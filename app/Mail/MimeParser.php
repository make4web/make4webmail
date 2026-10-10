<?php
declare(strict_types=1);

namespace M4W\Mail;

/**
 * Robust MIME parser (RFC 2045-2049, 2047, 2231) with no extension dependency.
 */
final class MimeParser
{
    private const MAX_DEPTH = 20;

    public static function parse(string $raw): ParsedMessage
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $root = self::parsePart($raw, '0', 0);
        return new ParsedMessage($root, strlen($raw));
    }

    public static function parsePart(string $raw, string $id, int $depth): MimePart
    {
        $part = new MimePart();
        $part->id = $id;
        [$headerBlock, $body] = self::splitHeaderBody($raw);
        $part->headers = self::parseHeaders($headerBlock);
        $part->body = $body;

        [$type, $params] = self::parseParamHeader((string) ($part->header('Content-Type') ?? 'text/plain'));
        $part->type = strtolower($type) ?: 'text/plain';
        if (!str_contains($part->type, '/')) {
            $part->type = 'text/plain';
        }
        $part->typeParams = $params;
        $disp = $part->header('Content-Disposition');
        if ($disp !== null) {
            [$d, $dp] = self::parseParamHeader($disp);
            $part->disposition = strtolower($d);
            $part->dispositionParams = $dp;
        }

        if ($part->isMultipart() && $depth < self::MAX_DEPTH) {
            $boundary = (string) ($params['boundary'] ?? '');
            if ($boundary !== '') {
                $i = 1;
                foreach (self::splitMultipart($body, $boundary) as $chunk) {
                    $part->children[] = self::parsePart($chunk, $id === '0' ? (string) $i : $id . '.' . $i, $depth + 1);
                    $i++;
                }
            }
        }
        return $part;
    }

    /** @return array{0:string,1:string} */
    private static function splitHeaderBody(string $raw): array
    {
        if (str_starts_with($raw, "\n")) {
            return ['', substr($raw, 1)];
        }
        $pos = strpos($raw, "\n\n");
        if ($pos === false) {
            // Headers only, or body only.
            return preg_match('/^[\x21-\x39\x3b-\x7e]+:/', $raw) ? [$raw, ''] : ['', $raw];
        }
        return [substr($raw, 0, $pos), substr($raw, $pos + 2)];
    }

    /** @return array<int, array{0:string,1:string}> */
    public static function parseHeaders(string $block): array
    {
        $headers = [];
        $current = null;
        foreach (explode("\n", $block) as $line) {
            if ($line === '') {
                continue;
            }
            if (($line[0] === ' ' || $line[0] === "\t") && $current !== null) {
                $headers[$current][1] .= ' ' . ltrim($line);
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0) {
                continue;
            }
            $headers[] = [trim(substr($line, 0, $colon)), trim(substr($line, $colon + 1))];
            $current = count($headers) - 1;
        }
        return $headers;
    }

    /** Split multipart body into raw parts. */
    private static function splitMultipart(string $body, string $boundary): array
    {
        $delim = '--' . $boundary;
        $parts = [];
        $lines = explode("\n", $body);
        $buf = null;
        foreach ($lines as $line) {
            $trim = rtrim($line, " \t\r");
            if ($trim === $delim . '--') {
                if ($buf !== null) {
                    $parts[] = implode("\n", $buf);
                }
                $buf = null;
                break;
            }
            if ($trim === $delim) {
                if ($buf !== null) {
                    $parts[] = implode("\n", $buf);
                }
                $buf = [];
                continue;
            }
            if ($buf !== null) {
                $buf[] = $line;
            }
        }
        if ($buf !== null && $buf !== []) {
            $parts[] = implode("\n", $buf);
        }
        return $parts;
    }

    /**
     * Parse "value; a=b; c*=utf-8''x; d*0=..." into [value, params] (RFC 2231 aware).
     * @return array{0:string,1:array<string,string>}
     */
    public static function parseParamHeader(string $value): array
    {
        $tokens = [];
        $buf = '';
        $inQuote = false;
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $c = $value[$i];
            if ($c === '\\' && $inQuote && $i + 1 < $len) {
                $buf .= $value[++$i];
                continue;
            }
            if ($c === '"') {
                $inQuote = !$inQuote;
                $buf .= $c;
                continue;
            }
            if ($c === ';' && !$inQuote) {
                $tokens[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        $tokens[] = $buf;
        $main = trim((string) array_shift($tokens));
        $params = [];
        $continuations = [];
        foreach ($tokens as $tok) {
            $eq = strpos($tok, '=');
            if ($eq === false) {
                continue;
            }
            $name = strtolower(trim(substr($tok, 0, $eq)));
            $val = trim(substr($tok, $eq + 1));
            if (strlen($val) >= 2 && $val[0] === '"' && str_ends_with($val, '"')) {
                $val = substr($val, 1, -1);
            }
            if (preg_match('/^([a-z0-9_.-]+)\*(\d+)(\*?)$/', $name, $m)) {
                $continuations[$m[1]][(int) $m[2]] = [$val, $m[3] === '*'];
            } elseif (str_ends_with($name, '*')) {
                $params[substr($name, 0, -1)] = self::decode2231($val, true);
            } else {
                $params[$name] = $val;
            }
        }
        foreach ($continuations as $name => $pieces) {
            ksort($pieces);
            $charset = '';
            $joined = '';
            $first = true;
            foreach ($pieces as [$v, $encoded]) {
                if ($encoded) {
                    if ($first && preg_match("/^([^']*)'[^']*'(.*)$/s", $v, $m)) {
                        $charset = $m[1];
                        $v = $m[2];
                    }
                    $joined .= rawurldecode($v);
                } else {
                    $joined .= $v;
                }
                $first = false;
            }
            $params[$name] = $charset !== '' ? Charset::toUtf8($joined, $charset) : $joined;
        }
        return [$main, $params];
    }

    private static function decode2231(string $v, bool $withCharset): string
    {
        if ($withCharset && preg_match("/^([^']*)'[^']*'(.*)$/s", $v, $m)) {
            return Charset::toUtf8(rawurldecode($m[2]), $m[1] ?: 'UTF-8');
        }
        return rawurldecode($v);
    }
}
