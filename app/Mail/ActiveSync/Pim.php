<?php
declare(strict_types=1);

namespace M4W\Mail\ActiveSync;

use M4W\Mail\ActiveSync\Wbxml as W;
use Sabre\VObject;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VCard;

/**
 * Calendar events and contacts between their stored form (iCalendar, vCard: what CalDAV and
 * CardDAV keep) and what an Exchange ActiveSync device reads and sends (MS-ASCAL, MS-ASCNTC).
 * What a device cannot show is kept: an edit from the phone changes its fields only.
 */
final class Pim
{
    private const DAYS = ['SU' => 1, 'MO' => 2, 'TU' => 4, 'WE' => 8, 'TH' => 16, 'FR' => 32, 'SA' => 64];

    // ------------------------------------------------------------------ time

    private static function utc(\DateTimeInterface $d): string
    {
        return (new \DateTimeImmutable('@' . $d->getTimestamp()))->format('Ymd\THis\Z');
    }

    private static function parse(string $s, string $tz): ?\DateTimeImmutable
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        // 20261110T090000Z, or 2026-11-10T09:00:00.000Z (contacts)
        $d = \DateTimeImmutable::createFromFormat('Ymd\THis\Z', $s, new \DateTimeZone('UTC'))
            ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.v\Z', $s, new \DateTimeZone('UTC'))
            ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $s, new \DateTimeZone('UTC'));
        return $d ? $d->setTimezone(new \DateTimeZone($tz)) : null;
    }

    /**
     * The device's TIME_ZONE_INFORMATION (MS-ASDTYPE 2.6.4): bias, the standard and daylight
     * names and when each starts (month, week of the month, day, hour), in base64.
     */
    public static function timezone(string $tz): string
    {
        $zone = new \DateTimeZone($tz);
        $year = (int) date('Y');
        $tr = array_values(array_filter($zone->getTransitions(mktime(0, 0, 0, 1, 1, $year), mktime(0, 0, 0, 12, 31, $year)), static fn($t, $i) => $i > 0, ARRAY_FILTER_USE_BOTH));
        $std = $dst = null;
        foreach ($tr as $t) {
            $t['isdst'] ? $dst = $t : $std = $t;
        }
        $stdOffset = $std ? (int) $std['offset'] : (int) $zone->getOffset(new \DateTime("$year-01-15"));
        $sys = static function (?array $t, int $offsetBefore): string {
            if (!$t) {
                return str_repeat("\0", 16);
            }
            $local = (new \DateTimeImmutable('@' . ($t['ts'] + $offsetBefore)));
            $day = (int) $local->format('j');
            $last = (int) $local->format('t');
            $week = $day + 7 > $last ? 5 : intdiv($day - 1, 7) + 1;
            return pack('v8', 0, (int) $local->format('n'), (int) $local->format('w'), $week, (int) $local->format('G'), (int) $local->format('i'), 0, 0);
        };
        $name = static fn(string $n) => substr(str_pad(mb_convert_encoding($n, 'UTF-16LE', 'UTF-8'), 64, "\0"), 0, 64);
        $dstOffset = $dst ? (int) $dst['offset'] : $stdOffset;
        $bin = pack('l', -intdiv($stdOffset, 60))
            . $name($tz) . $sys($std, $dstOffset) . pack('l', 0)
            . $name($tz) . $sys($dst, $stdOffset) . pack('l', $dst ? -intdiv($dstOffset - $stdOffset, 60) : 0);
        return base64_encode($bin);
    }

    // ------------------------------------------------------------------ events

    /** @return array ApplicationData children of an event */
    public static function eventNodes(string $ics, string $tz, string $myEmail): array
    {
        $cal = VObject\Reader::read($ics, VObject\Reader::OPTION_FORGIVING);
        $master = null;
        $overrides = [];
        foreach ($cal->select('VEVENT') as $ev) {
            isset($ev->{'RECURRENCE-ID'}) ? $overrides[] = $ev : $master ??= $ev;
        }
        if (!$master) {
            return [];
        }
        $out = [['Calendar:TimeZone', self::timezone($tz)]];
        $out = array_merge($out, self::eventFields($master, $tz, $myEmail, true));
        if (isset($master->RRULE)) {
            $out[] = self::recurrence($master, $tz);
            $ex = [];
            foreach ($master->select('EXDATE') as $exd) {
                foreach ($exd->getDateTimes() as $d) {
                    $ex[] = ['Calendar:Exception', [['Calendar:Deleted', '1'], ['Calendar:ExceptionStartTime', self::utc($d)]]];
                }
            }
            foreach ($overrides as $ov) {
                $ex[] = ['Calendar:Exception', array_merge([['Calendar:ExceptionStartTime', self::utc($ov->{'RECURRENCE-ID'}->getDateTime())]], self::eventFields($ov, $tz, $myEmail, false))];
            }
            if ($ex) {
                $out[] = ['Calendar:Exceptions', $ex];
            }
        }
        return $out;
    }

    private static function eventFields(VObject\Component $ev, string $tz, string $myEmail, bool $master): array
    {
        $allDay = isset($ev->DTSTART) && !$ev->DTSTART->hasTime();
        $local = new \DateTimeZone($tz);
        $start = isset($ev->DTSTART) ? $ev->DTSTART->getDateTime($local) : new \DateTimeImmutable();
        if (isset($ev->DTEND)) {
            $end = $ev->DTEND->getDateTime($local);
        } elseif (isset($ev->DURATION)) {
            $end = $start->add(VObject\DateTimeParser::parseDuration((string) $ev->DURATION));
        } else {
            $end = $allDay ? $start->modify('+1 day') : $start;
        }
        if ($allDay) {
            // Midnight where the device is, as Exchange does.
            $start = new \DateTimeImmutable($start->format('Y-m-d') . ' 00:00:00', $local);
            $end = new \DateTimeImmutable($end->format('Y-m-d') . ' 00:00:00', $local);
        }
        $out = [];
        if ($master) {
            $out[] = ['Calendar:DtStamp', self::utc(isset($ev->DTSTAMP) ? $ev->DTSTAMP->getDateTime() : new \DateTimeImmutable())];
        }
        $out[] = ['Calendar:StartTime', self::utc($start)];
        $out[] = ['Calendar:Subject', (string) ($ev->SUMMARY ?? '')];
        if ($master) {
            $out[] = ['Calendar:UID', (string) ($ev->UID ?? '')];
        }
        $out[] = ['Calendar:EndTime', self::utc($end)];
        if (isset($ev->LOCATION) && (string) $ev->LOCATION !== '') {
            $out[] = ['Calendar:Location', (string) $ev->LOCATION];
        }
        $desc = (string) ($ev->DESCRIPTION ?? '');
        $out[] = ['AirSyncBase:Body', [['AirSyncBase:Type', '1'], ['AirSyncBase:EstimatedDataSize', (string) strlen($desc)], ['AirSyncBase:Data', $desc]]];
        $out[] = ['Calendar:AllDayEvent', $allDay ? '1' : '0'];
        $out[] = ['Calendar:BusyStatus', strtoupper((string) ($ev->TRANSP ?? '')) === 'TRANSPARENT' ? '0' : (strtoupper((string) ($ev->STATUS ?? '')) === 'TENTATIVE' ? '1' : '2')];
        $out[] = ['Calendar:Sensitivity', match (strtoupper((string) ($ev->CLASS ?? ''))) { 'PRIVATE' => '2', 'CONFIDENTIAL' => '3', default => '0' }];
        foreach ($ev->select('VALARM') as $a) {
            $trig = (string) ($a->TRIGGER ?? '');
            if (preg_match('/^-?P/', $trig)) {
                $sec = -((new \DateTimeImmutable('@0'))->add(VObject\DateTimeParser::parseDuration($trig))->getTimestamp());
                $out[] = ['Calendar:Reminder', (string) max(0, intdiv($sec, 60))];
                break;
            }
        }
        if ($master) {
            $attendees = [];
            foreach ($ev->select('ATTENDEE') as $at) {
                $email = preg_replace('/^mailto:/i', '', (string) $at->getValue());
                if (!is_valid_email($email)) {
                    continue;
                }
                $status = match (strtoupper((string) ($at['PARTSTAT'] ?? ''))) { 'ACCEPTED' => '3', 'TENTATIVE' => '2', 'DECLINED' => '4', default => '5' };
                $attendees[] = ['Calendar:Attendee', [['Calendar:Email', $email], ['Calendar:Name', (string) ($at['CN'] ?? $email)],
                    ['Calendar:AttendeeStatus', $status], ['Calendar:AttendeeType', strtoupper((string) ($at['ROLE'] ?? '')) === 'OPT-PARTICIPANT' ? '2' : '1']]];
            }
            $org = isset($ev->ORGANIZER) ? preg_replace('/^mailto:/i', '', (string) $ev->ORGANIZER->getValue()) : '';
            if ($attendees) {
                $out[] = ['Calendar:Attendees', $attendees];
                $out[] = ['Calendar:OrganizerEmail', $org ?: $myEmail];
                $out[] = ['Calendar:OrganizerName', (string) ($ev->ORGANIZER['CN'] ?? ($org ?: $myEmail))];
                $out[] = ['Calendar:MeetingStatus', $org === '' || strcasecmp($org, $myEmail) === 0 ? '1' : '3'];
            } else {
                $out[] = ['Calendar:MeetingStatus', '0'];
            }
        }
        return $out;
    }

    private static function recurrence(VObject\Component $ev, string $tz): array
    {
        $r = $ev->RRULE->getParts();
        $start = $ev->DTSTART->getDateTime(new \DateTimeZone($tz));
        $freq = strtoupper((string) ($r['FREQ'] ?? 'DAILY'));
        $byday = (array) ($r['BYDAY'] ?? []);
        $mask = 0;
        $nth = 0;
        foreach ($byday as $d) {
            if (preg_match('/^([+-]?\d)?(SU|MO|TU|WE|TH|FR|SA)$/', strtoupper((string) $d), $m)) {
                $mask |= self::DAYS[$m[2]];
                $nth = $m[1] !== '' ? (int) $m[1] : $nth;
            }
        }
        if (isset($r['BYSETPOS'])) {
            $nth = (int) (is_array($r['BYSETPOS']) ? $r['BYSETPOS'][0] : $r['BYSETPOS']);
        }
        $week = $nth < 0 ? 5 : max(1, min(5, $nth));
        $out = [];
        if ($freq === 'DAILY' && !$mask) {
            $out[] = ['Calendar:Type', '0'];
        } elseif ($freq === 'DAILY' || $freq === 'WEEKLY') {
            $out[] = ['Calendar:Type', '1'];
            $out[] = ['Calendar:DayOfWeek', (string) ($mask ?: 1 << (int) $start->format('w'))];
        } elseif ($freq === 'MONTHLY' && $mask) {
            $out[] = ['Calendar:Type', '3'];
            $out[] = ['Calendar:WeekOfMonth', (string) $week];
            $out[] = ['Calendar:DayOfWeek', (string) $mask];
        } elseif ($freq === 'MONTHLY') {
            $out[] = ['Calendar:Type', '2'];
            $out[] = ['Calendar:DayOfMonth', (string) (int) (is_array($r['BYMONTHDAY'] ?? null) ? $r['BYMONTHDAY'][0] : ($r['BYMONTHDAY'] ?? $start->format('j')))];
        } elseif ($mask) {
            $out[] = ['Calendar:Type', '6'];
            $out[] = ['Calendar:WeekOfMonth', (string) $week];
            $out[] = ['Calendar:DayOfWeek', (string) $mask];
            $out[] = ['Calendar:MonthOfYear', (string) (int) ($r['BYMONTH'] ?? $start->format('n'))];
        } else {
            $out[] = ['Calendar:Type', '5'];
            $out[] = ['Calendar:DayOfMonth', (string) (int) ($r['BYMONTHDAY'] ?? $start->format('j'))];
            $out[] = ['Calendar:MonthOfYear', (string) (int) ($r['BYMONTH'] ?? $start->format('n'))];
        }
        if (isset($r['UNTIL'])) {
            $out[] = ['Calendar:Until', self::utc(VObject\DateTimeParser::parse((string) $r['UNTIL'], new \DateTimeZone($tz)))];
        }
        if (isset($r['COUNT'])) {
            $out[] = ['Calendar:Occurrences', (string) (int) $r['COUNT']];
        }
        $out[] = ['Calendar:Interval', (string) max(1, (int) ($r['INTERVAL'] ?? 1))];
        return ['Calendar:Recurrence', $out];
    }

    /** The event as the device sent it, written over the stored one (or a new one). */
    public static function eventIcs(array $ad, ?string $existing, string $tz): string
    {
        $cal = $existing ? VObject\Reader::read($existing, VObject\Reader::OPTION_FORGIVING) : new VCalendar(['PRODID' => '-//Make4WebMail//ActiveSync//FR']);
        $ev = null;
        foreach ($cal->select('VEVENT') as $e) {
            if (!isset($e->{'RECURRENCE-ID'})) {
                $ev = $e;
                break;
            }
        }
        if (!$ev) {
            $ev = $cal->add('VEVENT', ['UID' => W::text($ad, 'Calendar:UID') ?: bin2hex(random_bytes(16)) . '@m4w']);
        }
        self::applyFields($cal, $ev, $ad, $tz);
        // Recurrence and its exceptions: as the device says.
        if (W::child($ad, 'Calendar:Recurrence') || !$existing) {
            unset($ev->RRULE, $ev->EXDATE);
            foreach ($cal->select('VEVENT') as $e) {
                if (isset($e->{'RECURRENCE-ID'})) {
                    $cal->remove($e);
                }
            }
            if ($rec = W::child($ad, 'Calendar:Recurrence')) {
                $ev->RRULE = self::rrule($rec, $tz);
                foreach (W::children(W::child($ad, 'Calendar:Exceptions'), 'Calendar:Exception') as $x) {
                    $when = self::parse(W::text($x, 'Calendar:ExceptionStartTime'), $tz);
                    if (!$when) {
                        continue;
                    }
                    $allDay = !$ev->DTSTART->hasTime();
                    if (W::text($x, 'Calendar:Deleted') === '1') {
                        $allDay ? $ev->add('EXDATE', $when->format('Ymd'), ['VALUE' => 'DATE']) : $ev->add('EXDATE', $when->setTimezone(new \DateTimeZone('UTC')));
                    } else {
                        $ov = $cal->add('VEVENT', ['UID' => (string) $ev->UID]);
                        $allDay ? $ov->add('RECURRENCE-ID', $when->format('Ymd'), ['VALUE' => 'DATE']) : $ov->add('RECURRENCE-ID', $when->setTimezone(new \DateTimeZone('UTC')));
                        foreach (['SUMMARY', 'LOCATION', 'DESCRIPTION', 'DTSTART', 'DTEND', 'CLASS', 'TRANSP'] as $p) {
                            if (isset($ev->$p)) {
                                $ov->$p = clone $ev->$p;
                            }
                        }
                        self::applyFields($cal, $ov, $x, $tz, true);
                    }
                }
            }
        }
        $ev->DTSTAMP = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $seq = (int) (string) ($ev->SEQUENCE ?? 0);
        $ev->SEQUENCE = $existing ? $seq + 1 : 0;
        return $cal->serialize();
    }

    private static function applyFields(VCalendar $cal, VObject\Component $ev, array $ad, string $tz, bool $exception = false): void
    {
        $has = static fn(string $tag) => W::child($ad, $tag) !== null;
        $allDay = $has('Calendar:AllDayEvent') ? W::text($ad, 'Calendar:AllDayEvent') === '1' : (isset($ev->DTSTART) && !$ev->DTSTART->hasTime());
        $start = self::parse(W::text($ad, 'Calendar:StartTime'), $tz);
        $end = self::parse(W::text($ad, 'Calendar:EndTime'), $tz);
        if ($start) {
            unset($ev->DTSTART, $ev->DURATION);
            $allDay ? $ev->add('DTSTART', $start->format('Ymd'), ['VALUE' => 'DATE']) : $ev->add('DTSTART', $start->setTimezone(new \DateTimeZone('UTC')));
        }
        if ($end) {
            unset($ev->DTEND, $ev->DURATION);
            if ($allDay) {
                $e = $end->format('Ymd') > ($start ?? $end)->format('Ymd') ? $end : ($start ?? $end)->modify('+1 day');
                $ev->add('DTEND', $e->format('Ymd'), ['VALUE' => 'DATE']);
            } else {
                $ev->add('DTEND', $end->setTimezone(new \DateTimeZone('UTC')));
            }
        }
        foreach (['Calendar:Subject' => 'SUMMARY', 'Calendar:Location' => 'LOCATION'] as $tag => $prop) {
            if ($has($tag)) {
                $v = W::text($ad, $tag);
                unset($ev->$prop);
                if ($v !== '') {
                    $ev->$prop = $v;
                }
            }
        }
        if ($b = W::child($ad, 'AirSyncBase:Body')) {
            $v = str_replace("\r\n", "\n", W::text($b, 'AirSyncBase:Data'));
            unset($ev->DESCRIPTION);
            if (trim($v) !== '') {
                $ev->DESCRIPTION = $v;
            }
        }
        if ($has('Calendar:Sensitivity')) {
            $ev->CLASS = match (W::text($ad, 'Calendar:Sensitivity')) { '2' => 'PRIVATE', '3' => 'CONFIDENTIAL', default => 'PUBLIC' };
        }
        if ($has('Calendar:BusyStatus')) {
            $ev->TRANSP = W::text($ad, 'Calendar:BusyStatus') === '0' ? 'TRANSPARENT' : 'OPAQUE';
        }
        if ($has('Calendar:Reminder') || !$exception) {
            foreach ($ev->select('VALARM') as $a) {
                $ev->remove($a);
            }
            $min = W::text($ad, 'Calendar:Reminder');
            if ($min !== '' && ctype_digit($min)) {
                $ev->add('VALARM', ['ACTION' => 'DISPLAY', 'DESCRIPTION' => (string) ($ev->SUMMARY ?? 'Rappel'), 'TRIGGER' => '-PT' . (int) $min . 'M']);
            }
        }
        if (!$exception && ($att = W::child($ad, 'Calendar:Attendees'))) {
            $old = [];
            foreach ($ev->select('ATTENDEE') as $a) {
                $old[strtolower(preg_replace('/^mailto:/i', '', (string) $a->getValue()))] = $a;
                $ev->remove($a);
            }
            foreach (W::children($att, 'Calendar:Attendee') as $a) {
                $email = strtolower(trim(W::text($a, 'Calendar:Email')));
                if (!is_valid_email($email)) {
                    continue;
                }
                if (isset($old[$email])) {
                    $ev->add($old[$email]); // keeps its answer
                    continue;
                }
                $ev->add('ATTENDEE', 'mailto:' . $email, ['CN' => W::text($a, 'Calendar:Name') ?: $email, 'PARTSTAT' => 'NEEDS-ACTION', 'RSVP' => 'TRUE',
                    'ROLE' => W::text($a, 'Calendar:AttendeeType') === '2' ? 'OPT-PARTICIPANT' : 'REQ-PARTICIPANT']);
            }
            $org = trim(W::text($ad, 'Calendar:OrganizerEmail'));
            if (!isset($ev->ORGANIZER) && is_valid_email($org)) {
                $ev->add('ORGANIZER', 'mailto:' . $org, ['CN' => W::text($ad, 'Calendar:OrganizerName') ?: $org]);
            }
        }
    }

    private static function rrule(array $rec, string $tz): string
    {
        $type = (int) W::text($rec, 'Calendar:Type', '0');
        $mask = (int) W::text($rec, 'Calendar:DayOfWeek', '0');
        $days = implode(',', array_keys(array_filter(self::DAYS, static fn($bit) => ($mask & $bit) !== 0)));
        $week = (int) W::text($rec, 'Calendar:WeekOfMonth', '1');
        $nth = $week >= 5 ? -1 : $week;
        $parts = match ($type) {
            1 => ['FREQ=WEEKLY'] + ($days !== '' ? [1 => 'BYDAY=' . $days] : []),
            2 => ['FREQ=MONTHLY', 'BYMONTHDAY=' . (int) W::text($rec, 'Calendar:DayOfMonth', '1')],
            3 => ['FREQ=MONTHLY', 'BYDAY=' . $days, 'BYSETPOS=' . $nth],
            5 => ['FREQ=YEARLY', 'BYMONTH=' . (int) W::text($rec, 'Calendar:MonthOfYear', '1'), 'BYMONTHDAY=' . (int) W::text($rec, 'Calendar:DayOfMonth', '1')],
            6 => ['FREQ=YEARLY', 'BYMONTH=' . (int) W::text($rec, 'Calendar:MonthOfYear', '1'), 'BYDAY=' . $days, 'BYSETPOS=' . $nth],
            default => ['FREQ=DAILY'] + ($days !== '' ? [1 => 'BYDAY=' . $days] : []),
        };
        $interval = (int) W::text($rec, 'Calendar:Interval', '1');
        if ($interval > 1) {
            $parts[] = 'INTERVAL=' . $interval;
        }
        if ($n = (int) W::text($rec, 'Calendar:Occurrences', '0')) {
            $parts[] = 'COUNT=' . $n;
        } elseif ($u = self::parse(W::text($rec, 'Calendar:Until'), $tz)) {
            $parts[] = 'UNTIL=' . $u->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        }
        return implode(';', $parts);
    }

    // ------------------------------------------------------------------ contacts

    /** The vCard fields an Exchange contact has. */
    private const TEL = ['CELL' => 'MobilePhoneNumber', 'WORK' => 'BusinessPhoneNumber', 'HOME' => 'HomePhoneNumber', 'FAX' => 'BusinessFaxNumber', 'PAGER' => 'PagerNumber'];
    private const ADR = ['WORK' => 'Business', 'HOME' => 'Home', 'OTHER' => 'Other'];

    /** @return array ApplicationData children of a contact */
    public static function contactNodes(string $vcard): array
    {
        $v = VObject\Reader::read($vcard, VObject\Reader::OPTION_FORGIVING);
        $n = isset($v->N) ? $v->N->getParts() : [];
        $out = [];
        $add = static function (string $tag, string $value) use (&$out): void {
            if (trim($value) !== '') {
                $out[] = [$tag, trim($value)];
            }
        };
        $fn = trim((string) ($v->FN ?? ''));
        $add('Contacts:FileAs', $fn);
        $add('Contacts:LastName', (string) ($n[0] ?? ''));
        $add('Contacts:FirstName', (string) ($n[1] ?? ''));
        if (($n[0] ?? '') === '' && ($n[1] ?? '') === '' && $fn !== '') {
            $add('Contacts:FirstName', $fn);
        }
        $add('Contacts:MiddleName', (string) ($n[2] ?? ''));
        $add('Contacts:Title', (string) ($n[3] ?? ''));
        $add('Contacts:Suffix', (string) ($n[4] ?? ''));
        $i = 1;
        foreach ($v->select('EMAIL') as $e) {
            if ($i <= 3 && is_valid_email(trim((string) $e))) {
                $add('Contacts:Email' . $i++ . 'Address', trim((string) $e));
            }
        }
        $used = [];
        foreach ($v->select('TEL') as $t) {
            $types = array_map('strtoupper', isset($t['TYPE']) ? (array) $t['TYPE']->getParts() : []);
            $tag = 'MobilePhoneNumber';
            foreach (self::TEL as $type => $name) {
                if (in_array($type, $types, true)) {
                    $tag = $name;
                    break;
                }
            }
            if (isset($used[$tag])) {
                $tag = ['BusinessPhoneNumber' => 'Business2PhoneNumber', 'HomePhoneNumber' => 'Home2PhoneNumber'][$tag] ?? 'CarPhoneNumber';
            }
            if (!isset($used[$tag])) {
                $used[$tag] = true;
                $add('Contacts:' . $tag, (string) $t);
            }
        }
        if (isset($v->ORG)) {
            $org = $v->ORG->getParts();
            $add('Contacts:CompanyName', (string) ($org[0] ?? ''));
            $add('Contacts:Department', (string) ($org[1] ?? ''));
        }
        $add('Contacts:JobTitle', (string) ($v->TITLE ?? ''));
        foreach ($v->select('ADR') as $a) {
            $types = array_map('strtoupper', isset($a['TYPE']) ? (array) $a['TYPE']->getParts() : []);
            $kind = in_array('WORK', $types, true) ? 'Business' : (in_array('HOME', $types, true) ? 'Home' : 'Other');
            $p = $a->getParts();
            $add('Contacts:' . $kind . 'AddressStreet', trim(($p[1] ?? '') . "\n" . ($p[2] ?? '')));
            $add('Contacts:' . $kind . 'AddressCity', (string) ($p[3] ?? ''));
            $add('Contacts:' . $kind . 'AddressState', (string) ($p[4] ?? ''));
            $add('Contacts:' . $kind . 'AddressPostalCode', (string) ($p[5] ?? ''));
            $add('Contacts:' . $kind . 'AddressCountry', (string) ($p[6] ?? ''));
        }
        if (isset($v->BDAY) && preg_match('/^(\d{4})-?(\d{2})-?(\d{2})/', (string) $v->BDAY, $m)) {
            $add('Contacts:Birthday', "$m[1]-$m[2]-$m[3]T00:00:00.000Z");
        }
        $add('Contacts:WebPage', (string) ($v->URL ?? ''));
        $add('Contacts2:NickName', (string) ($v->NICKNAME ?? ''));
        if (isset($v->PHOTO)) {
            $raw = (string) $v->PHOTO->getValue();
            $bin = str_starts_with($raw, 'data:') ? (string) base64_decode(substr($raw, strpos($raw, ',') + 1)) : $raw;
            if ($bin !== '' && strlen($bin) < 48000 && @getimagesizefromstring($bin)) {
                $add('Contacts:Picture', base64_encode($bin));
            }
        }
        $note = (string) ($v->NOTE ?? '');
        $out[] = ['AirSyncBase:Body', [['AirSyncBase:Type', '1'], ['AirSyncBase:EstimatedDataSize', (string) strlen($note)], ['AirSyncBase:Data', $note]]];
        return $out;
    }

    /** The contact as the device sent it, over the stored card (what the phone does not know is kept). */
    public static function contactCard(array $ad, ?string $existing): string
    {
        $v = $existing ? VObject\Reader::read($existing, VObject\Reader::OPTION_FORGIVING) : new VCard(['VERSION' => '3.0', 'UID' => bin2hex(random_bytes(16))]);
        $t = static fn(string $tag) => trim(W::text($ad, $tag));
        $v->N = [$t('Contacts:LastName'), $t('Contacts:FirstName'), $t('Contacts:MiddleName'), $t('Contacts:Title'), $t('Contacts:Suffix')];
        $fn = $t('Contacts:FileAs') ?: trim($t('Contacts:FirstName') . ' ' . $t('Contacts:LastName')) ?: $t('Contacts:CompanyName') ?: $t('Contacts:Email1Address');
        $v->FN = preg_replace('/^(.+), (.+)$/', '$2 $1', $fn) ?? $fn;
        unset($v->EMAIL, $v->TEL, $v->ADR, $v->ORG, $v->TITLE, $v->BDAY, $v->URL, $v->NICKNAME);
        foreach ([1, 2, 3] as $i) {
            $e = $t("Contacts:Email{$i}Address");
            if (preg_match('/<([^>]+)>/', $e, $m)) {
                $e = $m[1];
            }
            if (is_valid_email($e)) {
                $v->add('EMAIL', $e, ['TYPE' => 'INTERNET']);
            }
        }
        $tels = self::TEL + ['CAR' => 'CarPhoneNumber', 'WORK2' => 'Business2PhoneNumber', 'HOME2' => 'Home2PhoneNumber', 'HOMEFAX' => 'HomeFaxNumber'];
        foreach ($tels as $type => $tag) {
            if (($n = $t('Contacts:' . $tag)) !== '') {
                $v->add('TEL', $n, ['TYPE' => match ($type) { 'WORK2' => 'WORK', 'HOME2' => 'HOME', 'HOMEFAX' => ['HOME', 'FAX'], 'FAX' => ['WORK', 'FAX'], default => $type }]);
            }
        }
        if ($t('Contacts:CompanyName') !== '' || $t('Contacts:Department') !== '') {
            $v->ORG = [$t('Contacts:CompanyName'), $t('Contacts:Department')];
        }
        if ($t('Contacts:JobTitle') !== '') {
            $v->TITLE = $t('Contacts:JobTitle');
        }
        foreach (self::ADR as $type => $kind) {
            $p = [$t("Contacts:{$kind}AddressStreet"), $t("Contacts:{$kind}AddressCity"), $t("Contacts:{$kind}AddressState"), $t("Contacts:{$kind}AddressPostalCode"), $t("Contacts:{$kind}AddressCountry")];
            if (implode('', $p) !== '') {
                $v->add('ADR', ['', '', $p[0], $p[1], $p[2], $p[3], $p[4]], ['TYPE' => $type]);
            }
        }
        if (preg_match('/^(\d{4})-?(\d{2})-?(\d{2})/', $t('Contacts:Birthday'), $m)) {
            $v->BDAY = "$m[1]-$m[2]-$m[3]";
        }
        if ($t('Contacts:WebPage') !== '') {
            $v->URL = $t('Contacts:WebPage');
        }
        if ($t('Contacts2:NickName') !== '') {
            $v->NICKNAME = $t('Contacts2:NickName');
        }
        if (W::child($ad, 'Contacts:Picture')) {
            unset($v->PHOTO);
            $bin = base64_decode($t('Contacts:Picture'), true);
            if ($bin && strlen($bin) < 1048576 && @getimagesizefromstring($bin)) {
                $v->add('PHOTO', $bin, ['ENCODING' => 'b', 'TYPE' => 'JPEG']);
            }
        }
        if ($b = W::child($ad, 'AirSyncBase:Body')) {
            $note = str_replace("\r\n", "\n", W::text($b, 'AirSyncBase:Data'));
            unset($v->NOTE);
            if (trim($note) !== '') {
                $v->NOTE = $note;
            }
        }
        return $v->serialize();
    }
}
