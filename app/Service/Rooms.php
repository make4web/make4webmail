<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Audit;
use M4W\Core\Database as DB;
use Sabre\VObject;
use Sabre\VObject\Component\VCalendar;

/**
 * Meeting rooms (and other bookable places). Only administrators create and set them up:
 * weekly opening hours, capacity, and whether bookings need the approval of a manager.
 * People book a room from their event: only rooms free and open for every occurrence are
 * offered. A booking of a managed room waits in the managers' backlog; the requester is told
 * of the decision.
 */
final class Rooms
{
    /** How far ahead the occurrences of a repeating event are checked. */
    private const HORIZON = 366 * 86400;

    public static function defaults(): array
    {
        $hours = [];
        for ($d = 1; $d <= 7; $d++) {
            $hours[$d] = $d <= 5 ? ['08:00', '19:00'] : null;
        }
        return ['managed' => false, 'managers' => [], 'capacity' => 0, 'details' => '', 'hours' => $hours];
    }

    public static function config(array $room): array
    {
        $c = json_decode((string) ($room['room_config'] ?? ''), true);
        $c = is_array($c) ? array_replace(self::defaults(), $c) : self::defaults();
        $hours = [];
        for ($d = 1; $d <= 7; $d++) {
            $h = $c['hours'][$d] ?? $c['hours'][(string) $d] ?? null;
            $hours[$d] = is_array($h) && count($h) === 2 ? [(string) $h[0], (string) $h[1]] : null;
        }
        $c['hours'] = $hours;
        $c['managers'] = array_values(array_map('intval', (array) $c['managers']));
        return $c;
    }

    /** @return array<int,array> id => room */
    public static function all(): array
    {
        $out = [];
        foreach (DB::all("SELECT * FROM calendars WHERE kind = 'room' AND deleted_at = 0 ORDER BY name") as $r) {
            $out[(int) $r['id']] = $r;
        }
        return $out;
    }

    public static function find(int $id): ?array
    {
        return DB::one("SELECT * FROM calendars WHERE id = :id AND kind = 'room' AND deleted_at = 0", ['id' => $id]);
    }

    public static function canManage(array $user, array $room): bool
    {
        return ($user['role'] ?? '') === 'admin' || in_array((int) $user['id'], self::config($room)['managers'], true);
    }

    /** Rooms this person decides on. */
    public static function managedBy(array $user): array
    {
        return array_filter(self::all(), static fn($r) => self::canManage($user, $r));
    }

    public static function saveConfig(array $admin, int $roomId, array $d): void
    {
        if (($admin['role'] ?? '') !== 'admin' || !($room = self::find($roomId))) {
            throw new \InvalidArgumentException(t('rooms.admin_only'));
        }
        $time = static fn($v) => is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$|^24:00$/', $v) ? $v : null;
        $hours = [];
        for ($day = 1; $day <= 7; $day++) {
            $h = $d['hours'][$day] ?? $d['hours'][(string) $day] ?? null;
            $from = is_array($h) ? $time($h[0] ?? null) : null;
            $to = is_array($h) ? $time($h[1] ?? null) : null;
            $hours[$day] = $from && $to && $from < $to ? [$from, $to] : null;
        }
        $managers = [];
        foreach ((array) ($d['managers'] ?? []) as $m) {
            if (($u = Users::find((int) $m)) && $u['status'] === 'active') {
                $managers[] = (int) $u['id'];
            }
        }
        $cfg = ['managed' => !empty($d['managed']), 'managers' => array_values(array_unique($managers)),
            'capacity' => max(0, min(10000, (int) ($d['capacity'] ?? 0))), 'details' => mb_substr(trim((string) ($d['details'] ?? '')), 0, 500), 'hours' => $hours];
        DB::update('calendars', ['room_config' => json_encode($cfg)], 'id = :id', ['id' => $roomId]);
        Audit::log('rooms.configured', $room['name'], [], (int) $admin['id']);
    }

    // ---------------------------------------------------------------- availability

    /** Occurrences [start, end, allDay] of an event, over the next year at most. */
    public static function occurrences(VCalendar $cal): array
    {
        $master = $cal->getBaseComponent('VEVENT') ?? $cal->VEVENT;
        if (!$master || !isset($master->DTSTART)) {
            return [];
        }
        $from = $master->DTSTART->getDateTime()->getTimestamp();
        try {
            $exp = (clone $cal)->expand(new \DateTimeImmutable('@' . ($from - 86400)), new \DateTimeImmutable('@' . max($from + 86400, min($from + self::HORIZON, time() + self::HORIZON))),
                new \DateTimeZone(date_default_timezone_get()));
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($exp->VEVENT ?? [] as $ev) {
            $allDay = !$ev->DTSTART->hasTime();
            $s = $ev->DTSTART->getDateTime()->getTimestamp();
            $e = isset($ev->DTEND) ? $ev->DTEND->getDateTime()->getTimestamp() : $s + ($allDay ? 86400 : 3600);
            if (strtoupper((string) ($ev->STATUS ?? '')) !== 'CANCELLED') {
                $out[] = [$s, $e, $allDay];
            }
            if (count($out) >= 400) {
                break;
            }
        }
        return $out;
    }

    /** Is the room open for the whole of this occurrence (local time, weekly hours)? */
    public static function open(array $cfg, int $start, int $end, bool $allDay): bool
    {
        if ($allDay) {
            for ($t = $start; $t < $end; $t += 86400) {
                if ($cfg['hours'][(int) date('N', $t)] === null) {
                    return false;
                }
            }
            return true;
        }
        $h = $cfg['hours'][(int) date('N', $start)];
        if ($h === null) {
            return false;
        }
        $sameDay = date('Y-m-d', $start) === date('Y-m-d', $end) || ($h[1] === '24:00' && $end === strtotime(date('Y-m-d', $start) . ' +1 day'));
        $endTime = date('Y-m-d', $start) === date('Y-m-d', $end) ? date('H:i', $end) : '24:00';
        return $sameDay && date('H:i', $start) >= $h[0] && $endTime <= $h[1];
    }

    /**
     * Why the room cannot be booked for these occurrences, '' if it can.
     * @return array{0:string,1:string} [state: ok|closed|busy, human detail]
     */
    public static function check(array $room, array $occurrences, string $excludeUid = ''): array
    {
        if (!$occurrences) {
            return ['ok', ''];
        }
        $cfg = self::config($room);
        foreach ($occurrences as [$s, $e, $allDay]) {
            if (!self::open($cfg, $s, $e, $allDay)) {
                return ['closed', t('rooms.closed_at', ['when' => Calendar::when($s, $e, $allDay)])];
            }
        }
        $from = min(array_column($occurrences, 0));
        $to = max(array_column($occurrences, 1));
        $taken = Calendar::range([(int) $room['id'] => $room + ['level' => 'read']], $from, $to);
        foreach ($taken as $o) {
            if ($o['uid'] === $excludeUid || $o['status'] === 'CANCELLED') {
                continue;
            }
            foreach ($occurrences as [$s, $e]) {
                if ($s < $o['end'] && $e > $o['start']) {
                    return ['busy', t('rooms.busy_at', ['when' => Calendar::when($o['start'], $o['end'], $o['all_day'])])];
                }
            }
        }
        return ['ok', ''];
    }

    /** Rooms with their state for an event (the form offers the free and open ones). */
    public static function available(VCalendar $cal, string $excludeUid = ''): array
    {
        $occ = self::occurrences($cal);
        $out = [];
        foreach (self::all() as $id => $r) {
            $cfg = self::config($r);
            [$state, $why] = self::check($r, $occ, $excludeUid);
            $out[] = ['id' => $id, 'name' => $r['name'], 'color' => $r['color'], 'state' => $state, 'why' => $why, 'managed' => $cfg['managed'],
                'capacity' => $cfg['capacity'], 'details' => $cfg['details']];
        }
        return $out;
    }

    // ---------------------------------------------------------------- bookings

    /** Only the organizer's copy books the room (attendees get a copy of the event too). */
    public static function organizedBy(array $user, VCalendar $cal): bool
    {
        $m = $cal->getBaseComponent('VEVENT') ?? $cal->VEVENT;
        if (!$m || !isset($m->ORGANIZER)) {
            return true;
        }
        return in_array('mailto:' . mb_strtolower(preg_replace('/^mailto:/i', '', (string) $m->ORGANIZER->getValue()) ?? ''), Scheduling::hrefs($user), true);
    }

    public static function roomOf(VCalendar $cal): int
    {
        $m = $cal->getBaseComponent('VEVENT') ?? $cal->VEVENT;
        return $m && isset($m->{'X-M4W-ROOM'}) ? (int) (string) $m->{'X-M4W-ROOM'} : 0;
    }

    public static function booking(int $roomId, string $uid): ?array
    {
        return DB::one("SELECT * FROM room_bookings WHERE room_id = :r AND uid = :u AND status IN ('pending', 'approved') ORDER BY id DESC LIMIT 1", ['r' => $roomId, 'u' => $uid]);
    }

    /**
     * Book (or re-book after a change) the room of an event. The event is changed in place:
     * room id, booking state, location. Throws when the room is closed or taken.
     */
    public static function book(array $user, int $roomId, VCalendar $event, int $calendarId, string $uri): string
    {
        $room = self::find($roomId);
        if (!$room) {
            throw new \InvalidArgumentException(t('rooms.not_found'));
        }
        $master = $event->getBaseComponent('VEVENT') ?? $event->VEVENT;
        $uid = (string) $master->UID;
        $occ = self::occurrences($event);
        [$state, $why] = self::check($room, $occ, $uid);
        if ($state !== 'ok') {
            throw new \InvalidArgumentException(t('rooms.cannot_book', ['room' => $room['name']]) . ' ' . $why);
        }
        $cfg = self::config($room);
        $prev = self::booking($roomId, $uid);
        $span = [$occ ? min(array_column($occ, 0)) : 0, $occ ? max(array_column($occ, 1)) : 0];
        $moved = !$prev || (int) $prev['start_at'] !== $span[0] || (int) $prev['end_at'] !== $span[1] || (int) $prev['recurring'] !== (count($occ) > 1 ? 1 : 0);
        $needsApproval = $cfg['managed'] && !self::canManage($user, $room);
        $status = $needsApproval ? ($prev && $prev['status'] === 'approved' && !$moved ? 'approved' : 'pending') : 'approved';

        // The room's own calendar holds a copy (what people see when they look at the room).
        $copy = clone $event;
        foreach ($copy->VEVENT as $v) {
            unset($v->VALARM, $v->ATTENDEE, $v->{'X-M4W-ROOM'}, $v->{'X-M4W-ROOM-STATUS'});
            $v->STATUS = $status === 'approved' ? 'CONFIRMED' : 'TENTATIVE';
            $v->{'X-M4W-BOOKED-BY'} = $user['email'];
        }
        $roomUri = Scheduling::uriFor($uid);
        Calendar::put($roomId, $roomUri, $copy->serialize());

        $row = ['room_id' => $roomId, 'requester_id' => (int) $user['id'], 'calendar_id' => $calendarId, 'event_uri' => $uri, 'uid' => mb_substr($uid, 0, 255),
            'title' => mb_substr((string) ($master->SUMMARY ?? ''), 0, 255), 'start_at' => $span[0], 'end_at' => $span[1], 'recurring' => count($occ) > 1 ? 1 : 0,
            'status' => $status, 'updated_at' => time()];
        if ($prev) {
            DB::update('room_bookings', $row + ($status === 'pending' ? ['decided_by' => null, 'decided_at' => 0, 'comment' => ''] : []), 'id = :id', ['id' => $prev['id']]);
        } else {
            DB::insert('room_bookings', $row + ['created_at' => time()]);
        }
        // The event says which room, and where it stands.
        $prevName = $master->{'X-M4W-ROOM'} ? (self::find((int) (string) $master->{'X-M4W-ROOM'})['name'] ?? '') : '';
        foreach ($event->VEVENT as $v) {
            $v->{'X-M4W-ROOM'} = (string) $roomId;
            $v->{'X-M4W-ROOM-STATUS'} = strtoupper($status);
            $loc = trim((string) ($v->LOCATION ?? ''));
            if ($loc === '' || $loc === $prevName) {
                $v->LOCATION = $room['name'];
            }
        }
        if ($status === 'pending' && (!$prev || $prev['status'] !== 'pending' || $moved)) {
            self::tellManagers($room, $user, (string) ($master->SUMMARY ?? ''), $span, count($occ) > 1);
        }
        return $status;
    }

    /** The event no longer uses the room (deleted, moved to another room, room removed). */
    public static function release(int $roomId, string $uid): void
    {
        if ($roomId <= 0 || $uid === '') {
            return;
        }
        DB::run("UPDATE room_bookings SET status = 'cancelled', updated_at = :t WHERE room_id = :r AND uid = :u AND status IN ('pending', 'approved')", ['t' => time(), 'r' => $roomId, 'u' => $uid]);
        Calendar::remove($roomId, Scheduling::uriFor($uid));
    }

    /** Remove the room from an event (the caller stores the event). */
    public static function detach(VCalendar $event): void
    {
        foreach ($event->VEVENT as $v) {
            unset($v->{'X-M4W-ROOM'}, $v->{'X-M4W-ROOM-STATUS'});
        }
    }

    /** A manager approves or declines. */
    public static function decide(array $manager, int $bookingId, bool $approve, string $comment = ''): void
    {
        $b = DB::one('SELECT * FROM room_bookings WHERE id = :id', ['id' => $bookingId]);
        $room = $b ? self::find((int) $b['room_id']) : null;
        if (!$b || !$room || !self::canManage($manager, $room)) {
            throw new \InvalidArgumentException(t('rooms.not_found'));
        }
        if ($b['status'] !== 'pending' && !($b['status'] === 'approved' && !$approve)) {
            throw new \InvalidArgumentException(t('rooms.already_decided'));
        }
        $comment = mb_substr(trim($comment), 0, 500);
        $status = $approve ? 'approved' : 'declined';
        DB::update('room_bookings', ['status' => $status, 'decided_by' => (int) $manager['id'], 'decided_at' => time(), 'comment' => $comment, 'updated_at' => time()], 'id = :id', ['id' => $bookingId]);
        $roomUri = Scheduling::uriFor((string) $b['uid']);
        if ($approve) {
            $o = Calendar::object((int) $room['id'], $roomUri);
            if ($o) {
                $c = VObject\Reader::read((string) $o['data'], VObject\Reader::OPTION_FORGIVING);
                foreach ($c->VEVENT as $v) {
                    $v->STATUS = 'CONFIRMED';
                }
                Calendar::put((int) $room['id'], $roomUri, $c->serialize());
            }
        } else {
            Calendar::remove((int) $room['id'], $roomUri);
        }
        // The requester's event shows the decision.
        $ev = Calendar::object((int) $b['calendar_id'], (string) $b['event_uri']);
        if ($ev) {
            $c = VObject\Reader::read((string) $ev['data'], VObject\Reader::OPTION_FORGIVING);
            foreach ($c->VEVENT as $v) {
                if ($approve) {
                    $v->{'X-M4W-ROOM-STATUS'} = 'APPROVED';
                } else {
                    unset($v->{'X-M4W-ROOM'}, $v->{'X-M4W-ROOM-STATUS'});
                    if (trim((string) ($v->LOCATION ?? '')) === $room['name']) {
                        unset($v->LOCATION);
                    }
                }
            }
            Calendar::put((int) $b['calendar_id'], (string) $b['event_uri'], $c->serialize());
        }
        Audit::log($approve ? 'rooms.approved' : 'rooms.declined', $room['name'] . ' — ' . $b['title'], [], (int) $manager['id']);
        $requester = Users::find((int) $b['requester_id']);
        if ($requester) {
            $when = Calendar::when((int) $b['start_at'], (int) $b['end_at'], false) . ((int) $b['recurring'] ? ' (' . t('rooms.recurring') . ')' : '');
            AccountGuard::notifyUser($requester,
                t($approve ? 'rooms.mail_approved_subject' : 'rooms.mail_declined_subject', ['room' => $room['name'], 'title' => $b['title']]),
                t($approve ? 'rooms.mail_approved_body' : 'rooms.mail_declined_body', ['room' => $room['name'], 'title' => $b['title'], 'when' => $when, 'who' => $manager['name'] ?? $manager['email']])
                . ($comment !== '' ? "\n\n« " . $comment . ' »' : ''), 'calendar');
        }
    }

    private static function tellManagers(array $room, array $requester, string $title, array $span, bool $recurring): void
    {
        $cfg = self::config($room);
        $ids = $cfg['managers'] ?: array_map('intval', array_column(DB::all("SELECT id FROM users WHERE role = 'admin' AND status = 'active'"), 'id'));
        foreach (array_unique($ids) as $id) {
            $m = Users::find((int) $id);
            if ($m && $m['status'] === 'active') {
                AccountGuard::notifyUser($m, t('rooms.mail_request_subject', ['room' => $room['name']]),
                    t('rooms.mail_request_body', ['who' => $requester['name'] ?? $requester['email'], 'room' => $room['name'], 'title' => $title,
                        'when' => Calendar::when($span[0], $span[1], false) . ($recurring ? ' (' . t('rooms.recurring') . ')' : '')]), 'calendar/rooms');
            }
        }
    }

    /** The backlog of a manager: waiting requests, and the coming approved bookings. */
    public static function backlog(array $user): array
    {
        $rooms = self::managedBy($user);
        if (!$rooms) {
            return ['pending' => [], 'upcoming' => [], 'rooms' => []];
        }
        [$in, $p] = DB::in('r', array_keys($rooms));
        $q = static fn(string $where, string $order) => DB::all("SELECT b.*, u.email AS requester_email, u.display_name AS requester_name, c.name AS room_name
            FROM room_bookings b JOIN calendars c ON c.id = b.room_id LEFT JOIN users u ON u.id = b.requester_id WHERE b.room_id IN $in AND $where ORDER BY $order LIMIT 200", $p + ['t' => time()]);
        return ['pending' => $q("b.status = 'pending' AND b.end_at > :t", 'b.start_at'), 'upcoming' => $q("b.status = 'approved' AND b.end_at > :t", 'b.start_at'), 'rooms' => $rooms];
    }

    public static function pendingCount(array $user): int
    {
        $rooms = self::managedBy($user);
        if (!$rooms) {
            return 0;
        }
        [$in, $p] = DB::in('r', array_keys($rooms));
        return (int) DB::value("SELECT COUNT(*) FROM room_bookings WHERE room_id IN $in AND status = 'pending' AND end_at > :t", $p + ['t' => time()]);
    }
}
