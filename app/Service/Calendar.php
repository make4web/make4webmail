<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Database as DB;
use M4W\Core\Settings;
use Sabre\VObject;
use Sabre\VObject\Component\VCalendar;

/**
 * Calendars and their events. Personal calendars belong to one person, who may share them;
 * shared calendars have members (read or write); rooms can be booked by everyone.
 * Events are stored as iCalendar objects, exactly as CalDAV clients send them (phones,
 * Thunderbird, Outlook with a CalDAV add-in), with a few extracted columns for queries.
 */
final class Calendar
{
    public const NEVER = 2147483647;
    /** Invitations are e-mails: a meeting is not a mailing list. */
    public const MAX_ATTENDEES = 100;
    public const COLORS = ['#7c3aed', '#2563eb', '#0891b2', '#16a34a', '#ca8a04', '#ea580c', '#dc2626', '#db2777', '#475569'];
    public const LEVELS = ['read' => 1, 'write' => 2, 'owner' => 3];

    // ---------------------------------------------------------------- calendars

    /** The person's own calendar, created on first use. */
    public static function defaultFor(int $userId): array
    {
        $c = DB::one("SELECT * FROM calendars WHERE owner_id = :u AND kind = 'personal' AND deleted_at = 0 ORDER BY id LIMIT 1", ['u' => $userId]);
        if ($c) {
            return $c;
        }
        $id = DB::insert('calendars', ['owner_id' => $userId, 'kind' => 'personal', 'uri' => 'personal', 'name' => t('cal.my_calendar'), 'color' => self::COLORS[0],
            'description' => '', 'ctag' => 1, 'created_by' => $userId, 'created_at' => time(), 'deleted_at' => 0]);
        return DB::one('SELECT * FROM calendars WHERE id = :id', ['id' => $id]);
    }

    /** Calendars this person sees, with their access level. @return array<int,array> */
    public static function forUser(array $user): array
    {
        $uid = (int) $user['id'];
        self::defaultFor($uid);
        $out = [];
        foreach (DB::all('SELECT * FROM calendars WHERE deleted_at = 0 ORDER BY kind, name') as $c) {
            $lvl = self::level($user, $c);
            if ($lvl > 0) {
                $c['level'] = array_search($lvl, self::LEVELS, true);
                $c['mine'] = (int) $c['owner_id'] === $uid;
                $out[(int) $c['id']] = $c;
            }
        }
        return $out;
    }

    /** 0 none, 1 read, 2 write, 3 owner. Rooms: everyone sees them, managers write, administrators own. */
    public static function level(array $user, array $c): int
    {
        if ((int) $c['deleted_at'] > 0) {
            return 0;
        }
        $uid = (int) $user['id'];
        if ((int) $c['owner_id'] === $uid) {
            return 3;
        }
        if ($c['kind'] === 'room') {
            // Rooms are booked from an event (free and open slots only), not written directly.
            if (($user['role'] ?? '') === 'admin') {
                return 3;
            }
            return in_array($uid, Rooms::config($c)['managers'], true) ? 2 : 1;
        }
        $l = DB::value('SELECT level FROM calendar_acl WHERE calendar_id = :c AND user_id IN (:u, 0) ORDER BY user_id DESC LIMIT 1', ['c' => $c['id'], 'u' => $uid]);
        return $l ? (self::LEVELS[$l] ?? 0) : 0;
    }

    public static function find(int $id): ?array
    {
        return DB::one('SELECT * FROM calendars WHERE id = :id AND deleted_at = 0', ['id' => $id]);
    }

    /** @return array the calendar, if this person has at least $min there */
    public static function require(array $user, int $id, int $min): array
    {
        $c = self::find($id);
        if (!$c || self::level($user, $c) < $min) {
            throw new \InvalidArgumentException(t('cal.not_found'));
        }
        return $c;
    }

    public static function create(array $user, string $name, string $kind = 'personal', string $color = ''): int
    {
        $kind = in_array($kind, ['personal', 'shared', 'room'], true) ? $kind : 'personal';
        if ($kind === 'room' && ($user['role'] ?? '') !== 'admin') {
            throw new \InvalidArgumentException(t('cal.rooms_admin'));
        }
        $name = trim(mb_substr($name, 0, 190));
        if ($name === '') {
            throw new \InvalidArgumentException(t('cal.name_required'));
        }
        return DB::insert('calendars', ['owner_id' => $kind === 'room' ? null : (int) $user['id'], 'kind' => $kind, 'uri' => 'cal-' . bin2hex(random_bytes(6)),
            'name' => $name, 'color' => in_array($color, self::COLORS, true) ? $color : self::COLORS[array_rand(self::COLORS)], 'description' => '', 'ctag' => 1,
            'created_by' => (int) $user['id'], 'created_at' => time(), 'deleted_at' => 0]);
    }

    public static function update(array $user, int $id, array $d): void
    {
        $c = self::require($user, $id, 3);
        $set = [];
        if (isset($d['name']) && trim((string) $d['name']) !== '') {
            $set['name'] = mb_substr(trim((string) $d['name']), 0, 190);
        }
        if (isset($d['color']) && preg_match('/^#[0-9a-f]{6}$/i', (string) $d['color'])) {
            $set['color'] = strtolower((string) $d['color']);
        }
        if ($set) {
            DB::update('calendars', $set + ['ctag' => (int) $c['ctag'] + 1], 'id = :id', ['id' => $id]);
        }
    }

    public static function delete(array $user, int $id): void
    {
        $c = self::require($user, $id, 3);
        if ($c['kind'] === 'personal' && (int) self::defaultFor((int) $user['id'])['id'] === $id) {
            throw new \InvalidArgumentException(t('cal.keep_default'));
        }
        DB::update('calendars', ['deleted_at' => time()], 'id = :id', ['id' => $id]);
    }

    /** Members of a calendar: [{user_id (0 = everyone), level}] */
    public static function setAcl(array $user, int $id, array $entries): void
    {
        self::require($user, $id, 3);
        DB::run('DELETE FROM calendar_acl WHERE calendar_id = :c', ['c' => $id]);
        foreach ($entries as $e) {
            $u = (int) ($e['user_id'] ?? -1);
            $l = in_array($e['level'] ?? '', ['read', 'write'], true) ? $e['level'] : 'read';
            if ($u === 0 || ($u > 0 && $u !== (int) $user['id'] && Users::find($u))) {
                DB::run('DELETE FROM calendar_acl WHERE calendar_id = :c AND user_id = :u', ['c' => $id, 'u' => $u]);
                DB::insert('calendar_acl', ['calendar_id' => $id, 'user_id' => $u, 'level' => $l]);
            }
        }
        DB::run('UPDATE calendars SET ctag = ctag + 1 WHERE id = :id', ['id' => $id]);
    }

    public static function acl(int $id): array
    {
        return DB::all('SELECT a.user_id, a.level, u.display_name, u.email FROM calendar_acl a LEFT JOIN users u ON u.id = a.user_id WHERE a.calendar_id = :c ORDER BY a.user_id', ['c' => $id]);
    }

    // ---------------------------------------------------------------- objects

    public static function objects(int $calendarId): array
    {
        return DB::all('SELECT id, uri, uid, etag, size, component, updated_at FROM cal_objects WHERE calendar_id = :c ORDER BY id', ['c' => $calendarId]);
    }

    public static function object(int $calendarId, string $uri): ?array
    {
        return DB::one('SELECT * FROM cal_objects WHERE calendar_id = :c AND uri = :u', ['c' => $calendarId, 'u' => $uri]);
    }

    public static function objectByUid(int $calendarId, string $uid): ?array
    {
        return DB::one('SELECT * FROM cal_objects WHERE calendar_id = :c AND uid = :u', ['c' => $calendarId, 'u' => $uid]);
    }

    /**
     * Store an iCalendar object (validated). Returns the new ETag.
     * @param ?callable $afterSave fn(?VCalendar $old, VCalendar $new) — invitations
     */
    public static function put(int $calendarId, string $uri, string $ics): string
    {
        if (!preg_match('/^[\w.@+\-]{1,180}$/', $uri)) {
            throw new \InvalidArgumentException('uri');
        }
        if (strlen($ics) > 2 * 1048576) {
            throw new \InvalidArgumentException(t('cal.too_big'));
        }
        try {
            $cal = VObject\Reader::read($ics, VObject\Reader::OPTION_FORGIVING);
        } catch (\Throwable) {
            throw new \InvalidArgumentException(t('cal.invalid'));
        }
        if (!$cal instanceof VCalendar) {
            throw new \InvalidArgumentException(t('cal.invalid'));
        }
        $cal->validate(VObject\Node::REPAIR);
        $info = self::describe($cal);
        $data = $cal->serialize();
        $etag = md5($data);
        $now = time();
        $row = ['uid' => mb_substr($info['uid'], 0, 255), 'etag' => $etag, 'size' => strlen($data), 'data' => $data, 'component' => $info['component'],
            'summary' => mb_substr($info['summary'], 0, 255), 'first_occurrence' => $info['first'], 'last_occurrence' => $info['last'],
            'next_alarm' => self::nextAlarm($cal, $now), 'updated_at' => $now];
        $old = self::object($calendarId, $uri);
        if ($old) {
            DB::update('cal_objects', $row, 'id = :id', ['id' => $old['id']]);
        } else {
            DB::insert('cal_objects', $row + ['calendar_id' => $calendarId, 'uri' => $uri, 'created_at' => $now]);
        }
        DB::run('UPDATE calendars SET ctag = ctag + 1 WHERE id = :id', ['id' => $calendarId]);
        return $etag;
    }

    public static function remove(int $calendarId, string $uri): void
    {
        DB::run('DELETE FROM cal_objects WHERE calendar_id = :c AND uri = :u', ['c' => $calendarId, 'u' => $uri]);
        DB::run('UPDATE calendars SET ctag = ctag + 1 WHERE id = :id', ['id' => $calendarId]);
    }

    /** uid, component, summary, first and last instants (recurrences included). */
    public static function describe(VCalendar $cal): array
    {
        $comp = null;
        foreach ($cal->getComponents() as $c) {
            if ($c->name !== 'VTIMEZONE') {
                $comp = $c;
                break;
            }
        }
        if (!$comp) {
            throw new \InvalidArgumentException(t('cal.invalid'));
        }
        $first = 0;
        $last = 0;
        if ($comp->name === 'VEVENT' && isset($comp->DTSTART)) {
            $start = $comp->DTSTART->getDateTime();
            $first = $start->getTimestamp();
            $end = isset($comp->DTEND) ? $comp->DTEND->getDateTime() : (isset($comp->DURATION) ? $start->add(VObject\DateTimeParser::parseDuration((string) $comp->DURATION)) : $start->modify($comp->DTSTART->hasTime() ? '+0 sec' : '+1 day'));
            $last = $end->getTimestamp();
            if (isset($comp->RRULE)) {
                try {
                    $it = new VObject\Recur\EventIterator($cal, (string) $comp->UID);
                    if ($it->isInfinite()) {
                        $last = self::NEVER;
                    } else {
                        $n = 0;
                        while ($it->valid() && $n++ < 5000) {
                            $last = max($last, $it->getDtEnd()->getTimestamp());
                            $it->next();
                        }
                    }
                } catch (\Throwable) {
                    $last = self::NEVER;
                }
            }
        }
        return ['uid' => (string) ($comp->UID ?? bin2hex(random_bytes(8))), 'component' => $comp->name, 'summary' => (string) ($comp->SUMMARY ?? ''),
            'first' => $first, 'last' => min(self::NEVER, max($first, $last))];
    }

    /** Next reminder time (VALARM relative to the start), 0 if none ahead. */
    public static function nextAlarm(VCalendar $cal, int $after): int
    {
        $master = $cal->getBaseComponent('VEVENT');
        if (!$master || !isset($master->VALARM)) {
            return 0;
        }
        $offsets = [];
        foreach ($master->VALARM as $a) {
            $trig = $a->TRIGGER;
            if ($trig && (string) ($trig['VALUE'] ?? 'DURATION') !== 'DATE-TIME') {
                try {
                    $offsets[] = VObject\DateTimeParser::parseDuration((string) $trig);
                } catch (\Throwable) {
                }
            }
        }
        if (!$offsets) {
            return 0;
        }
        try {
            $it = new VObject\Recur\EventIterator($cal, (string) $master->UID);
            $it->fastForward(new \DateTimeImmutable('@' . ($after - 7 * 86400)));
            $best = 0;
            for ($i = 0; $i < 50 && $it->valid(); $i++, $it->next()) {
                foreach ($offsets as $off) {
                    $t = $it->getDtStart()->add($off)->getTimestamp();
                    if ($t > $after && ($best === 0 || $t < $best)) {
                        $best = $t;
                    }
                }
                if ($best && $it->getDtStart()->getTimestamp() > $best + 86400) {
                    break;
                }
            }
            return $best;
        } catch (\Throwable) {
            return 0;
        }
    }

    // ---------------------------------------------------------------- occurrences

    /**
     * Occurrences between two instants in these calendars, recurrences expanded.
     * @param array<int,array> $calendars id => calendar row
     */
    public static function range(array $calendars, int $from, int $to, string $myEmail = ''): array
    {
        if (!$calendars) {
            return [];
        }
        [$in, $p] = DB::in('c', array_keys($calendars));
        $rows = DB::all("SELECT calendar_id, uri, data FROM cal_objects WHERE component = 'VEVENT' AND calendar_id IN $in AND first_occurrence < :to AND last_occurrence >= :from",
            $p + ['to' => $to, 'from' => $from]);
        $tz = new \DateTimeZone(date_default_timezone_get());
        $out = [];
        foreach ($rows as $r) {
            try {
                $cal = VObject\Reader::read((string) $r['data'], VObject\Reader::OPTION_FORGIVING);
                $exp = $cal->expand(new \DateTimeImmutable('@' . $from), new \DateTimeImmutable('@' . $to), $tz);
            } catch (\Throwable) {
                continue;
            }
            foreach ($exp->VEVENT ?? [] as $ev) {
                $out[] = self::occurrence($ev, (int) $r['calendar_id'], (string) $r['uri'], $calendars[(int) $r['calendar_id']], $myEmail);
            }
        }
        usort($out, static fn($a, $b) => [$a['start'], $a['end']] <=> [$b['start'], $b['end']]);
        return $out;
    }

    private static function occurrence(VObject\Component\VEvent $ev, int $calId, string $uri, array $cal, string $me): array
    {
        $start = $ev->DTSTART->getDateTime();
        $allDay = !$ev->DTSTART->hasTime();
        $end = isset($ev->DTEND) ? $ev->DTEND->getDateTime() : (isset($ev->DURATION) ? $start->add(VObject\DateTimeParser::parseDuration((string) $ev->DURATION)) : $start->modify($allDay ? '+1 day' : '+0 sec'));
        $attendees = [];
        $mine = '';
        foreach ($ev->ATTENDEE ?? [] as $a) {
            $email = strtolower(preg_replace('/^mailto:/i', '', (string) $a->getValue()) ?? '');
            $ps = strtoupper((string) ($a['PARTSTAT'] ?? 'NEEDS-ACTION'));
            $attendees[] = ['email' => $email, 'name' => (string) ($a['CN'] ?? ''), 'partstat' => $ps, 'role' => (string) ($a['ROLE'] ?? '')];
            if ($me !== '' && $email === strtolower($me)) {
                $mine = $ps;
            }
        }
        $org = isset($ev->ORGANIZER) ? strtolower(preg_replace('/^mailto:/i', '', (string) $ev->ORGANIZER->getValue()) ?? '') : '';
        return [
            'calendar_id' => $calId, 'uri' => $uri, 'uid' => (string) $ev->UID,
            'recurrence_id' => isset($ev->{'RECURRENCE-ID'}) ? $ev->{'RECURRENCE-ID'}->getDateTime()->getTimestamp() : null,
            'recurring' => isset($ev->{'RECURRENCE-ID'}),
            'title' => (string) ($ev->SUMMARY ?? ''), 'location' => (string) ($ev->LOCATION ?? ''), 'description' => (string) ($ev->DESCRIPTION ?? ''),
            'start' => $start->getTimestamp(), 'end' => $end->getTimestamp(), 'all_day' => $allDay,
            'status' => strtoupper((string) ($ev->STATUS ?? 'CONFIRMED')), 'color' => $cal['color'], 'writable' => in_array($cal['level'] ?? 'read', ['write', 'owner'], true),
            'meeting' => (string) ($ev->{'X-M4W-MEETING-URL'} ?? ''), 'room_status' => strtolower((string) ($ev->{'X-M4W-ROOM-STATUS'} ?? '')),
            'organizer' => $org, 'organizer_name' => isset($ev->ORGANIZER['CN']) ? (string) $ev->ORGANIZER['CN'] : '', 'attendees' => $attendees, 'my_partstat' => $mine,
        ];
    }

    // ---------------------------------------------------------------- web form → iCalendar

    /** VTIMEZONE for a zone, from its real transitions (no external data). */
    public static function vtimezone(string $tzid, int $from, int $to): string
    {
        try {
            $tz = new \DateTimeZone($tzid);
        } catch (\Throwable) {
            return '';
        }
        $tr = $tz->getTransitions($from - 366 * 86400, $to + 366 * 86400);
        $out = "BEGIN:VTIMEZONE\r\nTZID:$tzid\r\n";
        $fmt = static fn(int $s) => ($s < 0 ? '-' : '+') . sprintf('%02d%02d', intdiv(abs($s), 3600), intdiv(abs($s) % 3600, 60));
        $prev = $tr[0]['offset'] ?? 0;
        if (count($tr) <= 1) {
            $out .= "BEGIN:STANDARD\r\nDTSTART:19700101T000000\r\nTZOFFSETFROM:" . $fmt($prev) . "\r\nTZOFFSETTO:" . $fmt($prev) . "\r\nTZNAME:" . ($tr[0]['abbr'] ?? 'UTC') . "\r\nEND:STANDARD\r\n";
        }
        foreach (array_slice($tr, 1) as $t) {
            $local = (new \DateTimeImmutable('@' . ($t['ts'] + $prev)))->format('Ymd\THis');
            $kind = $t['isdst'] ? 'DAYLIGHT' : 'STANDARD';
            $out .= "BEGIN:$kind\r\nDTSTART:$local\r\nTZOFFSETFROM:" . $fmt($prev) . "\r\nTZOFFSETTO:" . $fmt($t['offset']) . "\r\nTZNAME:{$t['abbr']}\r\nEND:$kind\r\n";
            $prev = $t['offset'];
        }
        return $out . "END:VTIMEZONE\r\n";
    }

    /**
     * New or changed event from the web form. $d: title, location, description, start, end
     * (local "Y-m-d H:i" or "Y-m-d" when all_day), all_day, repeat (none|daily|weekly|weekdays|
     * monthly|yearly), repeat_until (Y-m-d), reminder (minutes, -1 none), attendees [{email,name}],
     * status. With $scope 'one' and $occurrence, only that occurrence changes.
     */
    public static function fromForm(array $user, array $d, ?string $existing = null, string $scope = 'all', ?int $occurrence = null): VCalendar
    {
        $tzid = date_default_timezone_get();
        $tz = new \DateTimeZone($tzid);
        $allDay = !empty($d['all_day']);
        $parse = static function (string $v, bool $date) use ($tz): \DateTimeImmutable {
            $dt = \DateTimeImmutable::createFromFormat($date ? '!Y-m-d' : 'Y-m-d H:i', substr(trim($v), 0, $date ? 10 : 16), $tz);
            if (!$dt) {
                throw new \InvalidArgumentException(t('cal.bad_date'));
            }
            return $dt;
        };
        $start = $parse((string) ($d['start'] ?? ''), $allDay);
        $end = $parse((string) ($d['end'] ?? ''), $allDay);
        if ($allDay) {
            $end = $end->modify('+1 day'); // DTEND is exclusive for whole days
        }
        if ($end <= $start) {
            $end = $allDay ? $start->modify('+1 day') : $start->modify('+1 hour');
        }
        $title = trim(mb_substr((string) ($d['title'] ?? ''), 0, 250)) ?: t('cal.untitled');

        $cal = $existing ? VObject\Reader::read($existing, VObject\Reader::OPTION_FORGIVING) : new VCalendar(['PRODID' => '-//Make4WebMail//Calendar//FR']);
        $master = $existing ? $cal->getBaseComponent('VEVENT') : null;
        if ($existing && $scope === 'one' && $occurrence !== null && $master && isset($master->RRULE)) {
            // Only this occurrence: an exception carrying the RECURRENCE-ID of the original instant.
            $rid = (new \DateTimeImmutable('@' . $occurrence))->setTimezone($master->DTSTART->getDateTime()->getTimezone());
            $ev = null;
            foreach ($cal->VEVENT as $v) {
                if (isset($v->{'RECURRENCE-ID'}) && $v->{'RECURRENCE-ID'}->getDateTime()->getTimestamp() === $occurrence) {
                    $ev = $v;
                }
            }
            if (!$ev) {
                $ev = $cal->add('VEVENT', ['UID' => (string) $master->UID]);
                $ev->add('RECURRENCE-ID', $rid, $master->DTSTART->hasTime() ? [] : ['VALUE' => 'DATE']);
                foreach (['ORGANIZER', 'ATTENDEE', 'DESCRIPTION', 'LOCATION', 'VALARM'] as $copy) {
                    foreach ($master->select($copy) as $node) {
                        $ev->add(clone $node);
                    }
                }
            }
            self::fill($ev, $title, $d, $start, $end, $allDay, $user, false);
        } else {
            $ev = $master ?? $cal->add('VEVENT', ['UID' => bin2hex(random_bytes(12)) . '@' . (\M4W\Mail\Address::domain($user['email']) ?: 'm4w')]);
            self::fill($ev, $title, $d, $start, $end, $allDay, $user, true);
        }
        // Time zone definition for the clients.
        if (!$allDay && !isset($cal->VTIMEZONE)) {
            $vtz = self::vtimezone($tzid, $start->getTimestamp(), $end->getTimestamp());
            if ($vtz !== '' && $tzid !== 'UTC') {
                $cal->add(VObject\Reader::read("BEGIN:VCALENDAR\r\n" . $vtz . "END:VCALENDAR\r\n")->VTIMEZONE);
            }
        }
        return $cal;
    }

    private static function fill(VObject\Component\VEvent $ev, string $title, array $d, \DateTimeImmutable $start, \DateTimeImmutable $end, bool $allDay, array $user, bool $master): void
    {
        $timeChanged = !isset($ev->DTSTART) || $ev->DTSTART->getDateTime()->getTimestamp() !== $start->getTimestamp()
            || (isset($ev->DTEND) && $ev->DTEND->getDateTime()->getTimestamp() !== $end->getTimestamp());
        unset($ev->DTSTART, $ev->DTEND, $ev->DURATION);
        $ev->add('DTSTART', $start, $allDay ? ['VALUE' => 'DATE'] : []);
        $ev->add('DTEND', $end, $allDay ? ['VALUE' => 'DATE'] : []);
        $ev->SUMMARY = $title;
        foreach (['location' => 'LOCATION', 'description' => 'DESCRIPTION'] as $k => $prop) {
            $v = trim(mb_substr((string) ($d[$k] ?? ''), 0, $k === 'description' ? 20000 : 500));
            unset($ev->{$prop});
            if ($v !== '') {
                $ev->{$prop} = $v;
            }
        }
        $status = strtoupper((string) ($d['status'] ?? ''));
        if (in_array($status, ['CONFIRMED', 'TENTATIVE', 'CANCELLED'], true)) {
            $ev->STATUS = $status;
        }
        $ev->DTSTAMP = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $ev->{'LAST-MODIFIED'} = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $ev->SEQUENCE = (int) (string) ($ev->SEQUENCE ?? 0) + ($timeChanged && isset($ev->ORGANIZER) ? 1 : 0);
        if ($master && array_key_exists('repeat', $d)) {
            unset($ev->RRULE);
            $until = isset($d['repeat_until']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d['repeat_until'])
                ? ';UNTIL=' . (new \DateTimeImmutable($d['repeat_until'] . ' 23:59:59', new \DateTimeZone(date_default_timezone_get())))->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z') : '';
            $rule = match ((string) $d['repeat']) {
                'daily' => 'FREQ=DAILY',
                'weekdays' => 'FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR',
                'weekly' => 'FREQ=WEEKLY',
                'biweekly' => 'FREQ=WEEKLY;INTERVAL=2',
                'monthly' => 'FREQ=MONTHLY',
                'yearly' => 'FREQ=YEARLY',
                default => '',
            };
            if ($rule !== '') {
                $ev->add('RRULE', $rule . $until);
            }
        }
        if (array_key_exists('reminder', $d)) {
            unset($ev->VALARM);
            $min = (int) $d['reminder'];
            if ($min >= 0) {
                $ev->add('VALARM', ['ACTION' => 'DISPLAY', 'DESCRIPTION' => $title, 'TRIGGER' => '-PT' . $min . 'M']);
            }
        }
        if (array_key_exists('attendees', $d)) {
            $list = [];
            foreach ((array) $d['attendees'] as $a) {
                $email = strtolower(trim((string) ($a['email'] ?? '')));
                if (is_valid_email($email) && $email !== strtolower($user['email'])) {
                    $list[$email] = trim(mb_substr((string) ($a['name'] ?? ''), 0, 120));
                }
                if (count($list) > self::MAX_ATTENDEES) {
                    throw new \InvalidArgumentException(t('cal.too_many', ['n' => self::MAX_ATTENDEES]));
                }
            }
            $keep = [];
            foreach ($ev->ATTENDEE ?? [] as $at) {
                $keep[strtolower(preg_replace('/^mailto:/i', '', (string) $at->getValue()) ?? '')] = $at;
            }
            unset($ev->ATTENDEE);
            if ($list) {
                if (!isset($ev->ORGANIZER)) {
                    $ev->add('ORGANIZER', 'mailto:' . $user['email'], ['CN' => ($user['display_name'] ?? '') ?: $user['email']]);
                }
                $org = strtolower(preg_replace('/^mailto:/i', '', (string) $ev->ORGANIZER->getValue()) ?? '');
                $ev->add('ATTENDEE', 'mailto:' . $org, ['CN' => (string) ($ev->ORGANIZER['CN'] ?? $org), 'ROLE' => 'CHAIR', 'PARTSTAT' => 'ACCEPTED']);
                foreach ($list as $email => $name) {
                    if (isset($keep[$email])) {
                        $ev->add(clone $keep[$email]);
                    } else {
                        $ev->add('ATTENDEE', 'mailto:' . $email, array_filter(['CN' => $name, 'ROLE' => 'REQ-PARTICIPANT', 'PARTSTAT' => 'NEEDS-ACTION', 'RSVP' => 'TRUE']));
                    }
                }
            } else {
                unset($ev->ORGANIZER);
            }
        }
    }

    /** Delete one occurrence (EXDATE) of a recurring event. */
    public static function withoutOccurrence(string $ics, int $occurrence): VCalendar
    {
        $cal = VObject\Reader::read($ics, VObject\Reader::OPTION_FORGIVING);
        $master = $cal->getBaseComponent('VEVENT');
        if (!$master) {
            throw new \InvalidArgumentException(t('cal.invalid'));
        }
        $dt = (new \DateTimeImmutable('@' . $occurrence))->setTimezone($master->DTSTART->getDateTime()->getTimezone());
        $master->add('EXDATE', $dt, $master->DTSTART->hasTime() ? [] : ['VALUE' => 'DATE']);
        foreach ($cal->VEVENT as $v) {
            if (isset($v->{'RECURRENCE-ID'}) && $v->{'RECURRENCE-ID'}->getDateTime()->getTimestamp() === $occurrence) {
                $cal->remove($v);
            }
        }
        return $cal;
    }

    /** Busy periods of internal people (free/busy for scheduling, no details). */
    public static function busy(array $userIds, int $from, int $to): array
    {
        $out = [];
        foreach ($userIds as $uid) {
            $u = Users::find((int) $uid);
            if (!$u) {
                continue;
            }
            $cals = [];
            foreach (DB::all("SELECT * FROM calendars WHERE owner_id = :u AND kind = 'personal' AND deleted_at = 0", ['u' => $uid]) as $c) {
                $cals[(int) $c['id']] = $c + ['level' => 'owner'];
            }
            $out[(int) $uid] = array_values(array_map(static fn($o) => ['start' => $o['start'], 'end' => $o['end'], 'uid' => $o['uid']],
                array_filter(self::range($cals, $from, $to, (string) $u['email']), static fn($o) => $o['status'] !== 'CANCELLED' && $o['my_partstat'] !== 'DECLINED' && !$o['all_day'])));
        }
        return $out;
    }

    /** "mardi 3 mars 2026, 14:00 – 15:30" in the reader's language. */
    public static function when(int $start, int $end, bool $allDay): string
    {
        $lang = \M4W\Core\I18n::language();
        $day = static function (int $ts) use ($lang): string {
            if (class_exists(\IntlDateFormatter::class)) {
                $f = new \IntlDateFormatter($lang === 'en' ? 'en_GB' : 'fr_FR', \IntlDateFormatter::FULL, \IntlDateFormatter::NONE, date_default_timezone_get());
                return (string) $f->format($ts);
            }
            return date($lang === 'en' ? 'l j F Y' : 'd/m/Y', $ts);
        };
        if ($allDay) {
            $last = $end - 86400;
            return $day($start) . ($last > $start ? ' – ' . $day($last) : '');
        }
        $same = date('Ymd', $start) === date('Ymd', $end);
        return $day($start) . ', ' . date('H:i', $start) . ' – ' . ($same ? '' : $day($end) . ', ') . date('H:i', $end);
    }

    /**
     * Reminders due now: a notification on the person's devices (web push), then the next
     * reminder of the series is scheduled. Run every minute by cron. Returns the number sent.
     */
    public static function processAlarms(?int $now = null): int
    {
        $now ??= time();
        $sent = 0;
        $rows = DB::all("SELECT o.id, o.data, o.calendar_id, c.owner_id, c.kind FROM cal_objects o JOIN calendars c ON c.id = o.calendar_id
            WHERE o.next_alarm > 0 AND o.next_alarm <= :n AND c.deleted_at = 0 ORDER BY o.next_alarm LIMIT 500", ['n' => $now]);
        foreach ($rows as $r) {
            try {
                $cal = VObject\Reader::read((string) $r['data'], VObject\Reader::OPTION_FORGIVING);
            } catch (\Throwable) {
                DB::update('cal_objects', ['next_alarm' => 0], 'id = :id', ['id' => $r['id']]);
                continue;
            }
            $due = (int) DB::value('SELECT next_alarm FROM cal_objects WHERE id = :id', ['id' => $r['id']]);
            // Only the owner of a personal calendar is reminded (not every reader of a shared one).
            $user = $r['kind'] === 'personal' && $r['owner_id'] ? Users::find((int) $r['owner_id']) : null;
            if ($user && $due > $now - 3600) {
                $occ = self::range([(int) $r['calendar_id'] => ['color' => '', 'level' => 'owner']], $due - 60, $due + 8 * 86400, (string) $user['email']);
                $occ = array_values(array_filter($occ, static fn($o) => $o['start'] >= $due - 60 && $o['status'] !== 'CANCELLED' && $o['my_partstat'] !== 'DECLINED'
                    && (string) $o['uid'] === (string) ($cal->VEVENT->UID ?? '')));
                if ($occ) {
                    $o = $occ[0];
                    \M4W\Core\I18n::setLanguage($user['language'] ?? 'fr');
                    WebPush::send((int) $user['id'], ['title' => $o['title'] ?: t('cal.untitled'),
                        'body' => self::when($o['start'], $o['end'], $o['all_day']) . ($o['location'] !== '' ? ' · ' . $o['location'] : ''),
                        'tag' => 'm4w-cal-' . $r['id'] . '-' . $o['start'], 'url' => 'calendar'], 3600);
                    $sent++;
                }
            }
            DB::update('cal_objects', ['next_alarm' => self::nextAlarm($cal, max($now, $due))], 'id = :id', ['id' => $r['id']]);
        }
        return $sent;
    }
}
