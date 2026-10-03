<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Database as DB;
use M4W\Mail\Address;
use M4W\Mail\ParsedMessage;

/**
 * Incoming message rules (filters), evaluated at delivery time.
 */
final class RuleEngine
{
    public const FIELDS = ['from', 'to', 'cc', 'recipients', 'subject', 'body', 'header', 'size', 'has_attachment', 'priority'];
    public const OPS = ['contains', 'not_contains', 'equals', 'not_equals', 'starts_with', 'ends_with', 'gt', 'lt', 'is_true', 'is_false'];
    public const ACTIONS = ['move', 'copy', 'mark_read', 'flag', 'trash', 'discard', 'redirect', 'reply', 'stop'];

    public static function forUser(int $userId, bool $enabledOnly = false): array
    {
        $rows = DB::all('SELECT * FROM rules WHERE user_id = :u' . ($enabledOnly ? ' AND enabled = 1' : '') . ' ORDER BY sort, id', ['u' => $userId]);
        foreach ($rows as &$r) {
            $r['conditions'] = json_decode((string) $r['conditions'], true) ?: [];
            $r['actions'] = json_decode((string) $r['actions'], true) ?: [];
        }
        return $rows;
    }

    /**
     * Normalize and validate a rule coming from the UI.
     * @throws \InvalidArgumentException
     */
    public static function normalize(int $userId, array $in): array
    {
        $conditions = [];
        foreach ((array) ($in['conditions'] ?? []) as $c) {
            $field = (string) ($c['field'] ?? '');
            $op = (string) ($c['op'] ?? 'contains');
            if (!in_array($field, self::FIELDS, true) || !in_array($op, self::OPS, true)) {
                continue;
            }
            $conditions[] = [
                'field'  => $field,
                'op'     => $op,
                'value'  => mb_substr(trim((string) ($c['value'] ?? '')), 0, 500),
                'header' => $field === 'header' ? preg_replace('/[^A-Za-z0-9-]/', '', (string) ($c['header'] ?? '')) : '',
            ];
        }
        $actions = [];
        foreach ((array) ($in['actions'] ?? []) as $a) {
            $type = (string) ($a['type'] ?? '');
            if (!in_array($type, self::ACTIONS, true)) {
                continue;
            }
            $act = ['type' => $type];
            if ($type === 'move' || $type === 'copy') {
                $fid = (int) ($a['folder'] ?? 0);
                if (!Folders::find($userId, $fid)) {
                    throw new \InvalidArgumentException(t('rules.invalid_folder'));
                }
                $act['folder'] = $fid;
            } elseif ($type === 'redirect') {
                $to = mb_strtolower(trim((string) ($a['to'] ?? '')));
                if (!is_valid_email($to)) {
                    throw new \InvalidArgumentException(t('rules.invalid_email'));
                }
                Forwarding::assertAllowed($to);
                $act['to'] = $to;
            } elseif ($type === 'reply') {
                $act['subject'] = mb_substr(trim((string) ($a['subject'] ?? '')), 0, 200);
                $act['body'] = mb_substr((string) ($a['body'] ?? ''), 0, 10000);
            }
            $actions[] = $act;
        }
        if (!$actions) {
            throw new \InvalidArgumentException(t('rules.need_action'));
        }
        return [
            'name'            => mb_substr(trim((string) ($in['name'] ?? '')) ?: t('rules.untitled'), 0, 190),
            'enabled'         => !empty($in['enabled']) ? 1 : 0,
            'match_type'      => ($in['match_type'] ?? 'all') === 'any' ? 'any' : 'all',
            'conditions'      => json_encode($conditions, JSON_UNESCAPED_UNICODE),
            'actions'         => json_encode($actions, JSON_UNESCAPED_UNICODE),
            'stop_processing' => !empty($in['stop_processing']) ? 1 : 0,
        ];
    }

    public static function matches(array $rule, ParsedMessage $msg): bool
    {
        $conds = $rule['conditions'];
        if (!$conds) {
            return true; // "all messages"
        }
        $any = $rule['match_type'] === 'any';
        foreach ($conds as $c) {
            $ok = self::test($c, $msg);
            if ($any && $ok) {
                return true;
            }
            if (!$any && !$ok) {
                return false;
            }
        }
        return !$any;
    }

    private static function test(array $c, ParsedMessage $msg): bool
    {
        $field = $c['field'];
        $op = $c['op'];
        $value = mb_strtolower((string) $c['value']);

        if ($field === 'has_attachment') {
            $has = (bool) array_filter($msg->attachmentList(), static fn($a) => !$a['inline']);
            return $op === 'is_false' ? !$has : $has;
        }
        if ($field === 'size') {
            $kb = $msg->size / 1024;
            return $op === 'lt' ? $kb < (float) $value : $kb > (float) $value;
        }
        if ($field === 'priority') {
            $high = $msg->priority() < 3;
            return $op === 'is_false' ? !$high : $high;
        }
        $haystacks = match ($field) {
            'from'       => self::addrStrings([$msg->from()]),
            'to'         => self::addrStrings($msg->to()),
            'cc'         => self::addrStrings($msg->cc()),
            'recipients' => self::addrStrings(array_merge($msg->to(), $msg->cc())),
            'subject'    => [$msg->subject()],
            'body'       => [$msg->text()],
            'header'     => array_map([\M4W\Mail\Charset::class, 'decodeHeader'], $msg->headerAll((string) $c['header'])),
            default      => [],
        };
        $haystacks = array_map('mb_strtolower', $haystacks);
        $negative = in_array($op, ['not_contains', 'not_equals'], true);
        foreach ($haystacks as $h) {
            $hit = match ($op) {
                'contains', 'not_contains' => $value !== '' && str_contains($h, $value),
                'equals', 'not_equals'     => $h === $value,
                'starts_with'              => $value !== '' && str_starts_with($h, $value),
                'ends_with'                => $value !== '' && str_ends_with($h, $value),
                default                    => false,
            };
            if ($hit) {
                return !$negative;
            }
        }
        return $negative;
    }

    /** "Name <email>" variants so that users can match on either. */
    private static function addrStrings(array $list): array
    {
        $out = [];
        foreach ($list as $a) {
            if (($a['email'] ?? '') === '') {
                continue;
            }
            $out[] = $a['email'];
            if ($a['name'] !== '') {
                $out[] = $a['name'];
                $out[] = Address::format($a['email'], $a['name']);
            }
        }
        return $out ?: [''];
    }

    /**
     * Compute the actions to perform for a message.
     * @return array{folder:?int,copies:int[],read:bool,flag:bool,discard:bool,redirects:string[],replies:array,matched:int[]}
     */
    public static function evaluate(int $userId, ParsedMessage $msg): array
    {
        $result = ['folder' => null, 'copies' => [], 'read' => false, 'flag' => false, 'discard' => false, 'redirects' => [], 'replies' => [], 'matched' => []];
        foreach (self::forUser($userId, true) as $rule) {
            if (!self::matches($rule, $msg)) {
                continue;
            }
            $result['matched'][] = (int) $rule['id'];
            $stop = (bool) $rule['stop_processing'];
            foreach ($rule['actions'] as $a) {
                switch ($a['type']) {
                    case 'move':
                        $result['folder'] = (int) $a['folder'];
                        break;
                    case 'copy':
                        $result['copies'][] = (int) $a['folder'];
                        break;
                    case 'mark_read':
                        $result['read'] = true;
                        break;
                    case 'flag':
                        $result['flag'] = true;
                        break;
                    case 'trash':
                        $result['folder'] = (int) Folders::byRole($userId, 'trash')['id'];
                        break;
                    case 'discard':
                        $result['discard'] = true;
                        break;
                    case 'redirect':
                        $result['redirects'][] = $a['to'];
                        break;
                    case 'reply':
                        $result['replies'][] = $a;
                        break;
                    case 'stop':
                        $stop = true;
                        break;
                }
            }
            if ($stop) {
                break;
            }
        }
        if ($result['matched']) {
            [$in, $p] = DB::in('r', $result['matched']);
            DB::run("UPDATE rules SET hits = hits + 1 WHERE id IN $in", $p);
        }
        return $result;
    }

    /** Run a rule on existing messages of a folder ("Run now"). Returns affected count. */
    public static function applyToFolder(int $userId, array $rule, int $folderId): int
    {
        $n = 0;
        $rows = DB::all('SELECT * FROM messages WHERE user_id = :u AND folder_id = :f ORDER BY id DESC LIMIT 2000', ['u' => $userId, 'f' => $folderId]);
        foreach ($rows as $row) {
            $parsed = Mailbox::parsed($row);
            if (!self::matches($rule, $parsed)) {
                continue;
            }
            $n++;
            foreach ($rule['actions'] as $a) {
                match ($a['type']) {
                    'move'      => Mailbox::move($userId, [(int) $row['id']], (int) $a['folder']),
                    'mark_read' => Mailbox::setFlags($userId, [(int) $row['id']], ['is_read' => 1]),
                    'flag'      => Mailbox::setFlags($userId, [(int) $row['id']], ['is_flagged' => 1]),
                    'trash'     => Mailbox::delete($userId, [(int) $row['id']]),
                    default     => null,
                };
            }
        }
        return $n;
    }
}
