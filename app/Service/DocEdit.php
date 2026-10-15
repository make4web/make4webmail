<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Config;
use M4W\Core\Database as DB;
use M4W\Mail\HtmlSanitizer;

/**
 * Built-in word processor: one person writes at a time, everyone else follows live.
 *
 * The document is turned into HTML once (WordDoc for .docx, PHPWord for .odt and .doc)
 * and kept on the server as the working copy (storage/data/docs). The writer holds the
 * pen and sends drafts as they type; the others receive each new revision. Saving makes
 * a new version of the .docx with only its body rewritten, or a copy when the original
 * holds what the editor cannot keep (tracked changes, comments, text boxes…), when it
 * is an .odt or a .doc, or when another version was saved meanwhile.
 */
final class DocEdit
{
    public const EXT = ['docx', 'odt', 'doc'];
    public const MAX_BYTES = 20 * 1048576;
    private const MAX_HTML = 25 * 1048576;
    private const PEN_TTL = 45;

    public static function supports(string $name): bool
    {
        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::EXT, true);
    }

    private static function dir(): string
    {
        $d = Config::storagePath('data/docs');
        if (!is_dir($d)) {
            mkdir($d, 0750, true);
        }
        return $d;
    }

    private static function htmlPath(int $fid): string
    {
        return self::dir() . '/' . $fid . '.html';
    }

    private static function srcPath(int $fid): string
    {
        return self::dir() . '/' . $fid . '.src';
    }

    /** @return resource */
    private static function lock(int $fid)
    {
        $h = fopen(self::dir() . '/' . $fid . '.lock', 'c');
        flock($h, LOCK_EX);
        return $h;
    }

    private static function unlock($h): void
    {
        flock($h, LOCK_UN);
        fclose($h);
    }

    /** Same filter as received mail, pictures embedded in the document allowed. */
    public static function clean(string $html): string
    {
        return (new HtmlSanitizer(false, null, false))->sanitize($html);
    }

    // ---------------------------------------------------------------- conversion

    /** @return array{html:string,issues:string[]} */
    private static function import(string $path, string $ext): array
    {
        if ($ext === 'docx') {
            return WordDoc::toHtml($path);
        }
        try {
            $word = \PhpOffice\PhpWord\IOFactory::load($path, $ext === 'odt' ? 'ODText' : 'MsDoc');
            $html = (new \PhpOffice\PhpWord\Writer\HTML($word))->getContent();
        } catch (\Throwable) {
            throw new \InvalidArgumentException(t('doc.unreadable'));
        }
        $body = preg_match('#<body[^>]*>(.*)</body>#is', $html, $m) ? $m[1] : $html;
        return ['html' => $body, 'issues' => [$ext === 'odt' ? 'odt' : 'legacy']];
    }

    // ---------------------------------------------------------------- session

    public static function open(array $user, array $file): array
    {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::EXT, true)) {
            throw new \InvalidArgumentException(t('doc.unsupported'));
        }
        if ((int) $file['size'] > self::MAX_BYTES) {
            throw new \InvalidArgumentException(t('doc.too_big'));
        }
        $fid = (int) $file['id'];
        $lock = self::lock($fid);
        try {
            $s = DB::one('SELECT * FROM doc_sessions WHERE file_id = :f', ['f' => $fid]);
            if (!$s || !is_file(self::htmlPath($fid)) || (!(int) $s['dirty'] && (int) $s['base_version'] !== (int) $file['version'])) {
                $s = self::start($file, $ext);
            }
            $html = (string) file_get_contents(self::htmlPath($fid));
        } finally {
            self::unlock($lock);
        }
        $canWrite = Files::filePermission($user, $file) >= Files::WRITE;
        return ['html' => $html, 'rev' => (int) $s['rev'], 'mode' => $s['mode'], 'issues' => array_values(array_filter(explode(',', (string) $s['reason']))),
            'can_write' => $canWrite, 'pen' => self::pen($s, (int) $user['id']), 'dirty' => (bool) (int) $s['dirty'], 'ext' => $ext,
            'file' => Files::fileOut($file)];
    }

    private static function start(array $file, string $ext): array
    {
        $fid = (int) $file['id'];
        $src = self::srcPath($fid);
        $out = fopen($src, 'wb');
        Files::stream((string) $file['blob_id'], static function (string $buf) use ($out) {
            fwrite($out, $buf);
        });
        fclose($out);
        $r = self::import($src, $ext);
        file_put_contents(self::htmlPath($fid), self::clean($r['html']));
        DB::run('DELETE FROM doc_sessions WHERE file_id = :f', ['f' => $fid]);
        $row = ['file_id' => $fid, 'base_version' => (int) $file['version'], 'mode' => $r['issues'] ? 'copy' : 'edit', 'reason' => implode(',', $r['issues']),
            'holder' => null, 'holder_seen' => 0, 'rev' => 1, 'dirty' => 0, 'last_user' => null, 'updated_at' => time(), 'published_at' => 0];
        DB::insert('doc_sessions', $row);
        return $row;
    }

    /** Who holds the pen, if anyone (a silent holder loses it after 45 seconds). */
    private static function pen(array $s, int $me): ?array
    {
        $h = (int) ($s['holder'] ?? 0);
        if (!$h || (int) $s['holder_seen'] < time() - self::PEN_TTL) {
            return null;
        }
        $u = DB::one('SELECT display_name, email FROM users WHERE id = :u', ['u' => $h]);
        return ['id' => $h, 'me' => $h === $me, 'name' => (string) ($u['display_name'] ?? '') ?: (string) ($u['email'] ?? ''), 'color' => Sheet::color($h)];
    }

    /** Take the pen when it is free (or already ours). */
    public static function take(array $user, array $file): array
    {
        $fid = (int) $file['id'];
        $lock = self::lock($fid);
        try {
            $s = DB::one('SELECT * FROM doc_sessions WHERE file_id = :f', ['f' => $fid]);
            if (!$s) {
                throw new \InvalidArgumentException(t('doc.session_gone'));
            }
            $pen = self::pen($s, (int) $user['id']);
            if ($pen && !$pen['me']) {
                return ['ok' => false, 'pen' => $pen];
            }
            DB::update('doc_sessions', ['holder' => (int) $user['id'], 'holder_seen' => time()], 'file_id = :f', ['f' => $fid]);
            return ['ok' => true, 'pen' => self::pen(DB::one('SELECT * FROM doc_sessions WHERE file_id = :f', ['f' => $fid]), (int) $user['id']), 'rev' => (int) $s['rev']];
        } finally {
            self::unlock($lock);
        }
    }

    public static function release(array $user, int $fid): void
    {
        DB::run('UPDATE doc_sessions SET holder = NULL, holder_seen = 0 WHERE file_id = :f AND holder = :u', ['f' => $fid, 'u' => $user['id']]);
    }

    /** The writer's latest text. @return int new revision */
    public static function draft(array $user, array $file, string $html): int
    {
        if (strlen($html) > self::MAX_HTML) {
            throw new \InvalidArgumentException(t('doc.too_big'));
        }
        $fid = (int) $file['id'];
        $lock = self::lock($fid);
        try {
            $s = DB::one('SELECT * FROM doc_sessions WHERE file_id = :f', ['f' => $fid]);
            if (!$s) {
                throw new \InvalidArgumentException(t('doc.session_gone'));
            }
            $pen = self::pen($s, (int) $user['id']);
            if (!$pen || !$pen['me']) {
                throw new \DomainException(t('doc.pen_lost'));
            }
            file_put_contents(self::htmlPath($fid) . '.tmp', self::clean($html));
            rename(self::htmlPath($fid) . '.tmp', self::htmlPath($fid));
            $rev = (int) $s['rev'] + 1;
            DB::update('doc_sessions', ['rev' => $rev, 'dirty' => 1, 'holder_seen' => time(), 'last_user' => (int) $user['id'], 'updated_at' => time()], 'file_id = :f', ['f' => $fid]);
            return $rev;
        } finally {
            self::unlock($lock);
        }
    }

    public static function poll(array $user, array $file, int $rev): array
    {
        $fid = (int) $file['id'];
        DB::run('DELETE FROM sheet_presence WHERE file_id = :f AND user_id = :u', ['f' => $fid, 'u' => $user['id']]);
        DB::insert('sheet_presence', ['file_id' => $fid, 'user_id' => (int) $user['id'], 'sheet' => 0, 'cell' => '', 'seen_at' => time()]);
        $s = DB::one('SELECT * FROM doc_sessions WHERE file_id = :f', ['f' => $fid]);
        if (!$s) {
            return ['gone' => true];
        }
        if ((int) $s['holder'] === (int) $user['id']) {
            DB::update('doc_sessions', ['holder_seen' => time()], 'file_id = :f', ['f' => $fid]);
            $s['holder_seen'] = time();
        }
        $out = ['rev' => (int) $s['rev'], 'pen' => self::pen($s, (int) $user['id']), 'dirty' => (bool) (int) $s['dirty'],
            'published_at' => (int) $s['published_at'], 'people' => Sheet::people($fid, (int) $user['id'])];
        if ((int) $s['rev'] > $rev) {
            $out['html'] = (string) @file_get_contents(self::htmlPath($fid));
        }
        return $out;
    }

    // ---------------------------------------------------------------- publishing

    /** @return array{file:array,copy:bool} */
    public static function publish(array $user, array $file): array
    {
        $fid = (int) $file['id'];
        $lock = self::lock($fid);
        try {
            $s = DB::one('SELECT * FROM doc_sessions WHERE file_id = :f', ['f' => $fid]);
            if (!$s || !is_file(self::htmlPath($fid))) {
                throw new \InvalidArgumentException(t('doc.session_gone'));
            }
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $changedMeanwhile = (int) $file['version'] !== (int) $s['base_version'];
            $copy = $s['mode'] === 'copy' || $changedMeanwhile;
            $html = (string) file_get_contents(self::htmlPath($fid));
            $tmp = (string) tempnam(Config::storagePath('tmp'), 'doc');
            $outExt = $ext === 'odt' ? 'odt' : 'docx';
            if ($outExt === 'odt') {
                $word = new \PhpOffice\PhpWord\PhpWord();
                \PhpOffice\PhpWord\Shared\Html::addHtml($word->addSection(), self::forPhpWord($html), false, false);
                \PhpOffice\PhpWord\IOFactory::createWriter($word, 'ODText')->save($tmp);
            } else {
                // A .docx keeps its own package; a .doc becomes a new .docx.
                WordDoc::fromHtml($html, $ext === 'docx' && is_file(self::srcPath($fid)) ? self::srcPath($fid) : null, $tmp);
            }
            try {
                if ($copy) {
                    $base = pathinfo($file['name'], PATHINFO_FILENAME);
                    $name = t($changedMeanwhile ? 'sheet.copy_conflict_name' : 'sheet.copy_name', ['name' => $base, 'user' => $user['name'] ?? '']) . '.' . $outExt;
                    $saved = Files::addFromPath($user, (int) $file['folder_id'], $tmp, $name);
                } else {
                    $saved = Files::putContent($user, $fid, $tmp);
                    // The new version is the package later saves start from.
                    copy($tmp, self::srcPath($fid));
                }
            } finally {
                @unlink($tmp);
            }
            DB::update('doc_sessions', ['dirty' => 0, 'published_at' => time()] + ($copy ? [] : ['base_version' => (int) $saved['version']]), 'file_id = :f', ['f' => $fid]);
        } finally {
            self::unlock($lock);
        }
        return ['file' => $saved, 'copy' => $copy];
    }

    /** PHPWord's HTML reader wants well-formed, simple markup. */
    private static function forPhpWord(string $html): string
    {
        $html = preg_replace('#<img[^>]+src="(?!data:)[^"]*"[^>]*>#i', '', $html) ?? $html;
        $d = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $d->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $out = '';
        foreach ($d->getElementsByTagName('body')->item(0)?->childNodes ?? [] as $c) {
            $out .= $d->saveXML($c);
        }
        return $out;
    }

    /** Scheduler: a document whose writer left is saved; finished sessions are cleaned up. */
    public static function housekeeping(): int
    {
        $n = 0;
        $q = time() - 120;
        foreach (DB::all('SELECT * FROM doc_sessions s WHERE s.dirty = 1 AND s.updated_at < :q AND s.holder_seen < :q AND NOT EXISTS (SELECT 1 FROM sheet_presence p WHERE p.file_id = s.file_id AND p.seen_at > :q)',
            ['q' => $q]) as $s) {
            $file = Files::file((int) $s['file_id']);
            $user = $s['last_user'] ? Users::find((int) $s['last_user']) : null;
            if (!$file || !$user || (int) $file['deleted_at'] > 0) {
                continue;
            }
            try {
                self::publish($user, $file);
                $n++;
            } catch (\Throwable $e) {
                error_log('[m4w] document autosave ' . $s['file_id'] . ': ' . $e->getMessage());
            }
        }
        foreach (DB::all('SELECT file_id FROM doc_sessions WHERE dirty = 0 AND updated_at < :t AND published_at < :t', ['t' => time() - 86400]) as $s) {
            $fid = (int) $s['file_id'];
            DB::run('DELETE FROM doc_sessions WHERE file_id = :f', ['f' => $fid]);
            foreach ([self::htmlPath($fid), self::srcPath($fid), self::dir() . '/' . $fid . '.lock'] as $p) {
                @unlink($p);
            }
        }
        return $n;
    }
}
