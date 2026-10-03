<?php
declare(strict_types=1);

namespace M4W\Mail;

/**
 * Builds RFC 5322 / MIME messages (CRLF line endings).
 */
final class MimeBuilder
{
    public array $from = ['email' => '', 'name' => ''];
    public array $to = [];
    public array $cc = [];
    public array $bcc = [];
    public array $replyTo = [];
    public string $subject = '';
    public string $html = '';
    public string $text = '';
    public string $messageId = '';
    public string $inReplyTo = '';
    public array $references = [];
    public int $priority = 3;
    public bool $readReceipt = false;
    /** @var array<int, array{name:string,mime:string,content:string,cid?:string}> */
    public array $attachments = [];
    /** @var array<int, array{name:string,mime:string,content:string,cid:string}> */
    public array $inline = [];
    /** @var array<string,string> */
    public array $extraHeaders = [];
    public ?int $date = null;

    public static function newMessageId(string $domain): string
    {
        $domain = preg_replace('/[^a-z0-9.-]/i', '', $domain) ?: 'localhost';
        return bin2hex(random_bytes(12)) . '.' . base_convert((string) time(), 10, 36) . '@' . $domain;
    }

    public static function boundary(string $prefix = 'm4w'): string
    {
        return '=_' . $prefix . '_' . bin2hex(random_bytes(12));
    }

    public function build(bool $includeBcc = false): string
    {
        $h = [];
        $h[] = 'Date: ' . date('r', $this->date ?? time());
        $h[] = 'From: ' . Address::encode($this->from['email'], $this->from['name'] ?? '');
        if ($this->to) {
            $h[] = $this->foldAddresses('To', $this->to);
        }
        if ($this->cc) {
            $h[] = $this->foldAddresses('Cc', $this->cc);
        }
        if ($includeBcc && $this->bcc) {
            $h[] = $this->foldAddresses('Bcc', $this->bcc);
        }
        if ($this->replyTo) {
            $h[] = $this->foldAddresses('Reply-To', $this->replyTo);
        }
        $h[] = 'Subject: ' . Charset::encodeHeader($this->subject);
        $h[] = 'Message-ID: <' . $this->messageId . '>';
        if ($this->inReplyTo !== '') {
            $h[] = 'In-Reply-To: <' . $this->inReplyTo . '>';
        }
        if ($this->references) {
            $refs = array_slice(array_unique($this->references), -20);
            $h[] = "References: <" . implode(">\r\n <", $refs) . '>';
        }
        if ($this->priority !== 3) {
            $h[] = 'X-Priority: ' . $this->priority . ($this->priority < 3 ? ' (Highest)' : ' (Lowest)');
            $h[] = 'Importance: ' . ($this->priority < 3 ? 'High' : 'Low');
        }
        if ($this->readReceipt) {
            $h[] = 'Disposition-Notification-To: ' . Address::encode($this->from['email'], $this->from['name'] ?? '');
        }
        foreach ($this->extraHeaders as $k => $v) {
            $h[] = $k . ': ' . str_replace(["\r", "\n"], '', $v);
        }
        $h[] = 'MIME-Version: 1.0';
        $h[] = 'X-Mailer: Make4Web Mail ' . (defined('M4W_VERSION') ? M4W_VERSION : '');

        [$ctype, $body] = $this->buildBody();
        $h[] = $ctype;

        return implode("\r\n", $h) . "\r\n\r\n" . $body;
    }

    /** @return array{0:string,1:string} [Content-Type header lines, body] */
    private function buildBody(): array
    {
        $text = $this->text !== '' ? $this->text : HtmlToText::convert($this->html);
        $alt = null;
        if ($this->html !== '') {
            $b = self::boundary('alt');
            $alt = [
                "Content-Type: multipart/alternative;\r\n boundary=\"$b\"",
                "--$b\r\n" . $this->textPart($text, 'text/plain')
                . "\r\n--$b\r\n" . $this->textPart($this->wrapHtml($this->html), 'text/html')
                . "\r\n--$b--\r\n",
            ];
        } else {
            $part = $this->textPart($text, 'text/plain');
            [$hdr, $bdy] = explode("\r\n\r\n", $part, 2);
            $alt = [$hdr, $bdy];
        }

        $current = $alt;
        if ($this->inline && $this->html !== '') {
            $b = self::boundary('rel');
            $body = "--$b\r\n" . $current[0] . "\r\n\r\n" . $current[1];
            foreach ($this->inline as $img) {
                $body .= "\r\n--$b\r\n" . $this->binaryPart($img['content'], $img['mime'], $img['name'], 'inline', $img['cid']);
            }
            $body .= "\r\n--$b--\r\n";
            $current = ["Content-Type: multipart/related;\r\n type=\"multipart/alternative\";\r\n boundary=\"$b\"", $body];
        }
        if ($this->attachments) {
            $b = self::boundary('mix');
            $body = "This is a multi-part message in MIME format.\r\n\r\n--$b\r\n" . $current[0] . "\r\n\r\n" . $current[1];
            foreach ($this->attachments as $att) {
                $body .= "\r\n--$b\r\n";
                if (($att['mime'] ?? '') === 'message/rfc822') {
                    $body .= 'Content-Type: message/rfc822; name="' . $this->paramValue($att['name']) . "\"\r\n"
                        . 'Content-Disposition: attachment; ' . $this->filenameParam($att['name']) . "\r\n\r\n"
                        . str_replace(["\r\n", "\n"], ["\n", "\r\n"], $att['content']);
                } else {
                    $body .= $this->binaryPart($att['content'], $att['mime'] ?: 'application/octet-stream', $att['name'], 'attachment', $att['cid'] ?? '');
                }
            }
            $body .= "\r\n--$b--\r\n";
            $current = ["Content-Type: multipart/mixed;\r\n boundary=\"$b\"", $body];
        }
        return $current;
    }

    private function wrapHtml(string $html): string
    {
        if (stripos($html, '<html') !== false) {
            return $html;
        }
        return "<!DOCTYPE html>\n<html><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width\"></head>"
            . "<body style=\"font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#1f2937\">"
            . $html . "</body></html>";
    }

    private function textPart(string $content, string $type): string
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $encoded = quoted_printable_encode(str_replace("\n", "\r\n", $content));
        return "Content-Type: $type; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . $encoded;
    }

    private function binaryPart(string $content, string $mime, string $name, string $disposition, string $cid = ''): string
    {
        $mime = preg_replace('#[^\w.+/-]#', '', $mime) ?: 'application/octet-stream';
        $out = "Content-Type: $mime; name=\"" . $this->paramValue($name) . "\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "Content-Disposition: $disposition; " . $this->filenameParam($name) . "\r\n";
        if ($cid !== '') {
            $out .= "Content-ID: <$cid>\r\n";
        }
        return $out . "\r\n" . rtrim(chunk_split(base64_encode($content), 76, "\r\n"));
    }

    private function paramValue(string $name): string
    {
        $name = str_replace(['"', "\r", "\n", '\\'], '', $name);
        return preg_match('/[^\x20-\x7e]/', $name) ? Charset::encodeHeader($name) : $name;
    }

    private function filenameParam(string $name): string
    {
        $name = str_replace(['"', "\r", "\n", '\\'], '', $name);
        if (!preg_match('/[^\x20-\x7e]/', $name)) {
            return 'filename="' . $name . '"';
        }
        $ascii = preg_replace('/[^\x20-\x7e]/', '_', $name);
        return "filename=\"$ascii\";\r\n filename*=UTF-8''" . rawurlencode($name);
    }

    private function foldAddresses(string $header, array $list): string
    {
        $items = array_map(static fn($a) => Address::encode($a['email'], $a['name'] ?? ''), $list);
        return $header . ': ' . implode(",\r\n ", $items);
    }

    /** Envelope recipients (To + Cc + Bcc), deduplicated. */
    public function envelopeRecipients(): array
    {
        $all = [];
        foreach ([$this->to, $this->cc, $this->bcc] as $list) {
            foreach ($list as $a) {
                $all[mb_strtolower($a['email'])] = $a['email'];
            }
        }
        return array_values($all);
    }
}
