<?php
declare(strict_types=1);

namespace M4W\Mail;

final class MimePart
{
    /** @var array<int, array{0:string,1:string}> raw unfolded headers [name, value] */
    public array $headers = [];
    public string $body = '';
    /** @var MimePart[] */
    public array $children = [];
    public string $id = '0';
    public string $type = 'text/plain';
    public array $typeParams = [];
    public string $disposition = '';
    public array $dispositionParams = [];

    public function header(string $name): ?string
    {
        foreach ($this->headers as [$n, $v]) {
            if (strcasecmp($n, $name) === 0) {
                return $v;
            }
        }
        return null;
    }

    public function headerAll(string $name): array
    {
        $out = [];
        foreach ($this->headers as [$n, $v]) {
            if (strcasecmp($n, $name) === 0) {
                $out[] = $v;
            }
        }
        return $out;
    }

    public function isMultipart(): bool
    {
        return str_starts_with($this->type, 'multipart/');
    }

    public function charset(): string
    {
        return (string) ($this->typeParams['charset'] ?? '');
    }

    public function filename(): string
    {
        $name = $this->dispositionParams['filename'] ?? $this->typeParams['name'] ?? '';
        $name = Charset::decodeHeader((string) $name);
        $name = str_replace(["\0", '/', '\\', "\r", "\n"], ['', '_', '_', '', ''], $name);
        return trim($name);
    }

    public function contentId(): string
    {
        return trim((string) $this->header('Content-ID'), " <>\t");
    }

    public function decodedBody(): string
    {
        $enc = strtolower(trim((string) $this->header('Content-Transfer-Encoding')));
        return match ($enc) {
            'base64'           => (string) base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $this->body) ?? ''),
            'quoted-printable' => quoted_printable_decode(preg_replace("/=\r?\n/", '', $this->body) ?? $this->body),
            default            => $this->body,
        };
    }

    public function text(): string
    {
        return Charset::toUtf8($this->decodedBody(), $this->charset());
    }

    public function isAttachment(): bool
    {
        if ($this->disposition === 'attachment') {
            return true;
        }
        if ($this->isMultipart()) {
            return false;
        }
        if (in_array($this->type, ['text/plain', 'text/html'], true)) {
            return $this->disposition === 'attachment' || ($this->filename() !== '' && $this->disposition !== 'inline');
        }
        return true;
    }
}
