<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Database as DB;
use M4W\Mail\Address;
use M4W\Mail\MimeBuilder;
use M4W\Mail\ParsedMessage;
use Sabre\VObject;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\ITip;

/**
 * Meeting invitations (iTIP over e-mail, RFC 6047): REQUEST, REPLY and CANCEL messages, the same
 * way whether the change came from the web, a phone (CalDAV) or an e-mail. People of this server
 * get the event straight in their calendar and an e-mail too; others get the e-mail with the
 * invitation, which Outlook, Gmail, Apple Mail and Thunderbird understand.
 */
final class Scheduling
{
    /** Our own addresses, as calendar user addresses. */
    public static function hrefs(array $user): array
    {
        $out = ['mailto:' . mb_strtolower($user['email'])];
        foreach (DB::all('SELECT address FROM aliases WHERE user_id = :u', ['u' => $user['id']]) as $a) {
            $out[] = 'mailto:' . mb_strtolower((string) $a['address']);
        }
        return $out;
    }

    /**
     * An event of $user changed (or was deleted when $new is null): tell the people concerned.
     * Returns the number of messages sent.
     */
    public static function afterChange(array $user, ?string $old, ?string $new): int
    {
        if ($old === null && $new === null) {
            return 0;
        }
        try {
            $messages = (new ITip\Broker())->parseEvent($new, self::hrefs($user), $old);
        } catch (\Throwable $e) {
            error_log('[m4w] itip: ' . $e->getMessage());
            return 0;
        }
        $n = 0;
        // Also from phones (CalDAV), where the web form's limit does not apply.
        $messages = array_slice($messages, 0, Calendar::MAX_ATTENDEES + 1);
        foreach ($messages as $m) {
            if (!$m->significantChange && $m->method === 'REQUEST') {
                continue; // e.g. only an alarm changed
            }
            $n += self::dispatch($user, $m) ? 1 : 0;
        }
        return $n;
    }

    /** One message: straight into the calendar for local people, and an e-mail for everyone. */
    private static function dispatch(array $user, ITip\Message $m): bool
    {
        $to = self::email((string) $m->recipient);
        if (!is_valid_email($to)) {
            return false;
        }
        $local = Users::resolveLocal($to);
        if ($local && (int) $local['id'] !== (int) $user['id']) {
            try {
                self::deliverLocal($local, $m);
            } catch (\Throwable $e) {
                error_log('[m4w] itip local: ' . $e->getMessage());
            }
        }
        self::mail($user, $m, $to, (string) $m->recipientName);
        return true;
    }

    /** Apply an iTIP message to a local person's calendar (new invitation, update, reply, cancel). */
    public static function deliverLocal(array $recipient, ITip\Message $m): ?array
    {
        [$calId, $uri, $existing] = self::locate((int) $recipient['id'], (string) $m->uid);
        if (!$existing && $m->method !== 'REQUEST') {
            return null; // a reply or cancellation for something we don't have
        }
        $broker = new ITip\Broker();
        $m->recipient = 'mailto:' . mb_strtolower($recipient['email']);
        $result = $broker->processMessage($m, $existing ? VObject\Reader::read((string) $existing['data'], VObject\Reader::OPTION_FORGIVING) : null);
        if (!$result instanceof VCalendar) {
            return null;
        }
        if (!$calId) {
            $calId = (int) Calendar::defaultFor((int) $recipient['id'])['id'];
            $uri = self::uriFor((string) $m->uid);
        }
        Calendar::put($calId, $uri, $result->serialize());
        return ['calendar_id' => $calId, 'uri' => $uri];
    }

    /** Where a person keeps this event (their own calendars). [calendarId|0, uri, row|null] */
    public static function locate(int $userId, string $uid): array
    {
        $row = DB::one("SELECT o.* FROM cal_objects o JOIN calendars c ON c.id = o.calendar_id WHERE c.owner_id = :u AND c.deleted_at = 0 AND o.uid = :uid ORDER BY o.id LIMIT 1",
            ['u' => $userId, 'uid' => $uid]);
        return $row ? [(int) $row['calendar_id'], (string) $row['uri'], $row] : [0, '', null];
    }

    public static function uriFor(string $uid): string
    {
        $u = preg_replace('/[^\w.@+\-]/', '', $uid) ?? '';
        return (strlen($u) >= 4 && strlen($u) <= 160 ? $u : bin2hex(random_bytes(12))) . '.ics';
    }

    private static function email(string $href): string
    {
        return mb_strtolower(trim(preg_replace('/^mailto:/i', '', $href) ?? ''));
    }

    /** The e-mail: readable text, plus the iCalendar part mail programs act on. */
    private static function mail(array $user, ITip\Message $m, string $to, string $toName): void
    {
        $cal = $m->message;
        $ev = $cal->VEVENT ?? null;
        if (!$ev) {
            return;
        }
        $title = (string) ($ev->SUMMARY ?? t('cal.untitled'));
        $start = $ev->DTSTART->getDateTime()->getTimestamp();
        $allDay = !$ev->DTSTART->hasTime();
        $end = isset($ev->DTEND) ? $ev->DTEND->getDateTime()->getTimestamp() : $start + ($allDay ? 86400 : 3600);
        $partstat = '';
        if ($m->method === 'REPLY') {
            foreach ($ev->ATTENDEE ?? [] as $a) {
                $partstat = strtoupper((string) ($a['PARTSTAT'] ?? ''));
            }
        }
        [$subjKey, $intro] = match ($m->method) {
            'CANCEL' => ['cal.mail.cancel_subject', 'cal.mail.cancel_intro'],
            'REPLY' => [match ($partstat) { 'ACCEPTED' => 'cal.mail.accepted_subject', 'DECLINED' => 'cal.mail.declined_subject', default => 'cal.mail.tentative_subject' }, 'cal.mail.reply_intro'],
            default => [(int) (string) ($ev->SEQUENCE ?? 0) > 0 ? 'cal.mail.update_subject' : 'cal.mail.invite_subject', 'cal.mail.invite_intro'],
        };
        $who = $user['name'] ?? $user['email'];
        $rows = [[t('cal.when'), Calendar::when($start, $end, $allDay)]];
        if (isset($ev->LOCATION) && (string) $ev->LOCATION !== '') {
            $rows[] = [t('cal.where'), (string) $ev->LOCATION];
        }
        if (isset($ev->ORGANIZER)) {
            $rows[] = [t('cal.organizer'), (string) ($ev->ORGANIZER['CN'] ?? '') ?: self::email((string) $ev->ORGANIZER->getValue())];
        }
        if ($m->method !== 'REPLY') {
            $names = [];
            foreach ($ev->ATTENDEE ?? [] as $a) {
                if (strtoupper((string) ($a['ROLE'] ?? '')) !== 'CHAIR') {
                    $names[] = (string) ($a['CN'] ?? '') ?: self::email((string) $a->getValue());
                }
            }
            if ($names) {
                $rows[] = [t('cal.attendees'), implode(', ', $names)];
            }
        }
        $html = '<p>' . e(t($intro, ['name' => $who, 'answer' => t('cal.partstat.' . strtolower($partstat ?: 'tentative'))])) . '</p>'
            . '<table style="border-collapse:collapse;margin:12px 0">' . '<tr><td colspan="2" style="font-size:17px;font-weight:bold;padding:0 0 8px">' . e($title) . '</td></tr>';
        foreach ($rows as [$k, $v]) {
            $html .= '<tr><td style="color:#6b7280;padding:3px 16px 3px 0;vertical-align:top">' . e($k) . '</td><td style="padding:3px 0">' . e($v) . '</td></tr>';
        }
        $html .= '</table>';
        // Online meeting: a box with a real button (the guest's name already filled in), the ID,
        // the code and the phone numbers, written by the application: nothing typed by hand.
        $meet = Meetings::of($cal);
        if ($m->method === 'REQUEST' && $meet && preg_match('#^https://#', $meet['url'])) {
            $label = Meetings::LABELS[$meet['provider']] ?? 'Visio';
            $join = Meetings::joinUrl($meet, $toName, $to, $title);
            $html .= '<table role="presentation" style="border-collapse:collapse;margin:16px 0;border:1px solid #e5e7eb;border-radius:8px;background:#f8fafc"><tr><td style="padding:14px 18px">'
                . '<div style="font-weight:bold;margin-bottom:10px">' . e(t('meet.box_title', ['provider' => $label])) . '</div>'
                . '<a href="' . e($join) . '" style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:10px 20px;border-radius:6px;font-weight:bold">'
                . e(t('cal.join_with', ['provider' => $label])) . '</a>'
                . '<table style="border-collapse:collapse;margin-top:12px;font-size:13px">';
            foreach (Meetings::details($meet) as [$k, $v]) {
                $html .= '<tr><td style="color:#6b7280;padding:2px 14px 2px 0;vertical-align:top;white-space:nowrap">' . e($k) . '</td><td style="padding:2px 0;font-family:Consolas,Menlo,monospace;word-break:break-all">' . e($v) . '</td></tr>';
            }
            $html .= '</table><div style="color:#6b7280;font-size:12px;margin-top:8px">' . e(Meetings::howTo($meet['provider'])) . '</div></td></tr></table>';
        }
        if ($m->method === 'REQUEST' && trim(Meetings::userDescription($ev)) !== '') {
            $html .= '<p style="white-space:pre-wrap">' . e(Meetings::userDescription($ev)) . '</p>';
        }
        if ($m->method === 'REQUEST') {
            $html .= '<p style="color:#6b7280;font-size:12px">' . e(t('cal.mail.howto')) . '</p>';
        }
        $b = new MimeBuilder();
        $b->from = ['email' => $user['email'], 'name' => $who];
        $b->to = [['email' => $to, 'name' => $toName]];
        $b->subject = t($subjKey, ['title' => $title, 'name' => $who]);
        $b->html = $html;
        $b->messageId = MimeBuilder::newMessageId(Address::domain($user['email']) ?: 'localhost');
        $b->calendar = ['method' => $m->method, 'ics' => $cal->serialize()];
        $b->extraHeaders['X-M4W-Invitation'] = $m->method;
        Transport::enqueue($b->build(), $user['email'], [$to], (int) $user['id'], 'invite');
    }

    // ---------------------------------------------------------------- received invitations

    /** The iCalendar part of a message, if any: [VCalendar, method]. */
    public static function fromMessage(ParsedMessage $msg): ?array
    {
        foreach ($msg->attachmentParts() as $p) {
            $name = strtolower($p->filename());
            if ($p->type !== 'text/calendar' && $p->type !== 'application/ics' && !str_ends_with($name, '.ics')) {
                continue;
            }
            $body = $p->decodedBody();
            if (strlen($body) > 1048576 || stripos($body, 'BEGIN:VCALENDAR') === false) {
                continue;
            }
            try {
                $cal = VObject\Reader::read($body, VObject\Reader::OPTION_FORGIVING);
            } catch (\Throwable) {
                continue;
            }
            $method = strtoupper((string) ($cal->METHOD ?? ''));
            if ($cal instanceof VCalendar && isset($cal->VEVENT) && in_array($method, ['REQUEST', 'REPLY', 'CANCEL', 'PUBLISH'], true)) {
                return [$cal, $method];
            }
        }
        return null;
    }

    /**
     * On delivery: replies to our invitations, and updates or cancellations of events already in
     * the calendar, are applied at once (sender checked against the organizer / attendee).
     * New invitations wait for the person to answer from the message.
     */
    public static function incoming(array $user, ParsedMessage $msg): void
    {
        $found = self::fromMessage($msg);
        if (!$found) {
            return;
        }
        [$cal, $method] = $found;
        $from = mb_strtolower($msg->from()['email'] ?? '');
        if ($from === '' || Users::resolveLocal($from)) {
            return; // our own people are handled directly, never through their e-mails
        }
        $ev = $cal->VEVENT;
        $uid = (string) ($ev->UID ?? '');
        [, , $existing] = self::locate((int) $user['id'], $uid);
        if ($uid === '' || !$existing) {
            return;
        }
        if ($method === 'REPLY') {
            $ok = false;
            foreach ($ev->ATTENDEE ?? [] as $a) {
                $ok = $ok || self::email((string) $a->getValue()) === $from;
            }
        } else {
            $ok = isset($ev->ORGANIZER) && self::email((string) $ev->ORGANIZER->getValue()) === $from;
        }
        if (!$ok || !in_array($method, ['REPLY', 'REQUEST', 'CANCEL'], true)) {
            return;
        }
        $m = new ITip\Message();
        $m->uid = $uid;
        $m->component = 'VEVENT';
        $m->method = $method;
        $m->sequence = (int) (string) ($ev->SEQUENCE ?? 0);
        $m->sender = 'mailto:' . $from;
        $m->recipient = 'mailto:' . mb_strtolower($user['email']);
        $m->message = $cal;
        try {
            if ($method === 'REQUEST') {
                // Keep our answer through an update that does not move the meeting.
                $old = VObject\Reader::read((string) $existing['data'], VObject\Reader::OPTION_FORGIVING);
                $mine = self::myPartstat($old, $user);
                self::deliverLocal($user, $m);
                if ($mine !== '' && $mine !== 'NEEDS-ACTION' && !self::moved($old, $cal)) {
                    self::setMyPartstat($user, $uid, $mine);
                }
            } else {
                self::deliverLocal($user, $m);
            }
        } catch (\Throwable $e) {
            error_log('[m4w] itip in: ' . $e->getMessage());
        }
    }

    private static function moved(VCalendar $a, VCalendar $b): bool
    {
        $s = static fn(VCalendar $c) => [isset($c->VEVENT->DTSTART) ? $c->VEVENT->DTSTART->getDateTime()->getTimestamp() : 0,
            isset($c->VEVENT->DTEND) ? $c->VEVENT->DTEND->getDateTime()->getTimestamp() : 0, (string) ($c->VEVENT->RRULE ?? '')];
        return $s($a) !== $s($b);
    }

    public static function myPartstat(VCalendar $cal, array $user): string
    {
        $mine = array_map(static fn($h) => substr($h, 7), self::hrefs($user));
        foreach ($cal->VEVENT ?? [] as $ev) {
            foreach ($ev->ATTENDEE ?? [] as $a) {
                if (in_array(self::email((string) $a->getValue()), $mine, true)) {
                    return strtoupper((string) ($a['PARTSTAT'] ?? 'NEEDS-ACTION'));
                }
            }
        }
        return '';
    }

    private static function setMyPartstat(array $user, string $uid, string $partstat): ?string
    {
        [$calId, $uri, $row] = self::locate((int) $user['id'], $uid);
        if (!$row) {
            return null;
        }
        $cal = VObject\Reader::read((string) $row['data'], VObject\Reader::OPTION_FORGIVING);
        $mine = array_map(static fn($h) => substr($h, 7), self::hrefs($user));
        foreach ($cal->VEVENT as $ev) {
            foreach ($ev->ATTENDEE ?? [] as $a) {
                if (in_array(self::email((string) $a->getValue()), $mine, true)) {
                    $a['PARTSTAT'] = $partstat;
                    unset($a['RSVP']);
                }
            }
        }
        $old = (string) $row['data'];
        Calendar::put($calId, $uri, $cal->serialize());
        return $old;
    }

    /** What the invitation card of a message shows. */
    public static function card(array $user, ParsedMessage $msg): ?array
    {
        $found = self::fromMessage($msg);
        if (!$found) {
            return null;
        }
        [$cal, $method] = $found;
        $ev = $cal->VEVENT;
        $start = isset($ev->DTSTART) ? $ev->DTSTART->getDateTime()->getTimestamp() : 0;
        $allDay = isset($ev->DTSTART) && !$ev->DTSTART->hasTime();
        $end = isset($ev->DTEND) ? $ev->DTEND->getDateTime()->getTimestamp() : $start + ($allDay ? 86400 : 3600);
        [, , $existing] = self::locate((int) $user['id'], (string) ($ev->UID ?? ''));
        $current = $existing ? VObject\Reader::read((string) $existing['data'], VObject\Reader::OPTION_FORGIVING) : null;
        $partstat = $current ? self::myPartstat($current, $user) : self::myPartstat($cal, $user);
        $replier = '';
        if ($method === 'REPLY') {
            foreach ($ev->ATTENDEE ?? [] as $a) {
                $replier = ((string) ($a['CN'] ?? '') ?: self::email((string) $a->getValue())) . '|' . strtoupper((string) ($a['PARTSTAT'] ?? ''));
            }
        }
        // Other events at the same time (own calendars only).
        $conflicts = [];
        if ($method === 'REQUEST' && $start > 0 && !$allDay) {
            $cals = [];
            foreach (Calendar::forUser($user) as $id => $c) {
                if ($c['mine']) {
                    $cals[$id] = $c;
                }
            }
            foreach (Calendar::range($cals, $start, $end, $user['email']) as $o) {
                if ($o['uid'] !== (string) $ev->UID && $o['start'] < $end && $o['end'] > $start && $o['status'] !== 'CANCELLED' && $o['my_partstat'] !== 'DECLINED') {
                    $conflicts[] = $o['title'] . ' (' . date('H:i', $o['start']) . '–' . date('H:i', $o['end']) . ')';
                }
            }
        }
        return [
            'method' => $method, 'uid' => (string) ($ev->UID ?? ''), 'title' => (string) ($ev->SUMMARY ?? t('cal.untitled')),
            'when' => $start ? Calendar::when($start, $end, $allDay) : '', 'start' => $start, 'location' => (string) ($ev->LOCATION ?? ''),
            'organizer' => isset($ev->ORGANIZER) ? ((string) ($ev->ORGANIZER['CN'] ?? '') ?: self::email((string) $ev->ORGANIZER->getValue())) : '',
            'recurring' => isset($ev->RRULE), 'in_calendar' => (bool) $existing, 'partstat' => $partstat, 'replier' => $replier,
            'cancelled' => $method === 'CANCEL' || ($current && strtoupper((string) ($current->VEVENT->STATUS ?? '')) === 'CANCELLED'),
            'outdated' => $current && (int) (string) ($current->VEVENT->SEQUENCE ?? 0) > (int) (string) ($ev->SEQUENCE ?? 0),
            'conflicts' => array_slice($conflicts, 0, 5), 'can_answer' => $method === 'REQUEST' && $partstat !== '',
            'join' => ($meet = Meetings::of($cal)) && preg_match('#^https://#', $meet['url']) ? Meetings::joinUrl($meet, (string) ($user['name'] ?? ''), (string) $user['email'], (string) ($ev->SUMMARY ?? ''))
                : (preg_match('#^https://#', $j = (string) ($ev->{'X-M4W-MEETING-URL'} ?? ($ev->CONFERENCE ?? ''))) ? $j : ''),
            'join_details' => $meet ? array_slice(Meetings::details($meet), 1) : [],
            'join_howto' => $meet ? Meetings::howTo($meet['provider']) : '',
            'join_label' => Meetings::LABELS[(string) ($ev->{'X-M4W-MEETING'} ?? '')] ?? '',
        ];
    }

    /**
     * The person answers an invitation from the message: the event goes into their calendar
     * with their answer, and the organizer gets the reply.
     */
    public static function respond(array $user, ParsedMessage $msg, string $partstat, string $comment = ''): void
    {
        $partstat = strtoupper($partstat);
        if (!in_array($partstat, ['ACCEPTED', 'TENTATIVE', 'DECLINED'], true)) {
            throw new \InvalidArgumentException('partstat');
        }
        $found = self::fromMessage($msg);
        if (!$found || $found[1] !== 'REQUEST') {
            throw new \InvalidArgumentException(t('cal.no_invitation'));
        }
        [$cal] = $found;
        $uid = (string) ($cal->VEVENT->UID ?? '');
        if ($uid === '' || self::myPartstat($cal, $user) === '') {
            throw new \InvalidArgumentException(t('cal.not_invited'));
        }
        [$calId, $uri, $existing] = self::locate((int) $user['id'], $uid);
        if (!$existing || (int) (string) ($cal->VEVENT->SEQUENCE ?? 0) >= (int) (string) (VObject\Reader::read((string) $existing['data'], VObject\Reader::OPTION_FORGIVING)->VEVENT->SEQUENCE ?? 0)) {
            // Store (or refresh) the copy from the invitation, without its METHOD.
            $copy = clone $cal;
            unset($copy->METHOD);
            if (!$calId) {
                $calId = (int) Calendar::defaultFor((int) $user['id'])['id'];
                $uri = self::uriFor($uid);
            }
            Calendar::put($calId, $uri, $copy->serialize());
        }
        $before = self::setMyPartstat($user, $uid, $partstat);
        [, , $row] = self::locate((int) $user['id'], $uid);
        if ($comment !== '' && $row) {
            $c = VObject\Reader::read((string) $row['data'], VObject\Reader::OPTION_FORGIVING);
            $c->VEVENT->COMMENT = mb_substr($comment, 0, 500);
            Calendar::put($calId, $uri, $c->serialize());
            [, , $row] = self::locate((int) $user['id'], $uid);
        }
        if ($before !== null && $row) {
            // The broker sees the attendee's answer change and builds the REPLY.
            $old = VObject\Reader::read($before, VObject\Reader::OPTION_FORGIVING);
            foreach ($old->VEVENT as $ev) {
                foreach ($ev->ATTENDEE ?? [] as $a) {
                    if (in_array('mailto:' . self::email((string) $a->getValue()), self::hrefs($user), true) && strtoupper((string) ($a['PARTSTAT'] ?? '')) === $partstat) {
                        $a['PARTSTAT'] = 'NEEDS-ACTION'; // answering again sends the answer again
                    }
                }
            }
            self::afterChange($user, $old->serialize(), (string) $row['data']);
        }
    }

    /** Add a published event (METHOD:PUBLISH, a plain .ics) to the calendar. */
    public static function import(array $user, ParsedMessage $msg): void
    {
        $found = self::fromMessage($msg);
        if (!$found) {
            throw new \InvalidArgumentException(t('cal.no_invitation'));
        }
        $cal = clone $found[0];
        unset($cal->METHOD);
        $uid = (string) ($cal->VEVENT->UID ?? bin2hex(random_bytes(8)));
        [$calId, $uri] = self::locate((int) $user['id'], $uid);
        if (!$calId) {
            $calId = (int) Calendar::defaultFor((int) $user['id'])['id'];
            $uri = self::uriFor($uid);
        }
        Calendar::put($calId, $uri, $cal->serialize());
    }
}
