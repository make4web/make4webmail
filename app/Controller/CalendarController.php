<?php
declare(strict_types=1);

namespace M4W\Controller;

use M4W\Core\Audit;
use M4W\Core\Database as DB;
use M4W\Core\Response;
use M4W\Core\Session;
use M4W\Dav\AppPasswords;
use M4W\Service\Calendar;
use M4W\Service\Mailbox;
use M4W\Service\Meetings;
use M4W\Service\Rooms;
use M4W\Service\Scheduling;
use M4W\Service\Users;
use Sabre\VObject;

/** Calendar page, events, calendars and sharing, invitations answered from a message. */
final class CalendarController extends Controller
{
    public function index(): Response
    {
        $user = $this->user();
        $people = DB::all("SELECT id, email, display_name, first_name, last_name FROM users WHERE status = 'active' AND id <> :u ORDER BY display_name, email", ['u' => $user['id']]);
        return $this->view('calendar/index', ['user' => $user, 'calendars' => $this->calendars($user), 'people' => $people]);
    }

    private function calendars(array $user): array
    {
        $out = [];
        foreach (Calendar::forUser($user) as $id => $c) {
            $owner = $c['owner_id'] ? Users::find((int) $c['owner_id']) : null;
            $out[] = ['id' => $id, 'name' => $c['name'], 'color' => $c['color'], 'kind' => $c['kind'], 'level' => $c['level'], 'mine' => $c['mine'],
                'owner' => $owner && !$c['mine'] ? $owner['name'] : '', 'acl' => $c['level'] === 'owner' && $c['kind'] !== 'room' ? Calendar::acl($id) : []];
        }
        return $out;
    }

    public function events(): Response
    {
        $user = $this->user();
        Session::close();
        $from = $this->req->int('from');
        $to = $this->req->int('to');
        if ($from <= 0 || $to <= $from || $to - $from > 120 * 86400) {
            return $this->fail('range');
        }
        $all = Calendar::forUser($user);
        $want = array_filter(array_map('intval', explode(',', (string) ($this->req->query['cals'] ?? ''))));
        $cals = $want ? array_intersect_key($all, array_flip($want)) : $all;
        return $this->ok(['events' => Calendar::range($cals, $from, $to, $user['email'])]);
    }

    /** Everything the event form needs. */
    public function event(): Response
    {
        $user = $this->user();
        $c = Calendar::require($user, $this->req->int('calendar_id'), 1);
        $o = Calendar::object((int) $c['id'], (string) ($this->req->query['uri'] ?? ''));
        if (!$o) {
            return $this->fail(t('cal.not_found'), 404);
        }
        $cal = VObject\Reader::read((string) $o['data'], VObject\Reader::OPTION_FORGIVING);
        $master = $cal->getBaseComponent('VEVENT') ?? $cal->VEVENT;
        $occ = $this->req->int('occurrence');
        $ev = $master;
        foreach ($cal->VEVENT as $v) {
            if ($occ && isset($v->{'RECURRENCE-ID'}) && $v->{'RECURRENCE-ID'}->getDateTime()->getTimestamp() === $occ) {
                $ev = $v;
            }
        }
        $allDay = !$ev->DTSTART->hasTime();
        $tz = new \DateTimeZone(date_default_timezone_get());
        $start = $ev->DTSTART->getDateTime()->setTimezone($tz);
        $end = isset($ev->DTEND) ? $ev->DTEND->getDateTime()->setTimezone($tz) : $start->modify($allDay ? '+1 day' : '+1 hour');
        if ($occ && $ev === $master && isset($master->RRULE)) {
            // The chosen occurrence of a series.
            $len = $end->getTimestamp() - $start->getTimestamp();
            $start = (new \DateTimeImmutable('@' . $occ))->setTimezone($tz);
            $end = $start->modify('+' . $len . ' seconds');
        }
        if ($allDay) {
            $end = $end->modify('-1 day');
        }
        $rrule = (string) ($master->RRULE ?? '');
        $repeat = 'none';
        $until = '';
        if ($rrule !== '') {
            $parts = [];
            foreach (explode(';', $rrule) as $kv) {
                [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
                $parts[strtoupper($k)] = $v;
            }
            $repeat = match (true) {
                ($parts['FREQ'] ?? '') === 'DAILY' && !isset($parts['INTERVAL']) => 'daily',
                ($parts['FREQ'] ?? '') === 'WEEKLY' && ($parts['BYDAY'] ?? '') === 'MO,TU,WE,TH,FR' => 'weekdays',
                ($parts['FREQ'] ?? '') === 'WEEKLY' && ($parts['INTERVAL'] ?? '1') === '2' => 'biweekly',
                ($parts['FREQ'] ?? '') === 'WEEKLY' && !isset($parts['BYDAY']) && !isset($parts['INTERVAL']) => 'weekly',
                ($parts['FREQ'] ?? '') === 'MONTHLY' && !isset($parts['INTERVAL']) => 'monthly',
                ($parts['FREQ'] ?? '') === 'YEARLY' && !isset($parts['INTERVAL']) => 'yearly',
                default => 'custom',
            };
            if (isset($parts['UNTIL'])) {
                $until = (new \DateTimeImmutable($parts['UNTIL']))->setTimezone($tz)->format('Y-m-d');
            }
        }
        $reminder = -1;
        foreach ($ev->VALARM ?? [] as $a) {
            try {
                $d = VObject\DateTimeParser::parseDuration((string) $a->TRIGGER);
                $reminder = abs((int) (($d->d * 86400 + $d->h * 3600 + $d->i * 60) / 60));
            } catch (\Throwable) {
            }
        }
        $attendees = [];
        $org = isset($ev->ORGANIZER) ? mb_strtolower(preg_replace('/^mailto:/i', '', (string) $ev->ORGANIZER->getValue()) ?? '') : '';
        foreach ($ev->ATTENDEE ?? [] as $a) {
            $email = mb_strtolower(preg_replace('/^mailto:/i', '', (string) $a->getValue()) ?? '');
            if ($email !== $org) {
                $attendees[] = ['email' => $email, 'name' => (string) ($a['CN'] ?? ''), 'partstat' => strtoupper((string) ($a['PARTSTAT'] ?? 'NEEDS-ACTION'))];
            }
        }
        $mine = array_map(static fn($h) => substr($h, 7), Scheduling::hrefs($user));
        return $this->ok(['event' => [
            'calendar_id' => (int) $c['id'], 'uri' => $o['uri'], 'uid' => $o['uid'], 'title' => (string) ($ev->SUMMARY ?? ''),
            'location' => (string) ($ev->LOCATION ?? ''), 'description' => Meetings::userDescription($ev), 'all_day' => $allDay,
            'start' => $start->format($allDay ? 'Y-m-d' : 'Y-m-d H:i'), 'end' => $end->format($allDay ? 'Y-m-d' : 'Y-m-d H:i'),
            'repeat' => $repeat, 'repeat_until' => $until, 'recurring' => $rrule !== '', 'reminder' => $reminder, 'attendees' => $attendees,
            'status' => strtoupper((string) ($ev->STATUS ?? 'CONFIRMED')), 'organizer' => $org, 'organizer_name' => (string) ($ev->ORGANIZER['CN'] ?? ''),
            'i_organize' => $org === '' || in_array($org, $mine, true), 'my_partstat' => Scheduling::myPartstat($cal, $user),
            'writable' => Calendar::level($user, $c) >= 2,
            'meeting' => ($mt = Meetings::of($cal)) ? $mt['provider'] : '', 'meeting_url' => $mt['url'] ?? '',
            // For whoever opens it: their name already filled in; and the fixed connection details.
            'meeting_join' => $mt ? Meetings::joinUrl($mt, (string) ($user['name'] ?? ''), (string) $user['email'], (string) ($ev->SUMMARY ?? '')) : '',
            'meeting_details' => $mt ? array_slice(Meetings::details($mt), 1) : [], 'meeting_howto' => $mt ? Meetings::howTo($mt['provider']) : '',
            'room_id' => Rooms::roomOf($cal), 'room_status' => strtolower((string) ($master->{'X-M4W-ROOM-STATUS'} ?? '')),
            'room_name' => ($r = Rooms::find(Rooms::roomOf($cal))) ? $r['name'] : '',
        ]]);
    }

    public function save(): Response
    {
        $user = $this->user();
        $d = $this->req->json();
        try {
            $target = Calendar::require($user, (int) ($d['calendar_id'] ?? 0), 2);
            $uri = (string) ($d['uri'] ?? '');
            $old = null;
            $source = $target;
            if ($uri !== '') {
                $source = Calendar::require($user, (int) ($d['original_calendar_id'] ?? $target['id']), 2);
                $old = Calendar::object((int) $source['id'], $uri);
                if (!$old) {
                    return $this->fail(t('cal.not_found'), 404);
                }
            }
            $scope = ($d['scope'] ?? 'all') === 'one' ? 'one' : 'all';
            $vcal = Calendar::fromForm($user, $d, $old ? (string) $old['data'] : null, $scope, isset($d['occurrence']) ? (int) $d['occurrence'] : null);
            if ($uri === '') {
                $uri = Scheduling::uriFor((string) $vcal->VEVENT->UID);
                if (Calendar::object((int) $target['id'], $uri)) {
                    $uri = bin2hex(random_bytes(12)) . '.ics';
                }
            }
            $this->conflictCheck($vcal, $target);
            $roomStatus = $this->rooms($user, $old ? (string) $old['data'] : null, $vcal, (int) $target['id'], $uri, array_key_exists('room_id', $d) ? (int) $d['room_id'] : null);
            $this->meeting($user, $old ? (string) $old['data'] : null, $vcal, array_key_exists('meeting', $d) ? (string) $d['meeting'] : null);
            if ((int) $source['id'] !== (int) $target['id']) {
                Calendar::remove((int) $source['id'], $uri);
            }
            Calendar::put((int) $target['id'], $uri, $vcal->serialize());
            $sent = Scheduling::afterChange($user, $old ? (string) $old['data'] : null, $vcal->serialize());
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->ok(['uri' => $uri, 'calendar_id' => (int) $target['id'], 'sent' => $sent, 'room_status' => $roomStatus]);
    }

    /** Rooms cannot be double-booked. */
    private function conflictCheck(VObject\Component\VCalendar $vcal, array $cal): void
    {
        if ($cal['kind'] !== 'room') {
            return;
        }
        $info = Calendar::describe($vcal);
        $to = min($info['last'], $info['first'] + 366 * 86400);
        $mine = (string) $vcal->VEVENT->UID;
        foreach (Calendar::range([(int) $cal['id'] => $cal + ['level' => 'write']], $info['first'], max($to, $info['first'] + 60)) as $o) {
            if ($o['uid'] === $mine || $o['status'] === 'CANCELLED') {
                continue;
            }
            $tmp = VObject\Reader::read($vcal->serialize());
            $exp = $tmp->expand(new \DateTimeImmutable('@' . $o['start']), new \DateTimeImmutable('@' . $o['end']));
            foreach ($exp->VEVENT ?? [] as $e) {
                $s = $e->DTSTART->getDateTime()->getTimestamp();
                $en = isset($e->DTEND) ? $e->DTEND->getDateTime()->getTimestamp() : $s;
                if ($s < $o['end'] && $en > $o['start']) {
                    throw new \InvalidArgumentException(t('cal.room_busy', ['title' => $o['title'], 'when' => Calendar::when($o['start'], $o['end'], $o['all_day'])]));
                }
            }
        }
    }

    /** Drag and drop in the grid: shift (and/or stretch) by a number of seconds. */
    public function move(): Response
    {
        $user = $this->user();
        $d = $this->req->json();
        try {
            $c = Calendar::require($user, (int) ($d['calendar_id'] ?? 0), 2);
            $o = Calendar::object((int) $c['id'], (string) ($d['uri'] ?? ''));
            if (!$o) {
                return $this->fail(t('cal.not_found'), 404);
            }
            $delta = (int) ($d['delta'] ?? 0);
            $stretch = (int) ($d['stretch'] ?? 0);
            $occ = isset($d['occurrence']) ? (int) $d['occurrence'] : null;
            $cal = VObject\Reader::read((string) $o['data'], VObject\Reader::OPTION_FORGIVING);
            $master = $cal->getBaseComponent('VEVENT');
            if (($d['scope'] ?? 'all') === 'one' && $occ !== null && $master && isset($master->RRULE)) {
                $occs = array_values(array_filter(Calendar::range([(int) $c['id'] => $c + ['level' => 'write']], $occ - 1, $occ + 1), static fn($x) => $x['uri'] === $o['uri'] && $x['start'] === $occ));
                if (!$occs) {
                    return $this->fail(t('cal.not_found'), 404);
                }
                $x = $occs[0];
                $fmt = $x['all_day'] ? 'Y-m-d' : 'Y-m-d H:i';
                $vcal = Calendar::fromForm($user, ['title' => $x['title'], 'location' => $x['location'], 'description' => $x['description'], 'all_day' => $x['all_day'],
                    'start' => date($fmt, $x['start'] + $delta), 'end' => date($fmt, $x['end'] + $delta + $stretch - ($x['all_day'] ? 86400 : 0))], (string) $o['data'], 'one', $occ);
            } else {
                foreach ($cal->VEVENT as $ev) {
                    if (isset($ev->{'RECURRENCE-ID'}) && $occ !== null && $ev->{'RECURRENCE-ID'}->getDateTime()->getTimestamp() !== $occ) {
                        continue;
                    }
                    if (isset($ev->{'RECURRENCE-ID'}) && $occ === null) {
                        continue;
                    }
                    $s = $ev->DTSTART->getDateTime();
                    $e = isset($ev->DTEND) ? $ev->DTEND->getDateTime() : $s;
                    $ev->DTSTART->setDateTime($s->modify(($delta >= 0 ? '+' : '') . $delta . ' seconds'));
                    if (isset($ev->DTEND)) {
                        $ev->DTEND->setDateTime($e->modify((($delta + $stretch) >= 0 ? '+' : '') . ($delta + $stretch) . ' seconds'));
                    }
                    if (isset($ev->ORGANIZER)) {
                        $ev->SEQUENCE = (int) (string) ($ev->SEQUENCE ?? 0) + 1;
                    }
                    $ev->DTSTAMP = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                    if ($occ === null || isset($ev->{'RECURRENCE-ID'})) {
                        break;
                    }
                }
                $vcal = $cal;
            }
            $this->conflictCheck($vcal, $c);
            $this->rooms($user, (string) $o['data'], $vcal, (int) $c['id'], (string) $o['uri']);
            $this->meeting($user, (string) $o['data'], $vcal, null);
            Calendar::put((int) $c['id'], (string) $o['uri'], $vcal->serialize());
            $sent = Scheduling::afterChange($user, (string) $o['data'], $vcal->serialize());
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->ok(['sent' => $sent]);
    }

    /**
     * The online meeting of an event changed by its organizer: created (Jitsi, Teams, Meet,
     * Zoom), kept and moved with the event, replaced, or cancelled.
     * $wanted: provider chosen in the form ('' none, null keep).
     */
    private function meeting(array $user, ?string $oldData, ?VObject\Component\VCalendar $new, ?string $wanted): void
    {
        $old = $oldData !== null ? VObject\Reader::read($oldData, VObject\Reader::OPTION_FORGIVING) : null;
        $ref = $new ?? $old;
        if (!$ref || !Rooms::organizedBy($user, $ref)) {
            return;
        }
        $cur = $old ? Meetings::of($old) : ($new ? Meetings::of($new) : null);
        $want = $new === null ? '' : ($wanted ?? ($cur['provider'] ?? ''));
        if ($cur && $cur['provider'] !== $want) {
            Meetings::delete($user, $cur);
        }
        if ($new === null) {
            return;
        }
        if ($want === '') {
            Meetings::detach($new);
            return;
        }
        $m = $new->getBaseComponent('VEVENT') ?? $new->VEVENT;
        $start = $m->DTSTART->getDateTime()->getTimestamp();
        $end = isset($m->DTEND) ? $m->DTEND->getDateTime()->getTimestamp() : $start + 3600;
        $title = (string) ($m->SUMMARY ?? '');
        if ($cur && $cur['provider'] === $want) {
            $om = $old ? ($old->getBaseComponent('VEVENT') ?? $old->VEVENT) : null;
            if ($om && ($om->DTSTART->getDateTime()->getTimestamp() !== $start || (string) ($om->SUMMARY ?? '') !== $title)) {
                Meetings::update($user, $cur, $title, $start, $end);
            }
            Meetings::attach($new, $cur);
            return;
        }
        Meetings::attach($new, Meetings::create($user, $want, $title, $start, $end));
    }

    /**
     * The room of an event changed by its organizer: booked (checked free and open), re-booked
     * after a change of time, or released. Attendees' copies never touch the room.
     * $wanted: the room chosen in the form (null: keep the current one, 0: none).
     */
    private function rooms(array $user, ?string $oldData, ?VObject\Component\VCalendar $new, int $calendarId, string $uri, ?int $wanted = null): string
    {
        $old = $oldData !== null ? VObject\Reader::read($oldData, VObject\Reader::OPTION_FORGIVING) : null;
        $ref = $new ?? $old;
        if (!$ref || !Rooms::organizedBy($user, $ref)) {
            return '';
        }
        $oldRoom = $old ? Rooms::roomOf($old) : 0;
        $uid = (string) (($ref->getBaseComponent('VEVENT') ?? $ref->VEVENT)->UID ?? '');
        $room = $new === null ? 0 : ($wanted ?? $oldRoom);
        $status = '';
        if ($new !== null && $room) {
            $status = Rooms::book($user, $room, $new, $calendarId, $uri);
        } elseif ($new !== null) {
            Rooms::detach($new);
        }
        if ($oldRoom && $oldRoom !== $room) {
            Rooms::release($oldRoom, $uid);
        }
        return $status;
    }

    public function delete(): Response
    {
        $user = $this->user();
        $d = $this->req->json();
        try {
            $c = Calendar::require($user, (int) ($d['calendar_id'] ?? 0), 2);
            $o = Calendar::object((int) $c['id'], (string) ($d['uri'] ?? ''));
            if (!$o) {
                return $this->ok();
            }
            if (($d['scope'] ?? 'all') === 'one' && isset($d['occurrence'])) {
                $vcal = Calendar::withoutOccurrence((string) $o['data'], (int) $d['occurrence']);
                try {
                    $this->rooms($user, (string) $o['data'], $vcal, (int) $c['id'], (string) $o['uri']);
                } catch (\InvalidArgumentException) {
                    // One occurrence fewer can only free the room.
                }
                Calendar::put((int) $c['id'], (string) $o['uri'], $vcal->serialize());
                $sent = Scheduling::afterChange($user, (string) $o['data'], $vcal->serialize());
            } else {
                $this->rooms($user, (string) $o['data'], null, (int) $c['id'], (string) $o['uri']);
                $this->meeting($user, (string) $o['data'], null, null);
                Calendar::remove((int) $c['id'], (string) $o['uri']);
                $sent = Scheduling::afterChange($user, (string) $o['data'], null);
            }
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->ok(['sent' => $sent]);
    }

    /** Answer an invitation shown in the calendar (event already there, from a colleague). */
    public function partstat(): Response
    {
        $user = $this->user();
        $d = $this->req->json();
        $ps = strtoupper((string) ($d['partstat'] ?? ''));
        if (!in_array($ps, ['ACCEPTED', 'TENTATIVE', 'DECLINED'], true)) {
            return $this->fail('partstat');
        }
        try {
            $c = Calendar::require($user, (int) ($d['calendar_id'] ?? 0), 2);
            $o = Calendar::object((int) $c['id'], (string) ($d['uri'] ?? ''));
            if (!$o) {
                return $this->fail(t('cal.not_found'), 404);
            }
            $cal = VObject\Reader::read((string) $o['data'], VObject\Reader::OPTION_FORGIVING);
            $mine = Scheduling::hrefs($user);
            $found = false;
            foreach ($cal->VEVENT as $ev) {
                foreach ($ev->ATTENDEE ?? [] as $a) {
                    if (in_array('mailto:' . mb_strtolower(preg_replace('/^mailto:/i', '', (string) $a->getValue()) ?? ''), $mine, true)) {
                        $a['PARTSTAT'] = $ps;
                        unset($a['RSVP']);
                        $found = true;
                    }
                }
            }
            if (!$found) {
                return $this->fail(t('cal.not_invited'));
            }
            Calendar::put((int) $c['id'], (string) $o['uri'], $cal->serialize());
            Scheduling::afterChange($user, (string) $o['data'], $cal->serialize());
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->ok();
    }

    /** Busy times of colleagues, to pick a slot (no details). */
    public function busy(): Response
    {
        $user = $this->user();
        Session::close();
        $from = $this->req->int('from');
        $to = $this->req->int('to');
        $ids = [];
        foreach (array_slice(array_filter(array_map('trim', explode(',', (string) ($this->req->query['emails'] ?? '')))), 0, 20) as $email) {
            $u = Users::resolveLocal($email);
            if ($u) {
                $ids[mb_strtolower($email)] = (int) $u['id'];
            }
        }
        if ($from <= 0 || $to <= $from || $to - $from > 14 * 86400) {
            return $this->fail('range');
        }
        $busy = Calendar::busy(array_values($ids), $from, $to);
        $out = [];
        foreach ($ids as $email => $id) {
            $out[$email] = $busy[$id] ?? [];
        }
        return $this->ok(['busy' => $out]);
    }

    // ---------------------------------------------------------------- rooms

    /** Rooms for the slot of the form: the free and open ones are offered. */
    public function roomsAvailable(): Response
    {
        $user = $this->user();
        Session::close();
        $q = $this->req->query;
        try {
            $vcal = Calendar::fromForm($user, ['title' => 'x', 'start' => (string) ($q['start'] ?? ''), 'end' => (string) ($q['end'] ?? ''), 'all_day' => !empty($q['all_day']),
                'repeat' => (string) ($q['repeat'] ?? 'none'), 'repeat_until' => (string) ($q['repeat_until'] ?? '')]);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        // The event's own booking does not count against it.
        return $this->ok(['rooms' => Rooms::available($vcal, (string) ($q['uid'] ?? ''))]);
    }

    /** Room bookings to approve (managers), and room settings (administrators). */
    public function roomsPage(): Response
    {
        $user = $this->user();
        $backlog = Rooms::backlog($user);
        if (!$backlog['rooms'] && !is_admin($user)) {
            return Response::redirect('/calendar');
        }
        $people = DB::all("SELECT id, email, display_name FROM users WHERE status = 'active' ORDER BY display_name, email");
        $rooms = [];
        foreach (Rooms::all() as $id => $r) {
            $rooms[$id] = $r + ['config' => Rooms::config($r), 'manage' => Rooms::canManage($user, $r)];
        }
        return $this->view('calendar/rooms', ['backlog' => $backlog, 'rooms' => $rooms, 'people' => $people, 'isAdmin' => is_admin($user),
            'calendars' => $this->calendars($user), 'user' => $user]);
    }

    public function decide(string $id): Response
    {
        try {
            Rooms::decide($this->user(), (int) $id, $this->req->str('decision') === 'approve', $this->req->str('comment'));
        } catch (\InvalidArgumentException $e) {
            return $this->back('/calendar/rooms', 'danger', $e->getMessage());
        }
        return $this->back('/calendar/rooms', 'success', t($this->req->str('decision') === 'approve' ? 'rooms.approved' : 'rooms.declined'));
    }

    public function roomConfig(string $id): Response
    {
        $r = $this->req;
        $hours = [];
        for ($d = 1; $d <= 7; $d++) {
            $hours[$d] = $r->bool('open_' . $d) ? [$r->str('from_' . $d), $r->str('to_' . $d)] : null;
        }
        try {
            Rooms::saveConfig($this->user(), (int) $id, ['managed' => $r->bool('managed'), 'managers' => $r->arr('managers'), 'capacity' => $r->int('capacity'),
                'details' => $r->str('details'), 'hours' => $hours]);
        } catch (\InvalidArgumentException $e) {
            return $this->back('/calendar/rooms', 'danger', $e->getMessage());
        }
        return $this->back('/calendar/rooms#room-' . (int) $id, 'success', t('settings.saved'));
    }

    // ---------------------------------------------------------------- calendars

    public function createCalendar(): Response
    {
        $user = $this->user();
        $d = $this->req->json();
        try {
            $id = Calendar::create($user, (string) ($d['name'] ?? ''), (string) ($d['kind'] ?? 'personal'), (string) ($d['color'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        Audit::log('cal.created', (string) ($d['name'] ?? ''), [], (int) $this->actor()['id']);
        return $this->ok(['id' => $id, 'calendars' => $this->calendars($user)]);
    }

    public function updateCalendar(string $id): Response
    {
        $user = $this->user();
        $d = $this->req->json();
        try {
            Calendar::update($user, (int) $id, $d);
            if (isset($d['acl']) && is_array($d['acl'])) {
                $c = Calendar::require($user, (int) $id, 3);
                if ($c['kind'] !== 'room') {
                    Calendar::setAcl($user, (int) $id, $d['acl']);
                    Audit::log('cal.shared', $c['name'], ['members' => count($d['acl'])], (int) $this->actor()['id']);
                }
            }
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->ok(['calendars' => $this->calendars($user)]);
    }

    public function deleteCalendar(string $id): Response
    {
        $user = $this->user();
        try {
            $c = Calendar::require($user, (int) $id, 3);
            Calendar::delete($user, (int) $id);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        Audit::log('cal.deleted', $c['name'], [], (int) $this->actor()['id']);
        return $this->ok(['calendars' => $this->calendars($user)]);
    }

    /** Import an .ics file into a calendar. */
    public function import(): Response
    {
        $user = $this->user();
        $f = $this->req->files['file'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || $f['size'] > 10 * 1048576) {
            return $this->fail(t('cal.import_failed'));
        }
        try {
            $c = Calendar::require($user, $this->req->int('calendar_id'), 2);
            $n = 0;
            $splitter = new VObject\Splitter\ICalendar(fopen($f['tmp_name'], 'rb'), VObject\Reader::OPTION_FORGIVING);
            while ($obj = $splitter->getNext()) {
                if ($n >= 5000) {
                    break;
                }
                unset($obj->METHOD);
                $info = Calendar::describe($obj);
                $uri = Scheduling::uriFor($info['uid']);
                Calendar::put((int) $c['id'], $uri, $obj->serialize());
                $n++;
            }
        } catch (\Throwable $e) {
            return $this->fail(t('cal.import_failed'));
        }
        return $this->ok(['count' => $n]);
    }

    /** Download a calendar as .ics. */
    public function export(string $id): Response
    {
        $user = $this->user();
        $c = Calendar::require($user, (int) $id, 1);
        $out = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Make4WebMail//Calendar//FR\r\nX-WR-CALNAME:" . str_replace(["\r", "\n", ',', ';'], ' ', $c['name']) . "\r\n";
        $tz = [];
        foreach (DB::all('SELECT data FROM cal_objects WHERE calendar_id = :c', ['c' => $c['id']]) as $r) {
            try {
                $v = VObject\Reader::read((string) $r['data'], VObject\Reader::OPTION_FORGIVING);
            } catch (\Throwable) {
                continue;
            }
            foreach ($v->children() as $child) {
                if ($child instanceof VObject\Component) {
                    if ($child->name === 'VTIMEZONE') {
                        $tz[(string) $child->TZID] = $child->serialize();
                    } else {
                        $out .= $child->serialize();
                    }
                }
            }
        }
        $out = str_replace("X-WR-CALNAME", implode('', $tz) . "X-WR-CALNAME", $out) . "END:VCALENDAR\r\n";
        $name = preg_replace('/[^\w\- ]/u', '', $c['name']) ?: 'agenda';
        return Response::download($out, 'text/calendar; charset=utf-8', $name . '.ics');
    }

    // ---------------------------------------------------------------- invitations in mail

    public function respond(): Response
    {
        $user = $this->user();
        $d = $this->req->json();
        $m = Mailbox::get((int) $user['id'], (int) ($d['message_id'] ?? 0));
        if (!$m) {
            return $this->fail(t('error.not_found'), 404);
        }
        try {
            $parsed = Mailbox::parsed($m);
            if (($d['action'] ?? '') === 'import') {
                Scheduling::import($user, $parsed);
            } else {
                Scheduling::respond($user, $parsed, (string) ($d['partstat'] ?? ''), trim((string) ($d['comment'] ?? '')));
            }
            $card = Scheduling::card($user, $parsed);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->ok(['invitation' => $card]);
    }

    // ---------------------------------------------------------------- phone sync

    public function sync(): Response
    {
        $user = $this->user();
        return $this->view('settings/sync', ['section' => 'sync', 'user' => $user, 'passwords' => AppPasswords::list((int) $user['id']), 'created' => self::once('app_pw'),
            'dav' => public_url('dav/'), 'host' => (string) parse_url(public_url('/'), PHP_URL_HOST)]);
    }

    private static function once(string $key): mixed
    {
        $v = Session::get($key);
        Session::forget($key);
        return $v;
    }

    public function saveMeetingLinks(): Response
    {
        $user = $this->user();
        $prefs = [];
        foreach (['teams', 'meet', 'zoom'] as $p) {
            $v = trim($this->req->str('meet_' . $p));
            if ($v !== '' && !Meetings::validPersonal($p, $v)) {
                return $this->back('/settings/sync#meetings', 'danger', t('meet.bad_personal', ['provider' => Meetings::LABELS[$p]]));
            }
            if (array_key_exists('meet_' . $p, $this->req->post)) {
                $prefs['meet_' . $p] = $v;
            }
        }
        Users::savePrefs((int) $user['id'], $prefs);
        return $this->back('/settings/sync#meetings', 'success', t('settings.saved'));
    }

    public function createPassword(): Response
    {
        $user = $this->user();
        if ((int) $this->actor()['id'] !== (int) $user['id']) {
            return $this->back('/settings/sync', 'danger', t('deleg.not_allowed'));
        }
        $pw = AppPasswords::create((int) $user['id'], $this->req->str('name'));
        Session::set('app_pw', ['name' => $this->req->str('name') ?: 'Appareil', 'password' => $pw]);
        Audit::log('dav.password_created', $this->req->str('name'), [], (int) $user['id']);
        return Response::redirect('/settings/sync');
    }

    public function revokePassword(): Response
    {
        $user = $this->user();
        AppPasswords::revoke((int) $user['id'], $this->req->int('id'));
        Audit::log('dav.password_revoked', (string) $this->req->int('id'), [], (int) $this->actor()['id']);
        return $this->back('/settings/sync', 'success', t('dav.revoked'));
    }

    /** Apple configuration profile: calendars and contacts set up in one tap (no password inside). */
    public function mobileconfig(): Response
    {
        $user = $this->user();
        $host = (string) parse_url(public_url('/'), PHP_URL_HOST);
        $port = (int) (parse_url(public_url('/'), PHP_URL_PORT) ?: 443);
        $path = rtrim((string) parse_url(public_url('dav/'), PHP_URL_PATH), '/') . '/';
        $x = static fn(string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $uuid = static fn() => strtoupper(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4)));
        $org = (string) \M4W\Core\Settings::get('brand.name', 'Make4WebMail');
        $payload = static fn(string $type, string $prefix, string $name) => '<dict><key>PayloadType</key><string>' . $type . '</string><key>PayloadVersion</key><integer>1</integer>'
            . '<key>PayloadIdentifier</key><string>' . $x('fr.make4web.' . $prefix . '.' . md5($host . $user['email'])) . '</string><key>PayloadUUID</key><string>' . $uuid() . '</string>'
            . '<key>PayloadDisplayName</key><string>' . $x($name) . '</string>'
            . '<key>' . $prefix . 'AccountDescription</key><string>' . $x($org) . '</string><key>' . $prefix . 'HostName</key><string>' . $x($host) . '</string>'
            . '<key>' . $prefix . 'Port</key><integer>' . $port . '</integer><key>' . $prefix . 'PrincipalURL</key><string>' . $x($path . 'principals/' . mb_strtolower($user['email']) . '/') . '</string>'
            . '<key>' . $prefix . 'UseSSL</key><true/><key>' . $prefix . 'Username</key><string>' . $x(mb_strtolower($user['email'])) . '</string></dict>';
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">' . "\n"
            . '<plist version="1.0"><dict><key>PayloadContent</key><array>' . $payload('com.apple.caldav.account', 'CalDAV', t('dav.calendars'))
            . $payload('com.apple.carddav.account', 'CardDAV', t('dav.contacts')) . '</array>'
            . '<key>PayloadDisplayName</key><string>' . $x($org . ' — ' . t('dav.profile')) . '</string><key>PayloadIdentifier</key><string>' . $x('fr.make4web.sync.' . md5($host . $user['email'])) . '</string>'
            . '<key>PayloadType</key><string>Configuration</string><key>PayloadUUID</key><string>' . $uuid() . '</string><key>PayloadVersion</key><integer>1</integer></dict></plist>';
        return Response::download($xml, 'application/x-apple-aspen-config', 'agenda-contacts.mobileconfig');
    }
}
