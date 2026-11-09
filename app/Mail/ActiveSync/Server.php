<?php
declare(strict_types=1);

namespace M4W\Mail\ActiveSync;

use M4W\Core\Database as DB;
use M4W\Mail\ActiveSync\Wbxml as W;
use M4W\Service\Composer;
use M4W\Service\Folders;
use M4W\Service\Mailbox;

/**
 * Exchange ActiveSync for the mail apps of phones (Gmail, Samsung Email, Outlook…), which get
 * new mail at once with it ("push"), on the webmail's own mailboxes: its folders, its sorted
 * messages, read and flagged both ways; what they send goes as from the webmail (signature,
 * Sent, limits). Mail only (protocol 14.1). Served by bin/maild.php after the app password.
 *
 * For each device and folder, the sync key and what the device holds (message => flags) are
 * kept (eas_states): each Sync sends only what changed; Ping waits for a change.
 */
final class Server
{
    public const VERSIONS = '12.1,14.0,14.1';
    public const COMMANDS = 'Sync,SendMail,SmartForward,SmartReply,GetAttachment,FolderSync,FolderCreate,FolderDelete,FolderUpdate,MoveItems,GetItemEstimate,Search,Settings,Ping,ItemOperations,Provision';
    private const HEARTBEAT_MIN = 60;
    private const HEARTBEAT_MAX = 1740;
    private const FILTER_DAYS = [0 => 0, 1 => 1, 2 => 3, 3 => 7, 4 => 14, 5 => 31, 6 => 92, 7 => 183];

    private int $uid;
    private int $device = 0;
    /** @var ?callable test hook: fn(int $seconds) */
    public static $sleep = null;

    public function __construct(private array $user, private string $ip)
    {
        $this->uid = (int) $user['id'];
    }

    /** Headers of the answer to OPTIONS (what this server speaks). */
    public static function optionsHeaders(): array
    {
        return ['MS-Server-ActiveSync' => '14.1', 'MS-ASProtocolVersions' => self::VERSIONS, 'MS-ASProtocolCommands' => self::COMMANDS, 'Allow' => 'OPTIONS,POST', 'Public' => 'OPTIONS,POST'];
    }

    /** Command codes of the encoded query (MS-ASHTTP 2.2.1.1.1.1.2). */
    private const CMD_CODES = [0 => 'Sync', 1 => 'SendMail', 2 => 'SmartForward', 3 => 'SmartReply', 4 => 'GetAttachment', 9 => 'FolderSync',
        10 => 'FolderCreate', 11 => 'FolderDelete', 12 => 'FolderUpdate', 13 => 'MoveItems', 14 => 'GetItemEstimate', 15 => 'MeetingResponse',
        16 => 'Search', 17 => 'Settings', 18 => 'Ping', 19 => 'ItemOperations', 20 => 'Provision', 21 => 'ResolveRecipients', 22 => 'ValidateCert'];

    /**
     * The request's query, plain ("Cmd=Sync&DeviceId=…") or base64-encoded as Outlook and iOS
     * send it; what the encoded form carries in place of headers goes into $h.
     * @return array<string,string>
     */
    public static function query(string $raw, array &$h): array
    {
        if ($raw === '' || str_contains($raw, '=') && !preg_match('/^[A-Za-z0-9+\/%]+={0,2}$/', $raw)) {
            parse_str($raw, $q);
            return $q;
        }
        $b = base64_decode(rawurldecode($raw), true);
        if (!is_string($b) || strlen($b) < 7) {
            parse_str($raw, $q);
            return $q;
        }
        $p = 0;
        $ver = ord($b[$p++]);
        $q = ['Cmd' => self::CMD_CODES[ord($b[$p++])] ?? ''];
        $p += 2; // locale
        $read = static function () use ($b, &$p): string {
            $n = ord($b[$p++] ?? "\0");
            $v = substr($b, $p, $n);
            $p += $n;
            return $v;
        };
        $q['DeviceId'] = bin2hex($read());
        $key = $read();
        if (strlen($key) === 4) {
            $h['x-ms-policykey'] = (string) unpack('V', $key)[1];
        }
        $q['DeviceType'] = $read();
        $h['ms-asprotocolversion'] = sprintf('%d.%d', intdiv($ver, 10), $ver % 10);
        while ($p < strlen($b)) {
            $tag = ord($b[$p++]);
            $v = $read();
            match ($tag) {
                0 => $q['AttachmentName'] = $v,
                1 => $q['CollectionId'] = $v,
                3 => $q['ItemId'] = $v,
                4 => $q['LongId'] = $v,
                6 => $q['Occurrence'] = $v,
                7 => [$q['SaveInSent'], $h['ms-asacceptmultipart']] = [ord($v[0] ?? "\0") & 1 ? 'T' : 'F', ord($v[0] ?? "\0") & 2 ? 'T' : 'F'],
                8 => $q['User'] = $v,
                default => null,
            };
        }
        return $q;
    }

    /**
     * One request: [HTTP status, headers, body].
     * @param array<string,string> $q the query (Cmd, DeviceId, DeviceType…)
     * @param array<string,string> $h request headers, lower case
     */
    public function handle(array $q, array $h, string $body): array
    {
        $cmd = (string) ($q['Cmd'] ?? '');
        $this->device($q, $h);
        $xml = ($h['content-type'] ?? '') === 'message/rfc822' ? null : W::decode($body);
        $out = match ($cmd) {
            'Provision' => $this->provision($xml),
            'FolderSync' => $this->folderSync($xml),
            'FolderCreate', 'FolderUpdate', 'FolderDelete' => $this->folderChange($cmd, $xml),
            'Sync' => $this->sync($xml, $body),
            'Ping' => $this->ping($xml),
            'GetItemEstimate' => $this->estimate($xml),
            'MoveItems' => $this->moveItems($xml),
            'ItemOperations' => $this->itemOperations($xml),
            'SendMail', 'SmartReply', 'SmartForward' => $this->send($cmd, $xml, $body, $q, $h),
            'Settings' => $this->settings($xml),
            'GetAttachment' => $this->getAttachment((string) ($q['AttachmentName'] ?? '')),
            'Search' => ['Search:Search', [['Search:Status', '1'], ['Search:Response', [['Search:Store', [['Search:Status', '1'], ['Search:Total', '0']]]]]]],
            default => null,
        };
        if ($out === null) {
            return [200, [], ''];
        }
        if (isset($out['raw'])) {
            return [200, ['Content-Type' => $out['type']], $out['raw']];
        }
        return [200, ['Content-Type' => 'application/vnd.ms-sync.wbxml'], W::encode($out)];
    }

    private function device(array $q, array $h): void
    {
        $id = substr(preg_replace('/[^\w-]/', '', (string) ($q['DeviceId'] ?? '')) ?? '', 0, 64) ?: 'unknown';
        $row = DB::one('SELECT id FROM eas_devices WHERE user_id = :u AND device_id = :d', ['u' => $this->uid, 'd' => $id]);
        $data = ['device_type' => mb_substr((string) ($q['DeviceType'] ?? ''), 0, 64), 'user_agent' => mb_substr((string) ($h['user-agent'] ?? ''), 0, 190), 'last_seen_at' => time(), 'last_ip' => $this->ip];
        if ($row) {
            DB::update('eas_devices', $data, 'id = :id', ['id' => $row['id']]);
            $this->device = (int) $row['id'];
        } else {
            try {
                $this->device = DB::insert('eas_devices', $data + ['user_id' => $this->uid, 'device_id' => $id, 'created_at' => time()]);
            } catch (\PDOException) {
                // The phone opens several connections at once: another one created it meanwhile.
                $this->device = (int) DB::value('SELECT id FROM eas_devices WHERE user_id = :u AND device_id = :d', ['u' => $this->uid, 'd' => $id]);
            }
        }
    }

    // ------------------------------------------------------------------ state

    private function state(string $collection): array
    {
        $r = DB::one('SELECT * FROM eas_states WHERE device = :d AND collection = :c', ['d' => $this->device, 'c' => $collection]);
        return $r ? ['key' => (int) $r['sync_key'], 'data' => json_decode((string) $r['data'], true) ?: [], 'previous' => json_decode((string) $r['previous'], true) ?: [], 'stamp' => (string) $r['stamp']]
            : ['key' => 0, 'data' => [], 'previous' => [], 'stamp' => ''];
    }

    private function save(string $collection, int $key, array $data, array $previous, string $stamp = ''): void
    {
        $row = ['sync_key' => $key, 'data' => json_encode($data), 'previous' => json_encode($previous), 'stamp' => $stamp, 'updated_at' => time()];
        // Not "update, else insert": MariaDB counts the rows changed, not those found, so an
        // unchanged state would be inserted twice. And parallel requests of one phone may race.
        $where = ['d' => $this->device, 'c' => $collection];
        if (DB::value('SELECT 1 FROM eas_states WHERE device = :d AND collection = :c', $where)) {
            DB::update('eas_states', $row, 'device = :d AND collection = :c', $where);
            return;
        }
        try {
            DB::insert('eas_states', $row + ['device' => $this->device, 'collection' => $collection]);
        } catch (\PDOException) {
            DB::update('eas_states', $row, 'device = :d AND collection = :c', $where);
        }
    }

    // ------------------------------------------------------------------ Provision (no policy is enforced)

    private function provision(?array $x): array
    {
        $policy = W::child(W::child($x, 'Policies'), 'Policy');
        $ack = $policy && W::text($policy, 'PolicyKey') !== '';
        $key = random_int(100000000, 999999999);
        DB::update('eas_devices', ['policy_key' => $key], 'id = :id', ['id' => $this->device]);
        $p = [['Provision:PolicyType', 'MS-EAS-Provisioning-WBXML'], ['Provision:Status', '1'], ['Provision:PolicyKey', (string) $key]];
        if (!$ack) {
            $p[] = ['Provision:Data', [['Provision:EASProvisionDoc', [['Provision:DevicePasswordEnabled', '0']]]]];
        }
        $out = [['Provision:Status', '1'], ['Provision:Policies', [['Provision:Policy', $p]]]];
        if (W::child($x, 'DeviceInformation')) {
            array_unshift($out, ['Settings:DeviceInformation', [['Settings:Status', '1']]]);
        }
        return ['Provision:Provision', $out];
    }

    // ------------------------------------------------------------------ folders

    private const TYPES = ['inbox' => 2, 'drafts' => 3, 'trash' => 4, 'sent' => 5];

    /** @return array<string, array{0:string,1:string,2:int}> id => [name, parent, type] */
    private function folders(): array
    {
        $hidden = Mailbox::$privacy ? Folders::personalIds($this->uid) : [];
        $out = [];
        foreach (DB::all('SELECT * FROM folders WHERE user_id = :u ORDER BY id', ['u' => $this->uid]) as $f) {
            if (in_array((int) $f['id'], $hidden, true)) {
                continue;
            }
            $out[(string) $f['id']] = [Folders::displayName($f), $f['parent_id'] !== null ? (string) $f['parent_id'] : '0', self::TYPES[$f['role'] ?? ''] ?? 12];
        }
        return $out;
    }

    private function folderNode(string $tag, string $id, array $f): array
    {
        return ['FolderHierarchy:' . $tag, [['FolderHierarchy:ServerId', $id], ['FolderHierarchy:ParentId', $f[1]], ['FolderHierarchy:DisplayName', $f[0]], ['FolderHierarchy:Type', (string) $f[2]]]];
    }

    private function folderSync(?array $x): array
    {
        $key = (int) W::text($x, 'SyncKey', '0');
        $st = $this->state('hier');
        if ($key !== 0 && $key !== $st['key']) {
            return ['FolderHierarchy:FolderSync', [['FolderHierarchy:Status', '9']]];
        }
        $old = $key === 0 ? [] : $st['data'];
        $now = $this->folders();
        $changes = [];
        foreach ($now as $id => $f) {
            if (!isset($old[$id])) {
                $changes[] = $this->folderNode('Add', (string) $id, $f);
            } elseif ($old[$id] !== $f) {
                $changes[] = $this->folderNode('Update', (string) $id, $f);
            }
        }
        foreach (array_diff_key($old, $now) as $id => $_) {
            $changes[] = ['FolderHierarchy:Delete', [['FolderHierarchy:ServerId', (string) $id]]];
        }
        $newKey = $changes || $key === 0 ? $st['key'] + 1 : $st['key'];
        $this->save('hier', $newKey, $now, $old);
        return ['FolderHierarchy:FolderSync', [['FolderHierarchy:Status', '1'], ['FolderHierarchy:SyncKey', (string) $newKey],
            ['FolderHierarchy:Changes', array_merge([['FolderHierarchy:Count', (string) count($changes)]], $changes)]]];
    }

    private function folderChange(string $cmd, ?array $x): array
    {
        $tag = 'FolderHierarchy:' . $cmd;
        $st = $this->state('hier');
        $id = W::text($x, 'ServerId');
        $name = trim(W::text($x, 'DisplayName'));
        $parent = (int) W::text($x, 'ParentId', '0');
        $f = $id !== '' ? Folders::find($this->uid, (int) $id) : null;
        try {
            if ($cmd === 'FolderCreate') {
                $id = (string) Folders::create($this->uid, $name, $parent ?: null);
            } elseif (!$f || $f['role']) {
                return [$tag, [['FolderHierarchy:Status', $f ? '2' : '4']]];
            } elseif ($cmd === 'FolderUpdate') {
                Folders::rename($this->uid, (int) $id, $name);
                DB::update('folders', ['parent_id' => $parent ?: null], 'id = :id AND user_id = :u', ['id' => $id, 'u' => $this->uid]);
            } else {
                Folders::delete($this->uid, (int) $id);
            }
        } catch (\Throwable) {
            return [$tag, [['FolderHierarchy:Status', '6']]];
        }
        // The device knows of it already: in its state, not sent back at the next FolderSync.
        $this->save('hier', $st['key'] + 1, $this->folders(), $st['data']);
        $out = [['FolderHierarchy:Status', '1'], ['FolderHierarchy:SyncKey', (string) ($st['key'] + 1)]];
        if ($cmd === 'FolderCreate') {
            $out[] = ['FolderHierarchy:ServerId', $id];
        }
        return [$tag, $out];
    }

    // ------------------------------------------------------------------ messages

    private function stamp(int $folderId): string
    {
        $s = DB::one('SELECT COUNT(*) AS n, COALESCE(SUM(id), 0) AS s, COALESCE(SUM(is_read + 2 * is_flagged), 0) AS f FROM messages WHERE user_id = :u AND folder_id = :f',
            ['u' => $this->uid, 'f' => $folderId]);
        return implode('|', array_map('strval', (array) $s));
    }

    /** @return array<string, string> message id => flags ("read flagged"), newest first, within the filter */
    private function current(int $folderId, int $filter): array
    {
        $days = self::FILTER_DAYS[$filter] ?? 0;
        $rows = DB::all('SELECT id, is_read, is_flagged FROM messages WHERE user_id = :u AND folder_id = :f AND scheduled_at = 0' . ($days ? ' AND date_received >= :d' : '') . ' ORDER BY date_received DESC, id DESC',
            ['u' => $this->uid, 'f' => $folderId] + ($days ? ['d' => time() - $days * 86400] : []));
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['id']] = $r['is_read'] . $r['is_flagged'];
        }
        return $out;
    }

    /** What a device is told of a message (Email and AirSyncBase), its body as it prefers. */
    private function appData(array $m, array $pref, bool $mime = false): array
    {
        $raw = Mailbox::raw($m);
        $p = Mailbox::parsed($m);
        $hdr = static fn(string $n) => (string) ($p->header($n) ?? '');
        $type = $mime ? 4 : (in_array(2, $pref['types'], true) ? 2 : (in_array(4, $pref['types'], true) ? 4 : 1));
        if ($type === 4) {
            $data = (string) preg_replace("/\r?\n/", "\r\n", $raw);
        } elseif ($type === 2) {
            $data = $p->html() !== '' ? $p->html() : nl2br(htmlspecialchars($p->text(), ENT_QUOTES, 'UTF-8'), false);
        } else {
            $data = $p->text() !== '' ? $p->text() : trim(html_entity_decode(strip_tags($p->html()), ENT_QUOTES, 'UTF-8'));
        }
        $size = strlen($data);
        $max = (int) ($pref['size'][$type] ?? 0);
        $truncated = $max > 0 && $size > $max;
        if ($truncated) {
            $data = mb_strcut($data, 0, $max, 'UTF-8');
        }
        $out = [
            ['Email:To', $hdr('To')], ['Email:Cc', $hdr('Cc')], ['Email:From', $hdr('From')], ['Email:Subject', (string) $m['subject']],
            $hdr('Reply-To') !== '' ? ['Email:ReplyTo', $hdr('Reply-To')] : null,
            ['Email:DateReceived', gmdate('Y-m-d\TH:i:s.000\Z', (int) $m['date_received'])],
            ['Email:DisplayTo', implode('; ', array_map(static fn($a) => $a['name'] ?: $a['email'], $p->to()))],
            ['Email:ThreadTopic', (string) preg_replace('/^((re|tr|fwd?|aw)\s*:\s*)+/i', '', (string) $m['subject'])],
            ['Email:Importance', (string) ((int) $m['priority'] < 3 ? 2 : ((int) $m['priority'] > 3 ? 0 : 1))],
            ['Email:Read', (string) (int) $m['is_read']],
        ];
        $atts = [];
        foreach ($p->attachmentParts() as $part) {
            $atts[] = ['AirSyncBase:Attachment', [
                ['AirSyncBase:DisplayName', $part->filename() ?: 'attachment'], ['AirSyncBase:FileReference', $m['id'] . ':' . $part->id],
                ['AirSyncBase:Method', '1'], ['AirSyncBase:EstimatedDataSize', (string) strlen($part->body)],
                $part->contentId() !== '' ? ['AirSyncBase:ContentId', $part->contentId()] : null,
                ['AirSyncBase:IsInline', $part->contentId() !== '' && str_contains($p->html(), 'cid:' . $part->contentId()) ? '1' : '0'],
            ]];
        }
        if ($atts) {
            $out[] = ['AirSyncBase:Attachments', $atts];
        }
        $out[] = ['AirSyncBase:Body', [['AirSyncBase:Type', (string) $type], ['AirSyncBase:EstimatedDataSize', (string) $size], ['AirSyncBase:Truncated', $truncated ? '1' : '0'], ['AirSyncBase:Data', $data]]];
        $out[] = ['Email:MessageClass', 'IPM.Note'];
        $out[] = ['Email:InternetCPID', '65001'];
        $out[] = (int) $m['is_flagged'] ? ['Email:Flag', [['Email:FlagStatus', '2'], ['Email:FlagType', 'for Follow Up']]] : ['Email:Flag', ''];
        $out[] = ['Email:ContentClass', 'urn:content-classes:message'];
        $out[] = ['AirSyncBase:NativeBodyType', $p->html() !== '' ? '2' : '1'];
        return $out;
    }

    /** The body preferences of a request's Options: types asked, and their truncation. */
    private static function prefs(?array $options): array
    {
        $types = [];
        $size = [];
        foreach (W::children($options, 'BodyPreference') as $bp) {
            $t = (int) W::text($bp, 'Type', '1');
            $types[] = $t;
            $size[$t] = (int) W::text($bp, 'TruncationSize', '0');
        }
        return ['types' => $types ?: [1], 'size' => $size, 'mime' => (int) W::text($options, 'MIMESupport', '0') === 2];
    }

    private function message(string $serverId): ?array
    {
        $id = (int) (explode(':', $serverId)[1] ?? $serverId);
        return $id ? Mailbox::get($this->uid, $id) : null;
    }

    private function sync(?array $x, string $body): array
    {
        // An empty Sync: the previous one again.
        if ($x === null) {
            $x = W::decode(base64_decode((string) ($this->state('last-sync')['data']['body'] ?? '')));
            if ($x === null) {
                return ['AirSync:Sync', [['AirSync:Status', '13']]];
            }
        } else {
            $this->save('last-sync', 0, ['body' => base64_encode($body)], []);
        }
        $collections = [];
        foreach (W::children(W::child($x, 'Collections'), 'Collection') as $c) {
            $collections[] = $this->syncCollection($c, (int) W::text($x, 'WindowSize', '100'));
        }
        return ['AirSync:Sync', [['AirSync:Collections', $collections]]];
    }

    private function syncCollection(array $c, int $window): array
    {
        $fid = (int) W::text($c, 'CollectionId');
        $key = (int) W::text($c, 'SyncKey', '0');
        $window = max(1, min(100, (int) W::text($c, 'WindowSize', (string) $window)));
        $folder = Folders::find($this->uid, $fid);
        if (!$folder) {
            return ['AirSync:Collection', [['AirSync:SyncKey', (string) $key], ['AirSync:CollectionId', (string) $fid], ['AirSync:Status', '8']]];
        }
        $col = 'F' . $fid;
        $st = $this->state($col);
        if ($key === 0) {
            $this->save($col, 1, [], [], '');
            return ['AirSync:Collection', [['AirSync:SyncKey', '1'], ['AirSync:CollectionId', (string) $fid], ['AirSync:Status', '1']]];
        }
        if ($key === $st['key']) {
            $have = $st['data'];
        } elseif ($key === $st['key'] - 1) {
            $have = $st['previous']; // the device did not get the last answer: sent again
        } else {
            return ['AirSync:Collection', [['AirSync:SyncKey', '0'], ['AirSync:CollectionId', (string) $fid], ['AirSync:Status', '3']]];
        }
        $options = W::child($c, 'Options');
        $pref = self::prefs($options);
        $filter = (int) W::text($options, 'FilterType', (string) ($st['data']['#filter'] ?? 0));
        unset($have['#filter']);
        $responses = [];
        // What the device changed.
        foreach ((array) (W::child($c, 'Commands')[1] ?? []) as $cmd) {
            $sid = W::text($cmd, 'ServerId');
            $m = $this->message($sid);
            $short = substr($cmd[0], strpos($cmd[0], ':') + 1);
            if ($short === 'Change' && $m) {
                $ad = W::child($cmd, 'ApplicationData');
                $flags = [];
                if (W::child($ad, 'Read')) {
                    $flags['is_read'] = (int) W::text($ad, 'Read');
                }
                if ($flag = W::child($ad, 'Flag')) {
                    $flags['is_flagged'] = (int) W::text($flag, 'FlagStatus', '0') === 2 ? 1 : 0;
                }
                if ($flags) {
                    Mailbox::setFlags($this->uid, [(int) $m['id']], $flags);
                    $m = Mailbox::get($this->uid, (int) $m['id']);
                    $have[(string) $m['id']] = $m['is_read'] . $m['is_flagged'];
                }
            } elseif ($short === 'Delete') {
                if ($m) {
                    $deletesAsMoves = W::text($c, 'DeletesAsMoves', '1') !== '0';
                    Mailbox::delete($this->uid, [(int) $m['id']], !$deletesAsMoves);
                }
                unset($have[(string) ($m['id'] ?? explode(':', $sid)[1] ?? '')]);
            } elseif ($short === 'Fetch') {
                $responses[] = $m ? ['AirSync:Fetch', [['AirSync:ServerId', $sid], ['AirSync:Status', '1'], ['AirSync:ApplicationData', $this->appData($m, $pref, $pref['mime'])]]]
                    : ['AirSync:Fetch', [['AirSync:ServerId', $sid], ['AirSync:Status', '8']]];
            } elseif ($short === 'Add') {
                $responses[] = ['AirSync:Add', [['AirSync:ClientId', W::text($cmd, 'ClientId')], ['AirSync:Status', '6']]];
            }
        }
        // What changed here, a window at a time.
        $commands = [];
        $more = false;
        if (W::text($c, 'GetChanges', '1') !== '0') {
            $now = $this->current($fid, $filter);
            $n = 0;
            foreach (array_diff_key($have, $now) as $id => $_) {
                if ($n++ >= $window) {
                    $more = true;
                    break;
                }
                $commands[] = ['AirSync:Delete', [['AirSync:ServerId', $fid . ':' . $id]]];
                unset($have[$id]);
            }
            foreach ($now as $id => $sig) {
                if (isset($have[$id]) && $have[$id] === $sig) {
                    continue;
                }
                if ($n++ >= $window) {
                    $more = true;
                    break;
                }
                $m = Mailbox::get($this->uid, (int) $id);
                if (!$m) {
                    continue;
                }
                if (isset($have[$id])) {
                    $f = (int) $m['is_flagged'] ? ['Email:Flag', [['Email:FlagStatus', '2'], ['Email:FlagType', 'for Follow Up']]] : ['Email:Flag', ''];
                    $commands[] = ['AirSync:Change', [['AirSync:ServerId', $fid . ':' . $id], ['AirSync:ApplicationData', [['Email:Read', (string) (int) $m['is_read']], $f]]]];
                } else {
                    $commands[] = ['AirSync:Add', [['AirSync:ServerId', $fid . ':' . $id], ['AirSync:ApplicationData', $this->appData($m, $pref, $pref['mime'])]]];
                }
                $have[$id] = $sig;
            }
        }
        $newKey = $st['key'] + 1;
        $this->save($col, $newKey, $have + ['#filter' => $filter], $st['data'], $more ? '' : $this->stamp($fid));
        $out = [['AirSync:SyncKey', (string) $newKey], ['AirSync:CollectionId', (string) $fid], ['AirSync:Status', '1']];
        if ($commands) {
            $out[] = ['AirSync:Commands', $commands];
        }
        if ($responses) {
            $out[] = ['AirSync:Responses', $responses];
        }
        if ($more) {
            $out[] = ['AirSync:MoreAvailable', ''];
        }
        return ['AirSync:Collection', $out];
    }

    private function estimate(?array $x): array
    {
        $out = [];
        foreach (W::children(W::child($x, 'Collections'), 'Collection') as $c) {
            $fid = (int) W::text($c, 'CollectionId');
            $st = $this->state('F' . $fid);
            $have = $st['data'];
            $filter = (int) W::text(W::child($c, 'Options'), 'FilterType', (string) ($have['#filter'] ?? 0));
            unset($have['#filter']);
            $now = $this->current($fid, $filter);
            $n = count(array_diff_key($have, $now)) + count(array_filter($now, static fn($sig, $id) => ($have[$id] ?? null) !== $sig, ARRAY_FILTER_USE_BOTH));
            $out[] = ['GetItemEstimate:Response', [['GetItemEstimate:Status', Folders::find($this->uid, $fid) ? '1' : '2'],
                ['GetItemEstimate:Collection', [['GetItemEstimate:CollectionId', (string) $fid], ['GetItemEstimate:Estimate', (string) $n]]]]];
        }
        return ['GetItemEstimate:GetItemEstimate', $out];
    }

    // ------------------------------------------------------------------ Ping: waits for a change

    private function ping(?array $x): array
    {
        $saved = $this->state('ping')['data'];
        $heartbeat = $x ? (int) W::text($x, 'HeartbeatInterval', (string) ($saved['hb'] ?? 600)) : (int) ($saved['hb'] ?? 600);
        $ids = $x && W::child($x, 'Folders') ? array_map(static fn($f) => (int) W::text($f, 'Id'), W::children(W::child($x, 'Folders'), 'Folder')) : (array) ($saved['ids'] ?? []);
        if ($heartbeat < self::HEARTBEAT_MIN || $heartbeat > self::HEARTBEAT_MAX) {
            return ['Ping:Ping', [['Ping:Status', '5'], ['Ping:HeartbeatInterval', (string) max(self::HEARTBEAT_MIN, min(self::HEARTBEAT_MAX, $heartbeat))]]];
        }
        if (!$ids) {
            return ['Ping:Ping', [['Ping:Status', '3']]];
        }
        $this->save('ping', 0, ['hb' => $heartbeat, 'ids' => $ids], []);
        $known = [];
        foreach ($ids as $id) {
            $known[$id] = $this->state('F' . $id)['stamp'];
        }
        $until = time() + $heartbeat - 5;
        do {
            $changed = [];
            foreach ($known as $id => $stamp) {
                if ($this->stamp($id) !== $stamp) {
                    $changed[] = ['Ping:Folder', (string) $id];
                }
            }
            if ($changed) {
                return ['Ping:Ping', [['Ping:Status', '2'], ['Ping:Folders', $changed]]];
            }
            self::$sleep ? (self::$sleep)(5) : sleep(5);
        } while (time() < $until);
        return ['Ping:Ping', [['Ping:Status', '1']]];
    }

    // ------------------------------------------------------------------ moving, fetching

    private function moveItems(?array $x): array
    {
        $out = [];
        foreach (W::children($x, 'Move') as $mv) {
            $src = W::text($mv, 'SrcMsgId');
            $dst = (int) W::text($mv, 'DstFldId');
            $m = $this->message($src);
            if (!$m) {
                $out[] = ['Move:Response', [['Move:SrcMsgId', $src], ['Move:Status', '1']]];
                continue;
            }
            if (!Folders::find($this->uid, $dst)) {
                $out[] = ['Move:Response', [['Move:SrcMsgId', $src], ['Move:Status', '2']]];
                continue;
            }
            $from = (int) $m['folder_id'];
            Mailbox::move($this->uid, [(int) $m['id']], $dst);
            // Both folders' states: the device already did the move.
            foreach ([['F' . $from, false], ['F' . $dst, true]] as [$col, $add]) {
                $st = $this->state($col);
                if ($st['key']) {
                    $data = $st['data'];
                    if ($add) {
                        $data[(string) $m['id']] = $m['is_read'] . $m['is_flagged'];
                    } else {
                        unset($data[(string) $m['id']]);
                    }
                    DB::update('eas_states', ['data' => json_encode($data)], 'device = :d AND collection = :c', ['d' => $this->device, 'c' => $col]);
                }
            }
            $out[] = ['Move:Response', [['Move:SrcMsgId', $src], ['Move:Status', '3'], ['Move:DstMsgId', $dst . ':' . $m['id']]]];
        }
        return ['Move:MoveItems', $out];
    }

    /** @return ?array{0:array,1:\M4W\Mail\MimePart} the message and the attachment part of a FileReference */
    private function attachment(string $ref): ?array
    {
        [$mid, $part] = array_pad(explode(':', $ref, 2), 2, '');
        $m = Mailbox::get($this->uid, (int) $mid);
        $p = $m ? Mailbox::parsed($m)->findPart($part) : null;
        return $p ? [$m, $p] : null;
    }

    private function itemOperations(?array $x): array
    {
        $out = [];
        foreach ($x[1] ?? [] as $op) {
            $short = substr($op[0], strpos($op[0], ':') + 1);
            if ($short === 'Fetch') {
                $ref = W::text($op, 'FileReference');
                if ($ref !== '') {
                    $a = $this->attachment($ref);
                    $out[] = $a ? ['ItemOperations:Fetch', [['ItemOperations:Status', '1'], ['AirSyncBase:FileReference', $ref],
                        ['ItemOperations:Properties', [['AirSyncBase:ContentType', $a[1]->type], ['ItemOperations:Data', base64_encode($a[1]->decodedBody())]]]]]
                        : ['ItemOperations:Fetch', [['ItemOperations:Status', '15'], ['AirSyncBase:FileReference', $ref]]];
                    continue;
                }
                $sid = W::text($op, 'ServerId');
                $m = $this->message($sid);
                $pref = self::prefs(W::child($op, 'Options'));
                $out[] = $m ? ['ItemOperations:Fetch', [['ItemOperations:Status', '1'], ['AirSync:CollectionId', (string) $m['folder_id']], ['AirSync:ServerId', $sid], ['AirSync:Class', 'Email'],
                    ['ItemOperations:Properties', $this->appData($m, $pref, $pref['mime'])]]]
                    : ['ItemOperations:Fetch', [['ItemOperations:Status', '6'], ['AirSync:ServerId', $sid]]];
            } elseif ($short === 'EmptyFolderContents') {
                $fid = (int) W::text($op, 'CollectionId');
                if (Folders::find($this->uid, $fid)) {
                    Mailbox::emptyFolder($this->uid, $fid);
                }
                $out[] = ['ItemOperations:EmptyFolderContents', [['ItemOperations:Status', '1'], ['AirSync:CollectionId', (string) $fid]]];
            }
        }
        return ['ItemOperations:ItemOperations', [['ItemOperations:Status', '1'], ['ItemOperations:Response', $out]]];
    }

    private function getAttachment(string $ref): array
    {
        $a = $this->attachment($ref);
        return $a ? ['raw' => $a[1]->decodedBody(), 'type' => $a[1]->type] : ['raw' => '', 'type' => 'text/plain'];
    }

    // ------------------------------------------------------------------ sending

    private function send(string $cmd, ?array $x, string $body, array $q, array $h): ?array
    {
        $tag = 'ComposeMail:' . $cmd;
        // 12.1: the message itself as the body; 14: in a WBXML document.
        if ($x === null) {
            $mime = $body;
            $source = ['id' => (int) ($q['ItemId'] ?? 0)];
        } else {
            $mimeNode = W::child($x, 'Mime');
            $mime = is_array($mimeNode[1] ?? null) ? (string) ($mimeNode[1]['opaque'] ?? '') : (string) ($mimeNode[1] ?? '');
            $src = W::child($x, 'Source');
            $source = ['id' => (int) (explode(':', W::text($src, 'ItemId'))[1] ?? W::text($src, 'ItemId'))];
        }
        if ($cmd !== 'SendMail' && !empty($source['id'])) {
            $source['mode'] = $cmd === 'SmartReply' ? 'reply' : 'forward';
        } else {
            $source = [];
        }
        try {
            $p = \M4W\Mail\MimeParser::parse(str_replace("\r\n", "\n", $mime));
            $rcpts = array_map(static fn($a) => $a['email'], array_merge($p->to(), $p->cc(), $p->bcc()));
            Composer::submit($this->user, $mime, $rcpts, $source);
            return null;
        } catch (\Throwable $e) {
            error_log('[m4w] activesync ' . $cmd . ': ' . $e->getMessage());
            return [$tag, [['ComposeMail:Status', '120']]];
        }
    }

    // ------------------------------------------------------------------ settings

    private function settings(?array $x): array
    {
        $out = [['Settings:Status', '1']];
        if (W::child($x, 'DeviceInformation')) {
            $out[] = ['Settings:DeviceInformation', [['Settings:Status', '1']]];
        }
        if (W::child($x, 'UserInformation')) {
            $addresses = array_map(static fn($i) => ['Settings:SmtpAddress', $i['email']], Composer::identities($this->user));
            $out[] = ['Settings:UserInformation', [['Settings:Status', '1'], ['Settings:Get', [['Settings:EmailAddresses', $addresses]]]]];
        }
        if (W::child($x, 'Oof')) {
            $out[] = ['Settings:Oof', [['Settings:Status', '1'], ['Settings:Get', [['Settings:OofState', '0']]]]];
        }
        return ['Settings:Settings', $out];
    }
}
