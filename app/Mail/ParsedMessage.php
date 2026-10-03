<?php
declare(strict_types=1);

namespace M4W\Mail;

final class ParsedMessage
{
    private ?string $text = null;
    private ?string $html = null;
    /** @var MimePart[]|null */
    private ?array $attachments = null;
    /** @var array<string, MimePart> */
    private array $inline = [];

    public function __construct(public readonly MimePart $root, public readonly int $size)
    {
    }

    public function header(string $name): ?string
    {
        return $this->root->header($name);
    }

    public function headerAll(string $name): array
    {
        return $this->root->headerAll($name);
    }

    public function headers(): array
    {
        return $this->root->headers;
    }

    public function subject(): string
    {
        return trim(Charset::decodeHeader((string) $this->header('Subject')));
    }

    /** @return array{name:string,email:string} */
    public function from(): array
    {
        return Address::parseList((string) $this->header('From'))[0] ?? ['name' => '', 'email' => ''];
    }

    public function to(): array
    {
        return Address::parseList(implode(',', $this->headerAll('To')));
    }

    public function cc(): array
    {
        return Address::parseList(implode(',', $this->headerAll('Cc')));
    }

    public function bcc(): array
    {
        return Address::parseList(implode(',', $this->headerAll('Bcc')));
    }

    public function replyTo(): array
    {
        return Address::parseList((string) $this->header('Reply-To'));
    }

    public function date(): int
    {
        $d = (string) $this->header('Date');
        $d = preg_replace('/\s*\([^)]*\)\s*$/', '', $d) ?? $d;
        $ts = $d !== '' ? strtotime($d) : false;
        return $ts === false ? 0 : $ts;
    }

    public function messageId(): string
    {
        return self::cleanId((string) $this->header('Message-ID'));
    }

    public function inReplyTo(): string
    {
        return self::cleanId((string) $this->header('In-Reply-To'));
    }

    public function references(): array
    {
        preg_match_all('/<([^<>\s]+)>/', (string) $this->header('References'), $m);
        return $m[1];
    }

    public static function cleanId(string $id): string
    {
        if (preg_match('/<([^<>\s]+)>/', $id, $m)) {
            return mb_substr($m[1], 0, 250);
        }
        return mb_substr(trim($id), 0, 250);
    }

    /** 1 = highest, 3 = normal, 5 = lowest */
    public function priority(): int
    {
        $x = (string) $this->header('X-Priority');
        if (preg_match('/^\s*(\d)/', $x, $m)) {
            return max(1, min(5, (int) $m[1]));
        }
        $imp = strtolower((string) ($this->header('Importance') ?? $this->header('Priority') ?? ''));
        return match (true) {
            str_contains($imp, 'high'), str_contains($imp, 'urgent') => 1,
            str_contains($imp, 'low'), str_contains($imp, 'non-urgent') => 5,
            default => 3,
        };
    }

    public function isAutomated(): bool
    {
        $auto = strtolower((string) $this->header('Auto-Submitted'));
        if ($auto !== '' && $auto !== 'no') {
            return true;
        }
        $prec = strtolower((string) $this->header('Precedence'));
        if (in_array($prec, ['bulk', 'list', 'junk', 'auto_reply'], true)) {
            return true;
        }
        if ($this->header('List-Id') !== null || $this->header('List-Unsubscribe') !== null
            || $this->header('X-Auto-Response-Suppress') !== null || $this->header('X-Autoreply') !== null) {
            return true;
        }
        $from = strtolower($this->from()['email']);
        return (bool) preg_match('/^(no-?reply|do-?not-?reply|mailer-daemon|postmaster|bounce|bounces|notifications?)[@+.-]/', $from);
    }

    public function isSpamFlagged(): bool
    {
        $flag = strtolower((string) $this->header('X-Spam-Flag'));
        $status = strtolower((string) $this->header('X-Spam-Status'));
        return $flag === 'yes' || str_starts_with($status, 'yes');
    }

    private function walk(): void
    {
        if ($this->attachments !== null) {
            return;
        }
        $this->attachments = [];
        $texts = [];
        $htmls = [];
        $this->collect($this->root, $texts, $htmls, false);
        $this->text = implode("\n\n", $texts);
        $this->html = implode("<hr>", $htmls);
    }

    private function collect(MimePart $p, array &$texts, array &$htmls, bool $inAlternative): void
    {
        if ($p->type === 'multipart/alternative') {
            // Pick best representation of each kind.
            $best = ['text' => null, 'html' => null];
            foreach ($p->children as $c) {
                if ($c->isMultipart()) {
                    $t = [];
                    $h = [];
                    $this->collect($c, $t, $h, true);
                    if ($h) {
                        $best['html'] = implode('<hr>', $h);
                    }
                    if ($t && !$best['text']) {
                        $best['text'] = implode("\n\n", $t);
                    }
                } elseif ($c->type === 'text/html' && !$c->isAttachment()) {
                    $best['html'] = $c->text();
                } elseif ($c->type === 'text/plain' && !$c->isAttachment()) {
                    $best['text'] = $c->text();
                } else {
                    $this->addAttachment($c);
                }
            }
            if ($best['text'] !== null) {
                $texts[] = $best['text'];
            }
            if ($best['html'] !== null) {
                $htmls[] = $best['html'];
            } elseif ($best['text'] !== null) {
                $htmls[] = self::textToHtml($best['text']);
            }
            return;
        }
        if ($p->isMultipart()) {
            foreach ($p->children as $c) {
                $this->collect($c, $texts, $htmls, $inAlternative);
            }
            return;
        }
        if (!$p->isAttachment() && ($p->type === 'text/plain' || $p->type === 'text/html')) {
            $content = $p->text();
            if ($p->type === 'text/html') {
                $htmls[] = $content;
                if (!$texts) {
                    $texts[] = HtmlToText::convert($content);
                }
            } else {
                $texts[] = $content;
                $htmls[] = self::textToHtml($content);
            }
            return;
        }
        $this->addAttachment($p);
    }

    private function addAttachment(MimePart $p): void
    {
        $cid = $p->contentId();
        if ($cid !== '') {
            $this->inline[$cid] = $p;
        }
        $this->attachments[] = $p;
    }

    public function text(): string
    {
        $this->walk();
        return (string) $this->text;
    }

    public function html(): string
    {
        $this->walk();
        return (string) $this->html;
    }

    /** @return MimePart[] */
    public function attachmentParts(): array
    {
        $this->walk();
        return $this->attachments;
    }

    /** Attachment metadata for listing (excludes inline images referenced from HTML). */
    public function attachmentList(): array
    {
        $html = $this->html();
        $out = [];
        foreach ($this->attachmentParts() as $p) {
            $cid = $p->contentId();
            $inlineUsed = $cid !== '' && str_contains($html, 'cid:' . $cid);
            $name = $p->filename();
            if ($name === '') {
                $name = match (true) {
                    $p->type === 'message/rfc822' => 'message.eml',
                    str_starts_with($p->type, 'image/') => 'image.' . substr($p->type, 6),
                    $p->type === 'text/calendar' => 'invite.ics',
                    default => 'attachment.bin',
                };
            }
            $out[] = [
                'part'   => $p->id,
                'name'   => $name,
                'mime'   => $p->type,
                'size'   => strlen($p->decodedBody()),
                'cid'    => $cid,
                'inline' => $inlineUsed,
            ];
        }
        return $out;
    }

    public function findPart(string $id, ?MimePart $node = null): ?MimePart
    {
        $node ??= $this->root;
        if ($node->id === $id) {
            return $node;
        }
        foreach ($node->children as $c) {
            $r = $this->findPart($id, $c);
            if ($r) {
                return $r;
            }
        }
        return null;
    }

    public function findByCid(string $cid): ?MimePart
    {
        $this->walk();
        return $this->inline[$cid] ?? null;
    }

    public function snippet(int $len = 200): string
    {
        $t = $this->text();
        // Drop quoted lines and signatures for a cleaner preview.
        $lines = array_filter(explode("\n", $t), static fn($l) => !str_starts_with(ltrim($l), '>'));
        $t = preg_replace('/\s+/u', ' ', implode(' ', $lines)) ?? '';
        return mb_substr(trim($t), 0, $len);
    }

    public static function textToHtml(string $text): string
    {
        $html = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = preg_replace_callback(
            '~\b(https?://[^\s<>"\']+[^\s<>"\'.,;:!?)\]])~i',
            static fn($m) => '<a href="' . $m[1] . '">' . $m[1] . '</a>',
            $html
        ) ?? $html;
        return '<div class="m4w-plain" style="white-space:pre-wrap;font-family:inherit">' . $html . '</div>';
    }
}
