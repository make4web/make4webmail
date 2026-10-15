<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Database as DB;
use M4W\Core\Settings;
use M4W\Mail\Address;
use M4W\Mail\LinkCheck;
use M4W\Mail\ParsedMessage;

/**
 * Antispam that learns: word statistics from what people move to Junk ("spam") or out of it
 * ("not spam"), shared by the organisation, combined with Robinson's method and Fisher's
 * chi-square (as SpamBayes does). Words are stored hashed: the table says nothing readable
 * about anyone's mail.
 */
final class Bayes
{
    private const MAX_TOKENS = 1500;
    private const INTERESTING = 25;

    public static function enabled(): bool
    {
        return (int) Settings::get('spam.bayes', 1) === 1;
    }

    /** Messages learnt on each side before the filter gives an opinion. */
    public static function minimum(): int
    {
        return max(1, (int) Settings::get('spam.bayes_min', 30));
    }

    /** @return array{spam:int,ham:int,tokens:int,ready:bool} */
    public static function stats(): array
    {
        $spam = (int) Settings::get('bayes.nspam', 0);
        $ham = (int) Settings::get('bayes.nham', 0);
        return ['spam' => $spam, 'ham' => $ham, 'tokens' => (int) DB::value('SELECT COUNT(*) FROM bayes_tokens'), 'ready' => $spam >= self::minimum() && $ham >= self::minimum()];
    }

    public static function reset(): void
    {
        DB::run('DELETE FROM bayes_tokens');
        DB::run('UPDATE messages SET bayes = 0 WHERE bayes <> 0');
        Settings::setMany(['bayes.nspam' => 0, 'bayes.nham' => 0]);
    }

    /** Distinct hashed tokens of a message. @return string[] */
    public static function tokens(ParsedMessage $p): array
    {
        $out = [];
        $add = static function (string $t) use (&$out): void {
            $out[substr(md5($t), 0, 16)] = true;
        };
        $from = mb_strtolower($p->from()['email'] ?? '');
        $add('from:' . $from);
        $add('fromdom:' . Address::domain($from));
        $add('fromname:' . mb_strtolower(trim((string) ($p->from()['name'] ?? ''))));
        $replyTo = $p->replyTo()[0]['email'] ?? '';
        if ($replyTo !== '' && mb_strtolower($replyTo) !== $from) {
            $add('replyto:' . Address::domain(mb_strtolower($replyTo)));
        }
        foreach (['X-Mailer', 'User-Agent'] as $h) {
            if (($v = $p->header($h)) !== null) {
                $add(strtolower($h) . ':' . mb_strtolower(preg_replace('/[\d.]+/', '', $v) ?? ''));
            }
        }
        foreach (self::words($p->subject()) as $w) {
            $add('subj:' . $w);
        }
        $text = mb_substr($p->text() ?: \M4W\Mail\HtmlToText::convert($p->html()), 0, 30000);
        foreach (self::words($text) as $w) {
            $add($w);
            if (count($out) >= self::MAX_TOKENS) {
                break;
            }
        }
        foreach (array_slice(LinkCheck::links($p->html()), 0, 50) as $l) {
            $add('url:' . LinkCheck::registrable(LinkCheck::host((string) $l['href'])));
        }
        foreach ($p->attachmentList() as $a) {
            $add('att:' . strtolower(pathinfo((string) $a['name'], PATHINFO_EXTENSION)));
        }
        return array_keys($out);
    }

    /** @return string[] */
    private static function words(string $s): array
    {
        $s = mb_strtolower(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        preg_match_all('/[\p{L}][\p{L}\'’-]{2,24}|\d+[,.]?\d*\s?(?:€|\$|%)/u', $s, $m);
        return array_unique(array_map(static fn($w) => trim($w, "'’-"), $m[0]));
    }

    /**
     * Learn a message as spam (true) or not (false). $previous: what it was learnt as before
     * (1 spam, -1 not spam, 0 never): a message changing side is first unlearnt.
     */
    public static function learn(ParsedMessage $p, bool $spam, int $previous = 0): bool
    {
        $target = $spam ? 1 : -1;
        if ($previous === $target) {
            return false;
        }
        $tokens = self::tokens($p);
        DB::transaction(function () use ($tokens, $spam, $previous): void {
            $now = time();
            foreach ($tokens as $t) {
                $row = DB::one('SELECT spam, ham FROM bayes_tokens WHERE token = :t', ['t' => $t]);
                $s = (int) ($row['spam'] ?? 0);
                $h = (int) ($row['ham'] ?? 0);
                if ($previous === 1) {
                    $s = max(0, $s - 1);
                } elseif ($previous === -1) {
                    $h = max(0, $h - 1);
                }
                $spam ? $s++ : $h++;
                if ($row) {
                    DB::update('bayes_tokens', ['spam' => $s, 'ham' => $h, 'last_at' => $now], 'token = :t', ['t' => $t]);
                } else {
                    DB::insert('bayes_tokens', ['token' => $t, 'spam' => $s, 'ham' => $h, 'last_at' => $now]);
                }
            }
        });
        $ns = (int) Settings::get('bayes.nspam', 0);
        $nh = (int) Settings::get('bayes.nham', 0);
        if ($previous === 1) {
            $ns--;
        } elseif ($previous === -1) {
            $nh--;
        }
        $spam ? $ns++ : $nh++;
        Settings::setMany(['bayes.nspam' => max(0, $ns), 'bayes.nham' => max(0, $nh)]);
        return true;
    }

    /** Spam probability 0..1, or null while the filter has not learnt enough. */
    public static function score(ParsedMessage $p): ?float
    {
        $st = self::stats();
        if (!self::enabled() || !$st['ready']) {
            return null;
        }
        $tokens = self::tokens($p);
        $probs = [];
        foreach (array_chunk($tokens, 400) as $chunk) {
            [$in, $params] = DB::in('t', $chunk);
            foreach (DB::all("SELECT spam, ham FROM bayes_tokens WHERE token IN $in", $params) as $r) {
                $s = (int) $r['spam'];
                $h = (int) $r['ham'];
                $n = $s + $h;
                if ($n === 0) {
                    continue;
                }
                $ps = $s / $st['spam'];
                $ph = $h / $st['ham'];
                $p0 = $ps / ($ps + $ph);
                // Robinson: few observations pull towards 0.5 ("don't know").
                $probs[] = (0.45 + $n * $p0) / (0.9 + $n);
            }
        }
        usort($probs, static fn($a, $b) => abs($b - 0.5) <=> abs($a - 0.5));
        $probs = array_values(array_filter(array_slice($probs, 0, self::INTERESTING), static fn($x) => abs($x - 0.5) > 0.1));
        if (count($probs) < 5) {
            return null;
        }
        $lnS = 0.0;
        $lnH = 0.0;
        foreach ($probs as $x) {
            $x = min(0.9999, max(0.0001, $x));
            $lnS += log(1 - $x);
            $lnH += log($x);
        }
        $n = count($probs);
        $sp = 1 - self::chi2Q(-2 * $lnS, 2 * $n);
        $hm = 1 - self::chi2Q(-2 * $lnH, 2 * $n);
        return round((1 + $sp - $hm) / 2, 4);
    }

    /** Probability that a chi-square with $v (even) degrees of freedom exceeds $x2. */
    private static function chi2Q(float $x2, int $v): float
    {
        $m = $x2 / 2;
        $sum = $term = exp(-$m);
        for ($i = 1; $i < intdiv($v, 2); $i++) {
            $term *= $m / $i;
            $sum += $term;
        }
        return min($sum, 1.0);
    }

    /** Spam filter weight for a probability. */
    public static function weight(float $prob): float
    {
        return match (true) {
            $prob >= 0.99 => 5.0,
            $prob >= 0.95 => 3.5,
            $prob >= 0.85 => 2.0,
            $prob <= 0.01 => -3.0,
            $prob <= 0.10 => -1.5,
            default => 0.0,
        };
    }

    /**
     * Feedback from people: moved into Junk = spam, moved out of Junk = not spam.
     * @param array<int,array> $rows message rows (with their previous folder)
     */
    public static function feedback(int $userId, array $rows, bool $spam, int $max = 100): int
    {
        if (!self::enabled()) {
            return 0;
        }
        $n = 0;
        foreach (array_slice($rows, 0, $max) as $row) {
            if ((int) ($row['is_draft'] ?? 0) || (int) ($row['bayes'] ?? 0) === ($spam ? 1 : -1)) {
                continue;
            }
            try {
                $full = DB::one('SELECT * FROM messages WHERE id = :id AND user_id = :u', ['id' => $row['id'], 'u' => $userId]);
                if (!$full) {
                    continue;
                }
                if (self::learn(Mailbox::parsed($full), $spam, (int) $full['bayes'])) {
                    DB::update('messages', ['bayes' => $spam ? 1 : -1], 'id = :id', ['id' => $full['id']]);
                    $n++;
                }
            } catch (\Throwable $e) {
                error_log('[m4w] bayes: ' . $e->getMessage());
            }
        }
        return $n;
    }
}
