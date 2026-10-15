<?php
declare(strict_types=1);

namespace M4W\Dav;

use M4W\Core\Database as DB;
use M4W\Service\Calendar;
use M4W\Service\Scheduling;
use Sabre\CalDAV\Backend\AbstractBackend;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\DAV\Exception;
use Sabre\DAV\PropPatch;

/**
 * Calendars over CalDAV: every calendar the person sees on the web (their own, the ones shared
 * with them, rooms), read-only where they may only read. Writes go through the same code as the
 * web (validation, invitations sent by the server).
 */
final class CalDavBackend extends AbstractBackend
{
    public function __construct(private readonly array $user)
    {
    }

    private function cal(int $id, int $min): array
    {
        $c = Calendar::find($id);
        if (!$c || Calendar::level($this->user, $c) < $min) {
            throw $min > 1 ? new Exception\Forbidden() : new Exception\NotFound();
        }
        return $c;
    }

    public function getCalendarsForUser($principalUri)
    {
        if ($principalUri !== Principals::uri($this->user)) {
            return [];
        }
        $out = [];
        foreach (Calendar::forUser($this->user) as $id => $c) {
            $out[] = [
                'id' => $id,
                'uri' => $c['mine'] ? $c['uri'] : 'c' . $id,
                'principaluri' => $principalUri,
                '{DAV:}displayname' => $c['name'],
                '{http://apple.com/ns/ical/}calendar-color' => $c['color'],
                '{http://calendarserver.org/ns/}getctag' => (string) $c['ctag'],
                '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new SupportedCalendarComponentSet(['VEVENT', 'VTODO']),
                '{http://sabredav.org/ns}read-only' => $c['level'] === 'read' ? 1 : 0,
            ];
        }
        return $out;
    }

    public function createCalendar($principalUri, $calendarUri, array $properties)
    {
        $id = Calendar::create($this->user, (string) ($properties['{DAV:}displayname'] ?? $calendarUri), 'personal', '');
        if (preg_match('/^[\w.\-]{1,120}$/', (string) $calendarUri)) {
            DB::update('calendars', ['uri' => $calendarUri], 'id = :id', ['id' => $id]);
        }
        $color = (string) ($properties['{http://apple.com/ns/ical/}calendar-color'] ?? '');
        if ($color !== '') {
            Calendar::update($this->user, $id, ['color' => substr($color, 0, 7)]);
        }
        return $id;
    }

    public function updateCalendar($calendarId, PropPatch $propPatch)
    {
        $propPatch->handle(['{DAV:}displayname', '{http://apple.com/ns/ical/}calendar-color'], function (array $props) use ($calendarId) {
            $c = $this->cal((int) $calendarId, 1);
            if (Calendar::level($this->user, $c) < 3) {
                return false;
            }
            Calendar::update($this->user, (int) $calendarId, [
                'name' => $props['{DAV:}displayname'] ?? null,
                'color' => isset($props['{http://apple.com/ns/ical/}calendar-color']) ? substr((string) $props['{http://apple.com/ns/ical/}calendar-color'], 0, 7) : null,
            ]);
            return true;
        });
    }

    public function deleteCalendar($calendarId)
    {
        try {
            Calendar::delete($this->user, (int) $calendarId);
        } catch (\InvalidArgumentException $e) {
            throw new Exception\Forbidden($e->getMessage());
        }
    }

    private static function row(array $r, bool $withData): array
    {
        $out = ['id' => (int) $r['id'], 'uri' => $r['uri'], 'etag' => '"' . $r['etag'] . '"', 'size' => (int) $r['size'],
            'lastmodified' => (int) $r['updated_at'], 'component' => strtolower((string) $r['component'])];
        if ($withData) {
            $out['calendardata'] = (string) $r['data'];
        }
        return $out;
    }

    public function getCalendarObjects($calendarId)
    {
        $this->cal((int) $calendarId, 1);
        return array_map(static fn($r) => self::row($r, false), Calendar::objects((int) $calendarId));
    }

    public function getCalendarObject($calendarId, $objectUri)
    {
        $this->cal((int) $calendarId, 1);
        $r = Calendar::object((int) $calendarId, (string) $objectUri);
        return $r ? self::row($r, true) : null;
    }

    public function getMultipleCalendarObjects($calendarId, array $uris)
    {
        $this->cal((int) $calendarId, 1);
        if (!$uris) {
            return [];
        }
        [$in, $p] = DB::in('u', array_values($uris));
        return array_map(static fn($r) => self::row($r, true), DB::all("SELECT * FROM cal_objects WHERE calendar_id = :c AND uri IN $in", $p + ['c' => (int) $calendarId]));
    }

    public function createCalendarObject($calendarId, $objectUri, $calendarData)
    {
        return $this->write((int) $calendarId, (string) $objectUri, (string) $calendarData);
    }

    public function updateCalendarObject($calendarId, $objectUri, $calendarData)
    {
        return $this->write((int) $calendarId, (string) $objectUri, (string) $calendarData);
    }

    private function write(int $calendarId, string $uri, string $data): ?string
    {
        $this->cal($calendarId, 2);
        $old = Calendar::object($calendarId, $uri);
        try {
            Calendar::put($calendarId, $uri, $data);
        } catch (\InvalidArgumentException $e) {
            throw new Exception\BadRequest($e->getMessage());
        }
        $new = Calendar::object($calendarId, $uri);
        // The server sends the invitations (we announce calendar-auto-schedule).
        Scheduling::afterChange($this->user, $old ? (string) $old['data'] : null, (string) $new['data']);
        // No ETag: the stored copy was normalised, the client must fetch it again.
        return null;
    }

    public function deleteCalendarObject($calendarId, $objectUri)
    {
        $this->cal((int) $calendarId, 2);
        $old = Calendar::object((int) $calendarId, (string) $objectUri);
        Calendar::remove((int) $calendarId, (string) $objectUri);
        if ($old) {
            Scheduling::afterChange($this->user, (string) $old['data'], null);
        }
    }

    /** Time-range queries use the indexed occurrence bounds before the exact check. */
    public function calendarQuery($calendarId, array $filters)
    {
        $this->cal((int) $calendarId, 1);
        $comp = $filters['comp-filters'][0] ?? null;
        $range = $comp['time-range'] ?? null;
        $sql = 'SELECT * FROM cal_objects WHERE calendar_id = :c';
        $p = ['c' => (int) $calendarId];
        if ($comp && !$comp['is-not-defined']) {
            $sql .= ' AND component = :comp';
            $p['comp'] = strtoupper((string) $comp['name']);
        }
        if ($range && ($p['comp'] ?? '') === 'VEVENT') {
            if (!empty($range['start'])) {
                $sql .= ' AND last_occurrence > :s';
                $p['s'] = $range['start']->getTimestamp();
            }
            if (!empty($range['end'])) {
                $sql .= ' AND first_occurrence < :e';
                $p['e'] = $range['end']->getTimestamp();
            }
        }
        $simple = !$filters['prop-filters'] && (!$comp || (!$comp['comp-filters'] && !$comp['prop-filters'] && !$range));
        $out = [];
        foreach (DB::all($sql, $p) as $r) {
            if ($simple || $this->validateFilterForObject(self::row($r, true), $filters)) {
                $out[] = $r['uri'];
            }
        }
        return $out;
    }

    public function getCalendarObjectByUID($principalUri, $uid)
    {
        foreach ($this->getCalendarsForUser($principalUri) as $c) {
            $r = Calendar::objectByUid((int) $c['id'], (string) $uid);
            if ($r) {
                return $c['uri'] . '/' . $r['uri'];
            }
        }
        return null;
    }
}
