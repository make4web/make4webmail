<?php
declare(strict_types=1);

namespace M4W\Service;

/**
 * In-app preview of office documents, without any external service: Word (.docx),
 * Excel (.xlsx), PowerPoint (.pptx), OpenDocument text (.odt), CSV and plain text
 * formats are turned into a self-contained HTML page.
 *
 * Everything written to the page is escaped here; embedded pictures become data: URIs
 * (PNG, JPEG, GIF only). Archives are opened with size guards against zip bombs.
 */
final class DocPreview
{
    private const MAX_FILE = 60 * 1024 * 1024;     // larger files are downloaded instead
    private const MAX_PART = 40 * 1024 * 1024;     // uncompressed size of one XML part
    private const MAX_IMAGE = 3 * 1024 * 1024;
    private const MAX_IMAGES_TOTAL = 20 * 1024 * 1024;
    private const MAX_ROWS = 2000;
    private const MAX_COLS = 60;

    private const TEXT = ['txt', 'log', 'md', 'json', 'xml', 'ini', 'conf', 'yml', 'yaml', 'sql', 'eml'];

    private int $imageBytes = 0;

    public static function supports(string $name): bool
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return in_array($ext, ['docx', 'xlsx', 'pptx', 'odt', 'csv', 'tsv'], true) || in_array($ext, self::TEXT, true);
    }

    /** @return string complete HTML page */
    public static function render(string $path, string $name): string
    {
        if (filesize($path) > self::MAX_FILE) {
            throw new \InvalidArgumentException(t('files.preview_too_big'));
        }
        $p = new self();
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $body = match (true) {
            $ext === 'docx' => $p->docx($path),
            $ext === 'xlsx' => $p->xlsx($path),
            $ext === 'pptx' => $p->pptx($path),
            $ext === 'odt' => $p->odt($path),
            $ext === 'csv', $ext === 'tsv' => $p->csv($path, $ext === 'tsv' ? "\t" : null),
            in_array($ext, self::TEXT, true) => $p->text($path),
            default => throw new \InvalidArgumentException(t('files.preview_unsupported')),
        };
        return self::page($name, $body, $ext);
    }

    private static function page(string $title, string $body, string $kind): string
    {
        $css = 'body{margin:0;background:#f1f5f9;font:14px/1.55 Calibri,Carlito,"Segoe UI",Arial,sans-serif;color:#1f2937}'
            . '.doc{max-width:820px;margin:24px auto;background:#fff;padding:56px 64px;box-shadow:0 2px 12px rgba(15,23,42,.12);border-radius:4px}'
            . '.doc p{margin:0 0 .55em}.doc h1{font-size:1.9em}.doc h2{font-size:1.5em}.doc h3{font-size:1.25em}.doc h1,.doc h2,.doc h3,.doc h4{margin:.8em 0 .4em;color:#1e3a8a}'
            . '.doc table{border-collapse:collapse;margin:.6em 0;width:100%}.doc td,.doc th{border:1px solid #cbd5e1;padding:4px 8px;vertical-align:top}'
            . '.doc img,.slide img{max-width:100%;height:auto}.doc li{margin:.15em 0}'
            . '.sheets{padding:16px}.sheet{background:#fff;margin:0 0 20px;border-radius:6px;box-shadow:0 1px 6px rgba(15,23,42,.1);overflow:auto}'
            . '.sheet h2{font-size:14px;margin:0;padding:8px 12px;background:#e2e8f0;position:sticky;left:0}'
            . '.sheet table{border-collapse:collapse;font:12.5px/1.4 Calibri,Carlito,Arial,sans-serif}.sheet td,.sheet th{border:1px solid #e2e8f0;padding:3px 8px;white-space:nowrap;max-width:420px;overflow:hidden;text-overflow:ellipsis}'
            . '.sheet th{background:#f8fafc;color:#64748b;font-weight:600;text-align:center;position:sticky;top:0}.sheet td.n{text-align:right}.sheet th.r{position:sticky;left:0}'
            . '.slides{padding:20px}.slide{background:#fff;max-width:900px;margin:0 auto 22px;aspect-ratio:16/9;border-radius:6px;box-shadow:0 2px 12px rgba(15,23,42,.14);padding:28px 40px;overflow:hidden;position:relative}'
            . '.slide .no{position:absolute;right:14px;bottom:10px;color:#94a3b8;font-size:12px}.slide p{margin:0 0 .4em}.slide .t{font-size:1.7em;font-weight:600;color:#0f172a;margin-bottom:.5em}'
            . 'pre{margin:0;padding:20px;white-space:pre-wrap;word-break:break-word;font:13px/1.5 ui-monospace,Consolas,monospace;background:#fff;min-height:100vh;box-sizing:border-box}'
            . '.note{max-width:820px;margin:12px auto 0;color:#64748b;font-size:12px;text-align:center}';
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title)
            . '</title><style>' . $css . '</style></head><body class="k-' . e($kind) . '">' . $body . '</body></html>';
    }

    // ------------------------------------------------------------- helpers

    private function zip(string $path): \ZipArchive
    {
        $z = new \ZipArchive();
        if ($z->open($path, \ZipArchive::RDONLY) !== true) {
            throw new \InvalidArgumentException(t('files.preview_failed'));
        }
        return $z;
    }

    private function part(\ZipArchive $z, string $name): ?string
    {
        $st = $z->statName($name);
        if ($st === false) {
            return null;
        }
        if ($st['size'] > self::MAX_PART) {
            throw new \InvalidArgumentException(t('files.preview_too_big'));
        }
        $s = $z->getFromName($name);
        return $s === false ? null : $s;
    }

    private function xml(?string $s): ?\DOMDocument
    {
        if ($s === null || $s === '') {
            return null;
        }
        $d = new \DOMDocument();
        // No network, no entity expansion.
        if (!@$d->loadXML($s, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            return null;
        }
        return $d;
    }

    /** Relationship id → target path, resolved against the part's folder. */
    private function rels(\ZipArchive $z, string $part): array
    {
        $dir = dirname($part);
        $relPath = $dir . '/_rels/' . basename($part) . '.rels';
        $d = $this->xml($this->part($z, $relPath));
        $out = [];
        if (!$d) {
            return $out;
        }
        foreach ($d->getElementsByTagName('Relationship') as $r) {
            $target = $r->getAttribute('Target');
            if ($r->getAttribute('TargetMode') === 'External') {
                continue;
            }
            $full = str_starts_with($target, '/') ? ltrim($target, '/') : $dir . '/' . $target;
            // Normalise "a/b/../c".
            $parts = [];
            foreach (explode('/', $full) as $seg) {
                if ($seg === '..') {
                    array_pop($parts);
                } elseif ($seg !== '.' && $seg !== '') {
                    $parts[] = $seg;
                }
            }
            $out[$r->getAttribute('Id')] = implode('/', $parts);
        }
        return $out;
    }

    private function image(\ZipArchive $z, string $path, string $alt = ''): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif'][$ext] ?? null;
        $st = $z->statName($path);
        if (!$mime || $st === false || $st['size'] > self::MAX_IMAGE || $this->imageBytes + $st['size'] > self::MAX_IMAGES_TOTAL) {
            return '';
        }
        $data = $z->getFromName($path);
        if ($data === false) {
            return '';
        }
        $this->imageBytes += strlen($data);
        return '<img alt="' . e($alt) . '" src="data:' . $mime . ';base64,' . base64_encode($data) . '">';
    }

    private static function children(\DOMNode $n, string $local): array
    {
        $out = [];
        foreach ($n->childNodes as $c) {
            if ($c instanceof \DOMElement && $c->localName === $local) {
                $out[] = $c;
            }
        }
        return $out;
    }

    private static function first(\DOMNode $n, string $local): ?\DOMElement
    {
        foreach ($n->getElementsByTagNameNS('*', $local) as $e) {
            return $e;
        }
        return null;
    }

    // ---------------------------------------------------------------- Word

    private function docx(string $path): string
    {
        $z = $this->zip($path);
        $doc = $this->xml($this->part($z, 'word/document.xml'));
        if (!$doc) {
            throw new \InvalidArgumentException(t('files.preview_failed'));
        }
        $rels = $this->rels($z, 'word/document.xml');
        $body = self::first($doc, 'body');
        $html = '';
        $list = false;
        foreach ($body ? $body->childNodes : [] as $n) {
            if (!$n instanceof \DOMElement) {
                continue;
            }
            if ($n->localName === 'p') {
                [$tag, $inner, $isList] = $this->wordParagraph($z, $n, $rels);
                if ($isList && !$list) {
                    $html .= '<ul>';
                } elseif (!$isList && $list) {
                    $html .= '</ul>';
                }
                $list = $isList;
                $html .= $isList ? '<li>' . $inner . '</li>' : "<$tag>" . ($inner === '' ? '&nbsp;' : $inner) . "</$tag>";
            } elseif ($n->localName === 'tbl') {
                if ($list) {
                    $html .= '</ul>';
                    $list = false;
                }
                $html .= $this->wordTable($z, $n, $rels);
            }
        }
        if ($list) {
            $html .= '</ul>';
        }
        $z->close();
        return '<div class="doc">' . $html . '</div>';
    }

    /** @return array{string,string,bool} tag, inner HTML, is list item */
    private function wordParagraph(\ZipArchive $z, \DOMElement $p, array $rels): array
    {
        $tag = 'p';
        $isList = false;
        $align = '';
        foreach (self::children($p, 'pPr') as $ppr) {
            foreach ($ppr->childNodes as $c) {
                if (!$c instanceof \DOMElement) {
                    continue;
                }
                if ($c->localName === 'pStyle' && preg_match('/^(?:heading|titre|title)\s*(\d?)$/i', $c->getAttribute('w:val'), $m)) {
                    $tag = 'h' . min(4, max(1, (int) ($m[1] ?: 1)));
                } elseif ($c->localName === 'numPr') {
                    $isList = true;
                } elseif ($c->localName === 'jc') {
                    $v = $c->getAttribute('w:val');
                    $align = in_array($v, ['center', 'right'], true) ? $v : ($v === 'both' ? 'justify' : '');
                }
            }
        }
        $inner = '';
        foreach ($p->childNodes as $c) {
            if ($c instanceof \DOMElement && in_array($c->localName, ['r', 'hyperlink', 'ins', 'smartTag'], true)) {
                $runs = $c->localName === 'r' ? [$c] : iterator_to_array($c->getElementsByTagNameNS('*', 'r'));
                foreach ($runs as $r) {
                    $inner .= $this->wordRun($z, $r, $rels);
                }
            }
        }
        if ($align !== '' && !$isList) {
            $inner = '<span style="display:block;text-align:' . $align . '">' . $inner . '</span>';
        }
        return [$tag, $inner, $isList];
    }

    private function wordRun(\ZipArchive $z, \DOMElement $r, array $rels): string
    {
        $text = '';
        foreach ($r->childNodes as $c) {
            if (!$c instanceof \DOMElement) {
                continue;
            }
            $text .= match ($c->localName) {
                't' => e($c->textContent),
                'tab' => '&emsp;',
                'br', 'cr' => '<br>',
                'drawing', 'pict' => $this->wordImage($z, $c, $rels),
                default => '',
            };
        }
        if ($text === '') {
            return '';
        }
        $rpr = self::children($r, 'rPr')[0] ?? null;
        if ($rpr) {
            $has = static fn(string $tag) => (bool) self::children($rpr, $tag) && !in_array(self::children($rpr, $tag)[0]->getAttribute('w:val'), ['0', 'false', 'none'], true);
            if ($has('b')) {
                $text = '<b>' . $text . '</b>';
            }
            if ($has('i')) {
                $text = '<i>' . $text . '</i>';
            }
            if ($has('u')) {
                $text = '<u>' . $text . '</u>';
            }
            if ($has('strike')) {
                $text = '<s>' . $text . '</s>';
            }
            $color = self::children($rpr, 'color')[0] ?? null;
            if ($color && preg_match('/^[0-9a-f]{6}$/i', $color->getAttribute('w:val'))) {
                $text = '<span style="color:#' . $color->getAttribute('w:val') . '">' . $text . '</span>';
            }
        }
        return $text;
    }

    private function wordImage(\ZipArchive $z, \DOMElement $drawing, array $rels): string
    {
        foreach ($drawing->getElementsByTagNameNS('*', 'blip') as $blip) {
            $id = $blip->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'embed');
            if ($id !== '' && isset($rels[$id])) {
                return $this->image($z, $rels[$id]);
            }
        }
        foreach ($drawing->getElementsByTagNameNS('*', 'imagedata') as $im) {
            $id = $im->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            if ($id !== '' && isset($rels[$id])) {
                return $this->image($z, $rels[$id]);
            }
        }
        return '';
    }

    private function wordTable(\ZipArchive $z, \DOMElement $tbl, array $rels): string
    {
        $html = '<table>';
        foreach (self::children($tbl, 'tr') as $tr) {
            $html .= '<tr>';
            foreach (self::children($tr, 'tc') as $tc) {
                $span = 1;
                foreach ($tc->getElementsByTagNameNS('*', 'gridSpan') as $g) {
                    $span = max(1, min(50, (int) $g->getAttribute('w:val')));
                }
                $cell = '';
                foreach ($tc->childNodes as $c) {
                    if ($c instanceof \DOMElement && $c->localName === 'p') {
                        $cell .= '<p>' . $this->wordParagraph($z, $c, $rels)[1] . '</p>';
                    } elseif ($c instanceof \DOMElement && $c->localName === 'tbl') {
                        $cell .= $this->wordTable($z, $c, $rels);
                    }
                }
                $html .= '<td' . ($span > 1 ? ' colspan="' . $span . '"' : '') . '>' . $cell . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</table>';
    }

    // --------------------------------------------------------------- Excel

    private function xlsx(string $path): string
    {
        $z = $this->zip($path);
        $shared = [];
        if ($ss = $this->xml($this->part($z, 'xl/sharedStrings.xml'))) {
            foreach ($ss->getElementsByTagNameNS('*', 'si') as $si) {
                $t = '';
                foreach ($si->getElementsByTagNameNS('*', 't') as $tn) {
                    $t .= $tn->textContent;
                }
                $shared[] = $t;
            }
        }
        $wb = $this->xml($this->part($z, 'xl/workbook.xml'));
        $rels = $this->rels($z, 'xl/workbook.xml');
        $html = '';
        foreach ($wb ? $wb->getElementsByTagNameNS('*', 'sheet') : [] as $sheet) {
            if ($sheet->getAttribute('state') === 'hidden') {
                continue;
            }
            $rid = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            $target = $rels[$rid] ?? '';
            $sd = $target !== '' ? $this->xml($this->part($z, $target)) : null;
            $html .= '<section class="sheet"><h2>' . e($sheet->getAttribute('name')) . '</h2>' . ($sd ? $this->sheetTable($sd, $shared) : '') . '</section>';
        }
        $z->close();
        return '<div class="sheets">' . ($html !== '' ? $html : '<p class="note">' . e(t('files.preview_empty')) . '</p>') . '</div>';
    }

    private static function colIndex(string $ref): int
    {
        $letters = preg_replace('/\d+/', '', strtoupper($ref)) ?? '';
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return max(0, $n - 1);
    }

    private static function colName(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    private function sheetTable(\DOMDocument $sd, array $shared): string
    {
        $rows = [];
        $maxCol = 0;
        $count = 0;
        $truncated = false;
        foreach ($sd->getElementsByTagNameNS('*', 'row') as $row) {
            if (++$count > self::MAX_ROWS) {
                $truncated = true;
                break;
            }
            $r = (int) $row->getAttribute('r') ?: $count;
            foreach (self::children($row, 'c') as $c) {
                $col = $c->getAttribute('r') !== '' ? self::colIndex($c->getAttribute('r')) : count($rows[$r] ?? []);
                if ($col >= self::MAX_COLS) {
                    $truncated = true;
                    continue;
                }
                $type = $c->getAttribute('t');
                $v = (self::children($c, 'v')[0] ?? null)?->textContent ?? '';
                if ($type === 's') {
                    $val = $shared[(int) $v] ?? '';
                } elseif ($type === 'inlineStr') {
                    $val = '';
                    foreach ($c->getElementsByTagNameNS('*', 't') as $tn) {
                        $val .= $tn->textContent;
                    }
                } elseif ($type === 'b') {
                    $val = $v === '1' ? 'VRAI' : 'FAUX';
                } else {
                    $val = $v;
                }
                if ($val === '') {
                    continue;
                }
                $num = $type === '' && is_numeric($val);
                if ($num && str_contains($val, 'E')) {
                    $val = rtrim(rtrim(number_format((float) $val, 6, '.', ''), '0'), '.');
                } elseif ($num && strlen($val) > 12 && str_contains($val, '.')) {
                    $val = (string) round((float) $val, 6);
                }
                $rows[$r][$col] = [$val, $num];
                $maxCol = max($maxCol, $col + 1);
            }
        }
        if (!$rows) {
            return '<p class="note">' . e(t('files.preview_empty')) . '</p>';
        }
        ksort($rows);
        $html = '<table><thead><tr><th class="r"></th>';
        for ($i = 0; $i < $maxCol; $i++) {
            $html .= '<th>' . self::colName($i) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $r => $cells) {
            $html .= '<tr><th class="r">' . (int) $r . '</th>';
            for ($i = 0; $i < $maxCol; $i++) {
                [$val, $num] = $cells[$i] ?? ['', false];
                $html .= '<td' . ($num ? ' class="n"' : '') . ($val !== '' ? ' title="' . e(mb_substr($val, 0, 300)) . '"' : '') . '>' . e($val) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        if ($truncated) {
            $html .= '<p class="note">' . e(t('files.preview_truncated', ['rows' => self::MAX_ROWS, 'cols' => self::MAX_COLS])) . '</p>';
        }
        return $html;
    }

    // ---------------------------------------------------------- PowerPoint

    private function pptx(string $path): string
    {
        $z = $this->zip($path);
        $pres = $this->xml($this->part($z, 'ppt/presentation.xml'));
        $rels = $this->rels($z, 'ppt/presentation.xml');
        $html = '';
        $n = 0;
        foreach ($pres ? $pres->getElementsByTagNameNS('*', 'sldId') : [] as $sld) {
            $rid = $sld->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            $target = $rels[$rid] ?? '';
            $sd = $target !== '' ? $this->xml($this->part($z, $target)) : null;
            if (!$sd) {
                continue;
            }
            $n++;
            $srels = $this->rels($z, $target);
            $inner = '';
            foreach ($sd->getElementsByTagNameNS('*', 'sp') as $sp) {
                $isTitle = false;
                foreach ($sp->getElementsByTagNameNS('*', 'ph') as $ph) {
                    $isTitle = in_array($ph->getAttribute('type'), ['title', 'ctrTitle'], true);
                }
                foreach ($sp->getElementsByTagNameNS('*', 'p') as $p) {
                    if ($p->namespaceURI !== 'http://schemas.openxmlformats.org/drawingml/2006/main') {
                        continue;
                    }
                    $t = '';
                    foreach ($p->getElementsByTagNameNS('*', 't') as $tn) {
                        $t .= $tn->textContent;
                    }
                    if (trim($t) !== '') {
                        $inner .= '<p' . ($isTitle ? ' class="t"' : '') . '>' . e($t) . '</p>';
                    }
                }
            }
            foreach ($sd->getElementsByTagNameNS('*', 'blip') as $blip) {
                $id = $blip->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'embed');
                if (isset($srels[$id])) {
                    $inner .= $this->image($z, $srels[$id]);
                }
            }
            $html .= '<section class="slide">' . $inner . '<span class="no">' . $n . '</span></section>';
        }
        $z->close();
        return '<div class="slides">' . ($html !== '' ? $html : '<p class="note">' . e(t('files.preview_empty')) . '</p>') . '</div>';
    }

    // --------------------------------------------------------- OpenDocument

    private function odt(string $path): string
    {
        $z = $this->zip($path);
        $doc = $this->xml($this->part($z, 'content.xml'));
        $z->close();
        if (!$doc) {
            throw new \InvalidArgumentException(t('files.preview_failed'));
        }
        $html = '';
        $body = self::first($doc, 'text');
        foreach ($body ? $body->childNodes : [] as $n) {
            if (!$n instanceof \DOMElement) {
                continue;
            }
            if ($n->localName === 'h') {
                $lvl = max(1, min(4, (int) ($n->getAttribute('text:outline-level') ?: 1)));
                $html .= "<h$lvl>" . e($n->textContent) . "</h$lvl>";
            } elseif ($n->localName === 'p') {
                $html .= '<p>' . (trim($n->textContent) === '' ? '&nbsp;' : e($n->textContent)) . '</p>';
            } elseif ($n->localName === 'list') {
                $html .= '<ul>';
                foreach ($n->getElementsByTagNameNS('*', 'list-item') as $li) {
                    $html .= '<li>' . e($li->textContent) . '</li>';
                }
                $html .= '</ul>';
            } elseif ($n->localName === 'table') {
                $html .= '<table>';
                foreach ($n->getElementsByTagNameNS('*', 'table-row') as $tr) {
                    $html .= '<tr>';
                    foreach (self::children($tr, 'table-cell') as $td) {
                        $html .= '<td>' . e($td->textContent) . '</td>';
                    }
                    $html .= '</tr>';
                }
                $html .= '</table>';
            }
        }
        return '<div class="doc">' . $html . '</div>';
    }

    // -------------------------------------------------------- CSV and text

    private function csv(string $path, ?string $sep): string
    {
        $fh = fopen($path, 'rb');
        $head = (string) fread($fh, 8192);
        rewind($fh);
        if ($sep === null) {
            $counts = [';' => substr_count($head, ';'), ',' => substr_count($head, ','), "\t" => substr_count($head, "\t")];
            arsort($counts);
            $sep = (string) array_key_first($counts);
        }
        $html = '<table><tbody>';
        $n = 0;
        while (($row = fgetcsv($fh, 0, $sep, '"', '')) !== false && $n < self::MAX_ROWS) {
            $n++;
            $html .= '<tr><th class="r">' . $n . '</th>';
            foreach (array_slice($row, 0, self::MAX_COLS) as $cell) {
                $cell = self::utf8((string) $cell);
                $html .= '<td' . (is_numeric(str_replace([' ', ','], ['', '.'], $cell)) ? ' class="n"' : '') . '>' . e($cell) . '</td>';
            }
            $html .= '</tr>';
        }
        fclose($fh);
        return '<div class="sheets"><section class="sheet">' . $html . '</tbody></table></section></div>';
    }

    private function text(string $path): string
    {
        $s = (string) file_get_contents($path, false, null, 0, 2 * 1024 * 1024);
        return '<pre>' . e(self::utf8($s)) . '</pre>';
    }

    private static function utf8(string $s): string
    {
        if (str_starts_with($s, "\xEF\xBB\xBF")) {
            $s = substr($s, 3);
        }
        return mb_check_encoding($s, 'UTF-8') ? $s : (string) mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }
}
