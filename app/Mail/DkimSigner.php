<?php
declare(strict_types=1);

namespace M4W\Mail;

/**
 * DKIM (RFC 6376) rsa-sha256, relaxed/relaxed canonicalization.
 */
final class DkimSigner
{
    private const SIGNED = ['from', 'to', 'cc', 'subject', 'date', 'message-id', 'reply-to', 'in-reply-to', 'references', 'mime-version', 'content-type'];

    public function __construct(private string $domain, private string $selector, private string $privateKeyPem)
    {
    }

    public function sign(string $message): string
    {
        $message = str_replace(["\r\n", "\r"], "\n", $message);
        $message = str_replace("\n", "\r\n", $message);
        $pos = strpos($message, "\r\n\r\n");
        if ($pos === false) {
            return $message;
        }
        $headerBlock = substr($message, 0, $pos);
        $body = substr($message, $pos + 4);

        $bh = base64_encode(hash('sha256', self::canonBody($body), true));

        $headers = [];
        foreach (preg_split("/\r\n(?![ \t])/", $headerBlock) ?: [] as $h) {
            $colon = strpos($h, ':');
            if ($colon !== false) {
                $headers[] = [strtolower(trim(substr($h, 0, $colon))), $h];
            }
        }
        $signedNames = [];
        $canon = '';
        foreach (self::SIGNED as $name) {
            // Sign the last occurrence (bottom-up rule), single instance is the norm.
            for ($i = count($headers) - 1; $i >= 0; $i--) {
                if ($headers[$i][0] === $name) {
                    $canon .= self::canonHeader($headers[$i][1]) . "\r\n";
                    $signedNames[] = $name;
                    break;
                }
            }
        }
        $dkim = 'DKIM-Signature: v=1; a=rsa-sha256; c=relaxed/relaxed; d=' . $this->domain . '; s=' . $this->selector
            . '; t=' . time() . '; h=' . implode(':', $signedNames) . '; bh=' . $bh . '; b=';
        $canon .= self::canonHeader($dkim);
        $key = openssl_pkey_get_private($this->privateKeyPem);
        if ($key === false || !openssl_sign($canon, $sig, $key, OPENSSL_ALGO_SHA256)) {
            return $message;
        }
        $b = base64_encode($sig);
        $dkim .= rtrim(chunk_split($b, 72, "\r\n\t"), "\r\n\t");
        return $dkim . "\r\n" . $message;
    }

    private static function canonHeader(string $h): string
    {
        $colon = strpos($h, ':');
        $name = strtolower(trim(substr($h, 0, $colon)));
        $value = substr($h, $colon + 1);
        $value = preg_replace("/\r\n[ \t]+/", ' ', $value) ?? $value;
        $value = preg_replace('/[ \t]+/', ' ', $value) ?? $value;
        return $name . ':' . trim($value);
    }

    private static function canonBody(string $body): string
    {
        $lines = explode("\r\n", $body);
        foreach ($lines as &$l) {
            $l = rtrim(preg_replace('/[ \t]+/', ' ', $l) ?? $l, " \t");
        }
        unset($l);
        $body = implode("\r\n", $lines);
        $body = rtrim($body, "\r\n");
        return $body === '' ? '' : $body . "\r\n";
    }

    /** Generate a 2048-bit key pair, returns [privatePem, dnsTxtValue]. */
    public static function generateKeys(): array
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $priv);
        $pub = openssl_pkey_get_details($res)['key'];
        $pub = preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', $pub);
        return [$priv, 'v=DKIM1; k=rsa; p=' . $pub];
    }
}
