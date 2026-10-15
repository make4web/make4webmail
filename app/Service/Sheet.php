<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Config;
use M4W\Core\Database as DB;
use M4W\Core\I18n;
use PhpOffice\PhpSpreadsheet\Calculation\Calculation;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlDate;
use PhpOffice\PhpSpreadsheet\Shared\StringHelper;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Built-in spreadsheet editor (no office server needed), several people at once.
 *
 * Each file being edited has a working copy (xlsx, in storage/data/sheets, so it is part of
 * the backups). Browsers send small operations (set a cell, style a range, insert rows…);
 * they are applied in arrival order by PhpSpreadsheet, which recalculates with Excel's
 * functions, and the cells that changed are published as a "patch" every editor polls.
 * Saving publishes the working copy as a new version of the file (or as a copy when the
 * original holds what this editor cannot keep: charts, pivot tables, macros, .xls).
 */
final class Sheet
{
    public const EXT = ['xlsx', 'xls', 'ods', 'csv', 'tsv'];
    public const MAX_BYTES = 15 * 1048576;
    public const MAX_CELLS = 60000;
    private const MAX_RANGE = 200000;
    private const PRESENCE_TTL = 30;

    public const FORMATS = [
        'general' => 'General',
        'number' => '#,##0.00',
        'integer' => '#,##0',
        'percent' => '0.00%',
        'currency' => '#,##0.00 "€"',
        'date' => 'dd/mm/yyyy',
        'datetime' => 'dd/mm/yyyy hh:mm',
        'text' => '@',
    ];

    public static function supports(string $name): bool
    {
        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::EXT, true);
    }

    private static function dir(): string
    {
        $d = Config::storagePath('data/sheets');
        if (!is_dir($d)) {
            mkdir($d, 0750, true);
        }
        return $d;
    }

    private static function workPath(int $fileId): string
    {
        return self::dir() . '/' . $fileId . '.xlsx';
    }

    private static function cachePath(int $fileId): string
    {
        return self::dir() . '/' . $fileId . '.cells.json';
    }

    /** @return resource */
    private static function lock(int $fileId)
    {
        $h = fopen(self::dir() . '/' . $fileId . '.lock', 'c');
        flock($h, LOCK_EX);
        return $h;
    }

    private static function unlock($h): void
    {
        flock($h, LOCK_UN);
        fclose($h);
    }

    // ---------------------------------------------------------------- locale

    private static function fr(): bool
    {
        return I18n::language() === 'fr';
    }

    private static function setupLocale(): void
    {
        StringHelper::setDecimalSeparator(self::fr() ? ',' : '.');
        StringHelper::setThousandsSeparator(self::fr() ? "\u{202F}" : ',');
        StringHelper::setCurrencyCode('€');
        Calculation::getInstance()->setLocale(self::fr() ? 'fr' : 'en_us');
    }

    /** Outside double quotes, apply $fn to the formula text. */
    private static function outsideStrings(string $f, callable $fn): string
    {
        $parts = preg_split('/("(?:[^"]|"")*")/', $f, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$f];
        foreach ($parts as $i => $p) {
            if ($i % 2 === 0) {
                $parts[$i] = $fn($p);
            }
        }
        return implode('', $parts);
    }

    /** "=SOMME(A1;1,5)" → "=SUM(A1,1.5)" */
    public static function toEnglish(string $f): string
    {
        if (!self::fr()) {
            return $f;
        }
        self::setupLocale();
        $f = self::outsideStrings($f, static fn($p) => preg_replace('/(?<=\d),(?=\d)/', '.', $p) ?? $p);
        return Calculation::getInstance()->translateFormulaToEnglish($f);
    }

    /** "=SUM(A1,1.5)" → "=SOMME(A1;1,5)" */
    public static function toLocale(string $f): string
    {
        if (!self::fr()) {
            return $f;
        }
        self::setupLocale();
        $f = Calculation::getInstance()->translateFormulaToLocale($f);
        return self::outsideStrings($f, static fn($p) => preg_replace('/(?<=\d)\.(?=\d)/', ',', $p) ?? $p);
    }

    /**
     * What a person typed → [value, data type, number format to apply or null].
     * French habits: "3,5", "1 234,5", "25 %", "12,50 €", "15/10/2026", "VRAI".
     */
    public static function parseInput(string $s): array
    {
        $t = trim($s);
        if ($t === '') {
            return [null, DataType::TYPE_NULL, null];
        }
        if ($t[0] === "'") {
            return [substr($t, 1), DataType::TYPE_STRING, null];
        }
        $fr = self::fr();
        $num = static function (string $n) use ($fr): ?float {
            $n = str_replace(["\u{00A0}", "\u{202F}", ' '], '', $n);
            if ($fr) {
                if (preg_match('/^-?\d+(,\d+)?$/', $n)) {
                    return (float) str_replace(',', '.', $n);
                }
                return preg_match('/^-?\d+\.\d+$/', $n) ? (float) $n : null; // "3.5" typed anyway
            }
            $n = str_replace(',', '', $n);
            return preg_match('/^-?\d+(\.\d+)?$/', $n) ? (float) $n : null;
        };
        $clean = static fn(float $v) => floor($v) === $v && abs($v) < 1e15 ? (int) $v : $v;
        if (($v = $num($t)) !== null) {
            return [$clean($v), DataType::TYPE_NUMERIC, null];
        }
        if (preg_match('/^(.+?)\s*%$/u', $t, $m) && ($v = $num($m[1])) !== null) {
            return [$v / 100, DataType::TYPE_NUMERIC, str_contains($m[1], $fr ? ',' : '.') ? self::FORMATS['percent'] : '0%'];
        }
        if ((preg_match('/^(.+?)\s*€$/u', $t, $m) || preg_match('/^€\s*(.+)$/u', $t, $m)) && ($v = $num($m[1])) !== null) {
            return [$clean($v), DataType::TYPE_NUMERIC, self::FORMATS['currency']];
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2}|\d{4})(?:\s+(\d{1,2}):(\d{2}))?$#', $t, $m)) {
            [$d, $mo] = $fr ? [(int) $m[1], (int) $m[2]] : [(int) $m[2], (int) $m[1]];
            $y = (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3];
            if (checkdate($mo, $d, $y)) {
                $time = isset($m[4]);
                return [XlDate::formattedPHPToExcel($y, $mo, $d, $time ? (int) $m[4] : 0, $time ? (int) $m[5] : 0), DataType::TYPE_NUMERIC, self::FORMATS[$time ? 'datetime' : 'date']];
            }
        }
        $up = mb_strtoupper($t);
        if (in_array($up, ['VRAI', 'TRUE'], true) || in_array($up, ['FAUX', 'FALSE'], true)) {
            return [in_array($up, ['VRAI', 'TRUE'], true), DataType::TYPE_BOOL, null];
        }
        return [$t, DataType::TYPE_STRING, null];
    }

    private static function numberText(float|int $v): string
    {
        $s = is_int($v) ? (string) $v : rtrim(rtrim(sprintf('%.15F', $v), '0'), '.');
        if (is_float($v) && strlen((string) $v) < strlen($s)) {
            $s = (string) $v; // shortest exact form (avoids 0.1000000000000000055)
        }
        return self::fr() ? str_replace('.', ',', $s) : $s;
    }

    /** Text shown when editing a cell (raw value, the way a person would type it). */
    private static function editText(Cell $c): string
    {
        $v = $c->getValue();
        if ($v instanceof RichText) {
            return $v->getPlainText();
        }
        if ($c->isFormula()) {
            return self::toLocale((string) $v);
        }
        if (is_bool($v)) {
            return self::fr() ? ($v ? 'VRAI' : 'FAUX') : ($v ? 'TRUE' : 'FALSE');
        }
        if (is_int($v) || is_float($v)) {
            $code = $c->getStyle()->getNumberFormat()->getFormatCode();
            if (XlDate::isDateTimeFormatCode($code)) {
                $dt = XlDate::excelToDateTimeObject((float) $v);
                $withTime = (float) $v - floor((float) $v) > 0.0000001;
                return $dt->format(self::fr() ? 'd/m/Y' : 'm/d/Y') . ($withTime ? $dt->format(' H:i') : '');
            }
            if (str_contains($code, '%')) {
                return self::numberText(round($v * 100, 10)) . '%';
            }
            return self::numberText($v);
        }
        return (string) ($v ?? '');
    }

    private static function displayText(Cell $c): string
    {
        try {
            $v = $c->getValue();
            if ($v instanceof RichText) {
                return $v->getPlainText();
            }
            if ($c->isFormula()) {
                $calc = $c->getCalculatedValue();
                if (is_bool($calc)) {
                    return self::fr() ? ($calc ? 'VRAI' : 'FAUX') : ($calc ? 'TRUE' : 'FALSE');
                }
                return NumberFormat::toFormattedString($calc, $c->getStyle()->getNumberFormat()->getFormatCode());
            }
            if (is_bool($v)) {
                return self::fr() ? ($v ? 'VRAI' : 'FAUX') : ($v ? 'TRUE' : 'FALSE');
            }
            return (string) $c->getFormattedValue();
        } catch (\Throwable) {
            return '#' . (self::fr() ? 'ERREUR' : 'ERROR') . '!';
        }
    }

    // ---------------------------------------------------------------- files

    private static function reader(string $ext): \PhpOffice\PhpSpreadsheet\Reader\IReader
    {
        $r = IOFactory::createReader(match ($ext) {
            'xls' => 'Xls',
            'ods' => 'Ods',
            'csv', 'tsv' => 'Csv',
            default => 'Xlsx',
        });
        if ($r instanceof \PhpOffice\PhpSpreadsheet\Reader\Csv) {
            $r->setInputEncoding(\PhpOffice\PhpSpreadsheet\Reader\Csv::GUESS_ENCODING);
            if ($ext === 'tsv') {
                $r->setDelimiter("\t");
            }
        }
        return $r;
    }

    private static function load(string $path, string $ext): Spreadsheet
    {
        self::setupLocale();
        if ($ext === 'csv') {
            // ";" in France, "," elsewhere: the separator found most on the first line wins.
            $h = fopen($path, 'rb');
            $first = $h ? (string) fgets($h) : '';
            if ($h) {
                fclose($h);
            }
            $r = self::reader('csv');
            $best = ',';
            $max = -1;
            foreach ([';', ',', "\t", '|'] as $d) {
                if (substr_count($first, $d) > $max) {
                    $max = substr_count($first, $d);
                    $best = $d;
                }
            }
            $r->setDelimiter($best);
            return $r->load($path);
        }
        return self::reader($ext)->load($path);
    }

    /** What would be lost by saving the file again with this editor ('' = nothing known). */
    public static function complexity(string $path, string $ext): string
    {
        if ($ext === 'xls') {
            return 'legacy';
        }
        if (!in_array($ext, ['xlsx', 'ods'], true)) {
            return '';
        }
        $z = new \ZipArchive();
        if ($z->open($path, \ZipArchive::RDONLY) !== true) {
            return '';
        }
        $why = '';
        for ($i = 0; $i < $z->numFiles && $why === ''; $i++) {
            $n = (string) $z->getNameIndex($i);
            $why = match (true) {
                (bool) preg_match('#^xl/(pivotTables|pivotCache)/#', $n) => 'pivot',
                (bool) preg_match('#^xl/charts/#', $n) => 'charts',
                str_ends_with($n, 'vbaProject.bin') => 'macros',
                (bool) preg_match('#^xl/(slicers|slicerCaches|timelines|externalLinks)/#', $n) => 'advanced',
                default => '',
            };
        }
        if ($why === '' && $ext === 'ods') {
            $content = (string) $z->getFromName('content.xml');
            $why = str_contains($content, '<draw:object') || str_contains($content, 'table:data-pilot') ? 'charts' : '';
        }
        $z->close();
        return $why;
    }

    private static function countCells(Spreadsheet $wb): int
    {
        $n = 0;
        foreach ($wb->getAllSheets() as $ws) {
            $n += $ws->getCellCollection()->getCurrentCoordinate() !== null ? count($ws->getCellCollection()->getCoordinates()) : 0;
        }
        return $n;
    }

    // ---------------------------------------------------------------- session

    /**
     * Open the file for this user: working copy ready, whole workbook for the grid.
     * @return array{mode:string,reason:string,can_write:bool,seq:int,workbook:array,file:array,dirty:bool}
     */
    public static function open(array $user, array $file): array
    {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::EXT, true)) {
            throw new \InvalidArgumentException(t('sheet.unsupported'));
        }
        if ((int) $file['size'] > self::MAX_BYTES) {
            throw new \InvalidArgumentException(t('sheet.too_big'));
        }
        $canWrite = Files::filePermission($user, $file) >= Files::WRITE;
        $fid = (int) $file['id'];
        $lock = self::lock($fid);
        try {
            $s = DB::one('SELECT * FROM sheet_sessions WHERE file_id = :f', ['f' => $fid]);
            $stale = !$s || !is_file(self::workPath($fid)) || (!(int) $s['dirty'] && (int) $s['base_version'] !== (int) $file['version']);
            if ($stale) {
                $s = self::start($file, $ext);
            }
            $wb = IOFactory::createReader('Xlsx')->load(self::workPath($fid));
            $book = self::export($wb);
            if (!is_file(self::cachePath($fid))) {
                // Starting point of the patches: what everyone has just loaded.
                file_put_contents(self::cachePath($fid), json_encode(array_map(static fn($sh) => (array) $sh['cells'], $book['sheets']), JSON_UNESCAPED_UNICODE));
            }
            $seq = (int) (DB::value('SELECT MAX(id) FROM sheet_ops WHERE file_id = :f', ['f' => $fid]) ?? 0);
        } finally {
            self::unlock($lock);
        }
        $mode = $s['mode'] === 'view' || !$canWrite ? 'view' : $s['mode'];
        return ['mode' => $mode, 'reason' => !$canWrite ? 'rights' : (string) $s['reason'], 'can_write' => $canWrite && $mode !== 'view',
            'seq' => $seq, 'workbook' => $book, 'file' => Files::fileOut($file), 'dirty' => (bool) (int) $s['dirty'], 'ext' => $ext];
    }

    /** New working copy from the current version of the file. */
    private static function start(array $file, string $ext): array
    {
        $fid = (int) $file['id'];
        $src = (string) tempnam(Config::storagePath('tmp'), 'sh');
        $out = fopen($src, 'wb');
        Files::stream((string) $file['blob_id'], static function (string $buf) use ($out) {
            fwrite($out, $buf);
        });
        fclose($out);
        try {
            $why = self::complexity($src, $ext);
            try {
                $wb = self::load($src, $ext);
            } catch (\Throwable $e) {
                throw new \InvalidArgumentException(t('sheet.unreadable'));
            }
            $mode = $why !== '' ? 'copy' : 'edit';
            if (self::countCells($wb) > self::MAX_CELLS) {
                [$mode, $why] = ['view', 'cells'];
            }
            self::save($wb, $fid);
        } finally {
            @unlink($src);
        }
        DB::run('DELETE FROM sheet_ops WHERE file_id = :f', ['f' => $fid]);
        DB::run('DELETE FROM sheet_sessions WHERE file_id = :f', ['f' => $fid]);
        @unlink(self::cachePath($fid));
        $row = ['file_id' => $fid, 'base_version' => (int) $file['version'], 'mode' => $mode, 'reason' => $why, 'dirty' => 0,
            'created_at' => time(), 'updated_at' => time(), 'published_at' => 0];
        DB::insert('sheet_sessions', $row);
        return $row;
    }

    private static function save(Spreadsheet $wb, int $fid): void
    {
        $w = IOFactory::createWriter($wb, 'Xlsx');
        $w->setPreCalculateFormulas(false);
        $tmp = self::workPath($fid) . '.tmp';
        $w->save($tmp);
        rename($tmp, self::workPath($fid));
    }

    // ---------------------------------------------------------------- export

    private static function xlColor(string $argb): string
    {
        return '#' . strtolower(substr(str_pad($argb, 8, 'F', STR_PAD_LEFT), 2));
    }

    /** Cell style for the grid (a subset: what this editor shows and changes). */
    private static function styleOut(\PhpOffice\PhpSpreadsheet\Style\Style $st): array
    {
        $f = $st->getFont();
        $o = [];
        if ($f->getBold()) {
            $o['b'] = 1;
        }
        if ($f->getItalic()) {
            $o['i'] = 1;
        }
        if ($f->getUnderline() !== 'none' && $f->getUnderline() !== null) {
            $o['u'] = 1;
        }
        if ($f->getStrikethrough()) {
            $o['s'] = 1;
        }
        $fc = $f->getColor()->getARGB();
        if ($fc && $fc !== 'FF000000') {
            $o['fc'] = self::xlColor($fc);
        }
        if ($f->getSize() && (float) $f->getSize() !== 11.0) {
            $o['fs'] = (float) $f->getSize();
        }
        if ($st->getFill()->getFillType() === Fill::FILL_SOLID) {
            $o['bg'] = self::xlColor((string) $st->getFill()->getStartColor()->getARGB());
        }
        $a = $st->getAlignment();
        if (in_array($a->getHorizontal(), ['left', 'center', 'right', 'justify'], true)) {
            $o['ha'] = $a->getHorizontal();
        }
        if (in_array($a->getVertical(), ['top', 'center'], true)) {
            $o['va'] = $a->getVertical();
        }
        if ($a->getWrapText()) {
            $o['wrap'] = 1;
        }
        $code = $st->getNumberFormat()->getFormatCode();
        $preset = array_search($code, self::FORMATS, true);
        if ($preset !== false && $preset !== 'general') {
            $o['nf'] = $preset;
        } elseif ($code !== 'General') {
            $o['nf'] = XlDate::isDateTimeFormatCode($code) ? 'date' : (str_contains($code, '%') ? 'percent' : (str_contains($code, '€') ? 'currency' : 'number'));
        }
        $b = [];
        foreach (['t' => 'getTop', 'r' => 'getRight', 'bo' => 'getBottom', 'l' => 'getLeft'] as $k => $m) {
            $side = $st->getBorders()->$m();
            if ($side->getBorderStyle() !== Border::BORDER_NONE) {
                $b[$k] = self::xlColor((string) $side->getColor()->getARGB());
            }
        }
        if ($b) {
            $o['bd'] = $b;
        }
        return $o;
    }

    private static function sheetOut(Spreadsheet $wb, Worksheet $ws, array &$styles): array
    {
        $cells = [];
        foreach ($ws->getCellCollection()->getCoordinates() as $addr) {
            $c = $ws->getCell($addr);
            $xf = $c->getXfIndex();
            $disp = self::displayText($c);
            $edit = self::editText($c);
            if ($disp === '' && $edit === '' && $xf === 0) {
                continue;
            }
            if (!isset($styles[$xf])) {
                $styles[$xf] = self::styleOut($wb->getCellXfByIndex($xf));
            }
            $num = $c->isFormula() ? is_numeric($c->getCalculatedValue()) : (is_int($c->getValue()) || is_float($c->getValue()));
            $cells[$addr] = [$disp, $edit, $xf, $num ? 1 : 0];
        }
        $defW = $ws->getDefaultColumnDimension()->getWidth();
        $colw = [];
        foreach ($ws->getColumnDimensions() as $col => $dim) {
            $w = $dim->getWidth();
            if (!$dim->getVisible()) {
                $colw[Coordinate::columnIndexFromString($col)] = 0;
            } elseif ($w >= 0) {
                $colw[Coordinate::columnIndexFromString($col)] = (int) round($w * 7 + 5);
            }
        }
        $rowh = [];
        foreach ($ws->getRowDimensions() as $r => $dim) {
            if (!$dim->getVisible()) {
                $rowh[$r] = 0;
            } elseif ($dim->getRowHeight() > 0) {
                $rowh[$r] = (int) round($dim->getRowHeight() * 4 / 3);
            }
        }
        return [
            'name' => $ws->getTitle(),
            'rows' => $ws->getHighestRow(), 'cols' => Coordinate::columnIndexFromString($ws->getHighestColumn()),
            'colw' => (object) $colw, 'rowh' => (object) $rowh, 'defw' => (int) round(($defW > 0 ? $defW : 8.43) * 7 + 5),
            'merges' => array_values($ws->getMergeCells()), 'freeze' => $ws->getFreezePane(),
            'cells' => (object) $cells,
        ];
    }

    public static function export(Spreadsheet $wb): array
    {
        self::setupLocale();
        $styles = [];
        $sheets = [];
        foreach ($wb->getAllSheets() as $ws) {
            $sheets[] = self::sheetOut($wb, $ws, $styles);
        }
        return ['sheets' => $sheets, 'styles' => (object) array_map(static fn($x) => (object) $x, $styles), 'active' => $wb->getActiveSheetIndex(), 'formats' => array_keys(self::FORMATS)];
    }

    // ---------------------------------------------------------------- operations

    private static function addr(mixed $a): string
    {
        $a = strtoupper((string) $a);
        if (!preg_match('/^[A-Z]{1,3}[1-9]\d{0,6}$/', $a)) {
            throw new \InvalidArgumentException('cell');
        }
        [$c, $r] = Coordinate::coordinateFromString($a);
        if (Coordinate::columnIndexFromString($c) > 16384 || (int) $r > 1048576) {
            throw new \InvalidArgumentException('cell');
        }
        return $a;
    }

    private static function range(mixed $r): string
    {
        $r = strtoupper((string) $r);
        $parts = explode(':', $r);
        if (count($parts) > 2) {
            throw new \InvalidArgumentException('range');
        }
        $a = self::addr($parts[0]);
        $b = self::addr($parts[1] ?? $parts[0]);
        [$c1, $r1] = Coordinate::coordinateFromString($a);
        [$c2, $r2] = Coordinate::coordinateFromString($b);
        $n = (abs(Coordinate::columnIndexFromString($c2) - Coordinate::columnIndexFromString($c1)) + 1) * (abs((int) $r2 - (int) $r1) + 1);
        if ($n > self::MAX_RANGE) {
            throw new \InvalidArgumentException(t('sheet.range_too_big'));
        }
        return $a === $b ? $a : $a . ':' . $b;
    }

    private static function sheetAt(Spreadsheet $wb, mixed $i): Worksheet
    {
        $i = (int) $i;
        if ($i < 0 || $i >= $wb->getSheetCount()) {
            throw new \InvalidArgumentException('sheet');
        }
        return $wb->getSheet($i);
    }

    private static function setCell(Worksheet $ws, string $addr, string $input): void
    {
        $c = $ws->getCell($addr);
        if ($input !== '' && $input[0] === '=' && strlen($input) > 1) {
            $c->setValueExplicit(self::toEnglish($input), DataType::TYPE_FORMULA);
            return;
        }
        [$v, $type, $fmt] = self::parseInput($input);
        if ($type === DataType::TYPE_NULL) {
            $c->setValueExplicit(null, DataType::TYPE_NULL);
            return;
        }
        $c->setValueExplicit($v, $type);
        $nf = $c->getStyle()->getNumberFormat();
        if ($fmt !== null && $nf->getFormatCode() === 'General') {
            $nf->setFormatCode($fmt);
        }
    }

    private static function sheetTitle(Spreadsheet $wb, string $name, ?int $except = null): string
    {
        $name = trim(preg_replace('/[\[\]:*?\/\\\\]+/', '', $name) ?? '');
        $name = mb_substr($name !== '' ? $name : (self::fr() ? 'Feuille' : 'Sheet'), 0, 28);
        $base = $name;
        $n = 2;
        $taken = static function (string $t) use ($wb, $except): bool {
            foreach ($wb->getAllSheets() as $i => $ws) {
                if ($i !== $except && mb_strtolower($ws->getTitle()) === mb_strtolower($t)) {
                    return true;
                }
            }
            return false;
        };
        while ($taken($name)) {
            $name = $base . ' ' . $n++;
        }
        return $name;
    }

    /** @return bool structure changed (every editor reloads) */
    private static function apply(Spreadsheet $wb, array $op, array &$touched): bool
    {
        $t = (string) ($op['t'] ?? '');
        switch ($t) {
            case 'set':
                $ws = self::sheetAt($wb, $op['s'] ?? 0);
                $a = self::addr($op['c'] ?? '');
                self::setCell($ws, $a, mb_substr((string) ($op['v'] ?? ''), 0, 32767));
                $touched[(int) $op['s']][$a] = true;
                return false;
            case 'setmany': // paste: a block of values from a top-left cell
                $ws = self::sheetAt($wb, $op['s'] ?? 0);
                [$c0, $r0] = Coordinate::coordinateFromString(self::addr($op['at'] ?? 'A1'));
                $ci = Coordinate::columnIndexFromString($c0);
                $rows = array_slice((array) ($op['rows'] ?? []), 0, 5000);
                $count = 0;
                foreach ($rows as $dr => $row) {
                    foreach (array_slice((array) $row, 0, 200) as $dc => $v) {
                        if (++$count > 50000) {
                            break 2;
                        }
                        $a = Coordinate::stringFromColumnIndex($ci + $dc) . ((int) $r0 + $dr);
                        self::setCell($ws, $a, mb_substr((string) $v, 0, 32767));
                        $touched[(int) $op['s']][$a] = true;
                    }
                }
                return false;
            case 'clear':
                $ws = self::sheetAt($wb, $op['s'] ?? 0);
                $range = self::range($op['r'] ?? '');
                [[$c1, $r1], [$c2, $r2]] = array_map(static fn($p) => Coordinate::indexesFromString($p), explode(':', $range . (str_contains($range, ':') ? '' : ':' . $range)));
                foreach ($ws->getCellCollection()->getCoordinates() as $addr) {
                    [$ci, $ri] = Coordinate::indexesFromString($addr);
                    if ($ci >= min($c1, $c2) && $ci <= max($c1, $c2) && $ri >= min($r1, $r2) && $ri <= max($r1, $r2)) {
                        $ws->getCell($addr)->setValueExplicit(null, DataType::TYPE_NULL);
                    }
                }
                $touched[(int) $op['s']] ??= [];
                return false;
            case 'style':
                $ws = self::sheetAt($wb, $op['s'] ?? 0);
                $st = $ws->getStyle(self::range($op['r'] ?? ''));
                $v = $op['v'] ?? null;
                $color = static fn($c) => preg_match('/^#?([0-9a-f]{6})$/i', (string) $c, $m) ? 'FF' . strtoupper($m[1]) : null;
                match ((string) ($op['k'] ?? '')) {
                    'b' => $st->getFont()->setBold((bool) $v),
                    'i' => $st->getFont()->setItalic((bool) $v),
                    'u' => $st->getFont()->setUnderline($v ? 'single' : 'none'),
                    's' => $st->getFont()->setStrikethrough((bool) $v),
                    'fs' => $st->getFont()->setSize(max(6, min(72, (float) $v))),
                    'fc' => $st->getFont()->getColor()->setARGB($color($v) ?? 'FF000000'),
                    'bg' => $color($v) ? $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($color($v)) : $st->getFill()->setFillType(Fill::FILL_NONE),
                    'ha' => $st->getAlignment()->setHorizontal(in_array($v, ['left', 'center', 'right'], true) ? $v : Alignment::HORIZONTAL_GENERAL),
                    'va' => $st->getAlignment()->setVertical(in_array($v, ['top', 'center', 'bottom'], true) ? $v : Alignment::VERTICAL_BOTTOM),
                    'wrap' => $st->getAlignment()->setWrapText((bool) $v),
                    'nf' => $st->getNumberFormat()->setFormatCode(self::FORMATS[(string) $v] ?? 'General'),
                    'border' => $st->getBorders()->getAllBorders()->setBorderStyle($v ? Border::BORDER_THIN : Border::BORDER_NONE),
                    default => throw new \InvalidArgumentException('style'),
                };
                $touched[(int) $op['s']] ??= [];
                return false;
            case 'colw':
                $ws = self::sheetAt($wb, $op['s'] ?? 0);
                $ws->getColumnDimension(Coordinate::stringFromColumnIndex(max(1, min(16384, (int) ($op['col'] ?? 1)))))->setWidth(max(0.5, min(255, ((int) ($op['w'] ?? 64) - 5) / 7)));
                return true;
            case 'rowh':
                $ws = self::sheetAt($wb, $op['s'] ?? 0);
                $ws->getRowDimension(max(1, min(1048576, (int) ($op['row'] ?? 1))))->setRowHeight(max(5, min(400, (int) ($op['h'] ?? 20) * 0.75)));
                return true;
            case 'ins_row':
            case 'del_row':
                $ws = self::sheetAt($wb, $op['s'] ?? 0);
                $at = max(1, min(1048576, (int) ($op['at'] ?? 1)));
                $n = max(1, min(1000, (int) ($op['n'] ?? 1)));
                $t === 'ins_row' ? $ws->insertNewRowBefore($at, $n) : $ws->removeRow($at, $n);
                return true;
            case 'ins_col':
            case 'del_col':
                $ws = self::sheetAt($wb, $op['s'] ?? 0);
                $col = Coordinate::stringFromColumnIndex(max(1, min(16384, (int) ($op['at'] ?? 1))));
                $n = max(1, min(200, (int) ($op['n'] ?? 1)));
                $t === 'ins_col' ? $ws->insertNewColumnBefore($col, $n) : $ws->removeColumn($col, $n);
                return true;
            case 'merge':
            case 'unmerge':
                $ws = self::sheetAt($wb, $op['s'] ?? 0);
                $r = self::range($op['r'] ?? '');
                if (str_contains($r, ':')) {
                    $t === 'merge' ? $ws->mergeCells($r) : $ws->unmergeCells($r);
                }
                return true;
            case 'freeze':
                $ws = self::sheetAt($wb, $op['s'] ?? 0);
                $c = (string) ($op['c'] ?? '');
                $c === '' || $c === 'A1' ? $ws->unfreezePane() : $ws->freezePane(self::addr($c));
                return true;
            case 'sheet_add':
                if ($wb->getSheetCount() >= 100) {
                    throw new \InvalidArgumentException('sheets');
                }
                $wb->createSheet()->setTitle(self::sheetTitle($wb, (string) ($op['name'] ?? '')));
                return true;
            case 'sheet_rename':
                $i = (int) ($op['s'] ?? 0);
                self::sheetAt($wb, $i)->setTitle(self::sheetTitle($wb, (string) ($op['name'] ?? ''), $i), true);
                return true;
            case 'sheet_del':
                if ($wb->getSheetCount() > 1) {
                    $i = (int) ($op['s'] ?? 0);
                    self::sheetAt($wb, $i);
                    $wb->removeSheetByIndex($i);
                    $wb->setActiveSheetIndex(0);
                }
                return true;
        }
        throw new \InvalidArgumentException('op');
    }

    /**
     * Apply a batch of operations from one editor, then publish what changed to everyone.
     * @return array{seq:int,ops:array}
     */
    public static function applyOps(array $user, array $file, array $ops): array
    {
        $fid = (int) $file['id'];
        $ops = array_slice($ops, 0, 500);
        $lock = self::lock($fid);
        try {
            $s = DB::one('SELECT * FROM sheet_sessions WHERE file_id = :f', ['f' => $fid]);
            if (!$s || !is_file(self::workPath($fid))) {
                throw new \InvalidArgumentException(t('sheet.session_gone'));
            }
            if ($s['mode'] === 'view') {
                throw new \InvalidArgumentException(t('sheet.read_only'));
            }
            self::setupLocale();
            $wb = IOFactory::createReader('Xlsx')->load(self::workPath($fid));
            $touched = [];
            $structure = false;
            foreach ($ops as $op) {
                if (is_array($op)) {
                    $structure = self::apply($wb, $op, $touched) || $structure;
                }
            }
            Calculation::getInstance($wb)->clearCalculationCache();
            if (self::countCells($wb) > self::MAX_CELLS * 1.5) {
                throw new \InvalidArgumentException(t('sheet.too_many_cells'));
            }
            self::save($wb, $fid);
            DB::update('sheet_sessions', ['dirty' => 1, 'last_user' => (int) $user['id'], 'updated_at' => time()], 'file_id = :f', ['f' => $fid]);
            $out = [];
            if ($structure) {
                $out[] = ['k' => 'reload'];
                @unlink(self::cachePath($fid));
            } else {
                $out = array_merge($out, self::patches($wb, $fid, $touched));
            }
            foreach ($out as $o) {
                DB::insert('sheet_ops', ['file_id' => $fid, 'user_id' => (int) $user['id'], 'op' => json_encode($o + ['by' => (int) $user['id']], JSON_UNESCAPED_UNICODE), 'created_at' => time()]);
            }
            $seq = (int) (DB::value('SELECT MAX(id) FROM sheet_ops WHERE file_id = :f', ['f' => $fid]) ?? 0);
        } finally {
            self::unlock($lock);
        }
        return ['seq' => $seq];
    }

    /**
     * Cells whose text, value or style changed since the last batch, per sheet. Formulas are
     * recalculated everywhere, so a change in one cell reaches every cell depending on it.
     */
    private static function patches(Spreadsheet $wb, int $fid, array $touched = []): array
    {
        $prev = json_decode((string) @file_get_contents(self::cachePath($fid)), true) ?: null;
        $styles = [];
        $now = [];
        foreach ($wb->getAllSheets() as $i => $ws) {
            $now[$i] = (array) self::sheetOut($wb, $ws, $styles)['cells'];
        }
        file_put_contents(self::cachePath($fid), json_encode($now, JSON_UNESCAPED_UNICODE));
        $out = [];
        foreach ($now as $i => $cells) {
            $old = $prev[$i] ?? [];
            $diff = [];
            foreach ($cells as $a => $v) {
                // Cells typed in come back even unchanged: the editor shows them formatted.
                if (($old[$a] ?? null) !== $v || isset($touched[$i][$a])) {
                    $diff[$a] = $v;
                }
            }
            foreach (array_keys($touched[$i] ?? []) as $a) {
                if (!isset($cells[$a]) && !array_key_exists($a, $diff)) {
                    $diff[$a] = null;
                }
            }
            foreach ($old as $a => $v) {
                if (!isset($cells[$a])) {
                    $diff[$a] = null;
                }
            }
            if ($prev === null && !$diff) {
                continue;
            }
            if ($diff) {
                $st = [];
                foreach ($diff as $v) {
                    if ($v !== null) {
                        $st[$v[2]] = $styles[$v[2]] ?? [];
                    }
                }
                $out[] = ['k' => 'patch', 's' => $i, 'cells' => $diff, 'styles' => (object) array_map(static fn($x) => (object) $x, $st)];
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------- polling

    public static function poll(array $user, array $file, int $since, int $sheet, string $cell): array
    {
        $fid = (int) $file['id'];
        DB::run('DELETE FROM sheet_presence WHERE file_id = :f AND user_id = :u', ['f' => $fid, 'u' => $user['id']]);
        DB::insert('sheet_presence', ['file_id' => $fid, 'user_id' => (int) $user['id'], 'sheet' => max(0, $sheet),
            'cell' => preg_match('/^[A-Z]{1,3}\d{1,7}$/', $cell) ? $cell : '', 'seen_at' => time()]);
        $ops = [];
        foreach (DB::all('SELECT id, op FROM sheet_ops WHERE file_id = :f AND id > :s ORDER BY id LIMIT 300', ['f' => $fid, 's' => $since]) as $r) {
            $ops[] = ['seq' => (int) $r['id']] + (json_decode((string) $r['op'], true) ?: []);
        }
        $s = DB::one('SELECT dirty, published_at, base_version FROM sheet_sessions WHERE file_id = :f', ['f' => $fid]);
        return ['ops' => $ops, 'people' => self::people($fid, (int) $user['id']), 'dirty' => (bool) (int) ($s['dirty'] ?? 0),
            'published_at' => (int) ($s['published_at'] ?? 0), 'gone' => $s === null];
    }

    /** Other people with the file open (name, colour, where they are). */
    public static function people(int $fid, int $me): array
    {
        $out = [];
        foreach (DB::all('SELECT p.*, u.display_name, u.email FROM sheet_presence p JOIN users u ON u.id = p.user_id WHERE p.file_id = :f AND p.seen_at > :t AND p.user_id <> :me',
            ['f' => $fid, 't' => time() - self::PRESENCE_TTL, 'me' => $me]) as $p) {
            $out[] = ['id' => (int) $p['user_id'], 'name' => (string) ($p['display_name'] ?: $p['email']), 'sheet' => (int) $p['sheet'], 'cell' => (string) $p['cell'],
                'color' => self::color((int) $p['user_id'])];
        }
        return $out;
    }

    public static function color(int $userId): string
    {
        $palette = ['#e11d48', '#2563eb', '#16a34a', '#d97706', '#7c3aed', '#0891b2', '#db2777', '#65a30d', '#ea580c', '#4f46e5'];
        return $palette[$userId % count($palette)];
    }

    public static function leave(array $user, int $fid): void
    {
        DB::run('DELETE FROM sheet_presence WHERE file_id = :f AND user_id = :u', ['f' => $fid, 'u' => $user['id']]);
    }

    // ---------------------------------------------------------------- publishing

    /**
     * Make the working copy the file's new version (same format), or a new file next to it
     * when the original holds what this editor does not keep or changed meanwhile.
     * @return array{file:array,copy:bool}
     */
    public static function publish(array $user, array $file): array
    {
        $fid = (int) $file['id'];
        $lock = self::lock($fid);
        try {
            $s = DB::one('SELECT * FROM sheet_sessions WHERE file_id = :f', ['f' => $fid]);
            if (!$s || !is_file(self::workPath($fid))) {
                throw new \InvalidArgumentException(t('sheet.session_gone'));
            }
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $changedMeanwhile = (int) $file['version'] !== (int) $s['base_version'];
            $copy = $s['mode'] === 'copy' || $changedMeanwhile;
            $outExt = $ext === 'xls' ? 'xlsx' : $ext;
            self::setupLocale();
            $wb = IOFactory::createReader('Xlsx')->load(self::workPath($fid));
            $tmp = (string) tempnam(Config::storagePath('tmp'), 'shp');
            $w = IOFactory::createWriter($wb, match ($outExt) {
                'ods' => 'Ods',
                'csv', 'tsv' => 'Csv',
                default => 'Xlsx',
            });
            if ($w instanceof \PhpOffice\PhpSpreadsheet\Writer\Csv) {
                $w->setDelimiter($outExt === 'tsv' ? "\t" : (self::fr() ? ';' : ','));
                $w->setUseBOM(true);
                $w->setSheetIndex($wb->getActiveSheetIndex());
            }
            if ($w instanceof \PhpOffice\PhpSpreadsheet\Writer\Xlsx) {
                $w->setPreCalculateFormulas(true);
            }
            $w->save($tmp);
            try {
                if ($copy) {
                    $base = pathinfo($file['name'], PATHINFO_FILENAME);
                    $name = t($changedMeanwhile ? 'sheet.copy_conflict_name' : 'sheet.copy_name', ['name' => $base, 'user' => $user['name'] ?? '']) . '.' . $outExt;
                    $saved = Files::addFromPath($user, (int) $file['folder_id'], $tmp, $name);
                } else {
                    $saved = Files::putContent($user, $fid, $tmp);
                }
            } finally {
                @unlink($tmp);
            }
            if (!$copy) {
                DB::update('sheet_sessions', ['dirty' => 0, 'base_version' => (int) $saved['version'], 'published_at' => time()], 'file_id = :f', ['f' => $fid]);
            } else {
                DB::update('sheet_sessions', ['dirty' => 0, 'published_at' => time()], 'file_id = :f', ['f' => $fid]);
            }
            DB::insert('sheet_ops', ['file_id' => $fid, 'user_id' => (int) $user['id'], 'op' => json_encode(['k' => 'published', 'by' => (int) $user['id'],
                'copy' => $copy ? (string) $saved['name'] : '', 'version' => (int) ($saved['version'] ?? 0)], JSON_UNESCAPED_UNICODE), 'created_at' => time()]);
        } finally {
            self::unlock($lock);
        }
        return ['file' => $saved, 'copy' => $copy];
    }

    /**
     * Scheduler: working copies nobody looks at any more are saved as the last editor
     * (unsaved work is never lost), and finished sessions are cleaned up.
     */
    public static function housekeeping(): int
    {
        $n = 0;
        $quiet = time() - 120;
        foreach (DB::all('SELECT s.* FROM sheet_sessions s WHERE s.dirty = 1 AND s.updated_at < :q AND NOT EXISTS (SELECT 1 FROM sheet_presence p WHERE p.file_id = s.file_id AND p.seen_at > :q)',
            ['q' => $quiet]) as $s) {
            $file = Files::file((int) $s['file_id']);
            $user = $s['last_user'] ? Users::find((int) $s['last_user']) : null;
            if (!$file || !$user || (int) $file['deleted_at'] > 0) {
                continue;
            }
            try {
                self::publish($user, $file);
                $n++;
            } catch (\Throwable $e) {
                error_log('[m4w] sheet autosave ' . $s['file_id'] . ': ' . $e->getMessage());
            }
        }
        foreach (DB::all('SELECT file_id FROM sheet_sessions WHERE dirty = 0 AND updated_at < :t AND published_at < :t', ['t' => time() - 86400]) as $s) {
            $fid = (int) $s['file_id'];
            if (DB::value('SELECT COUNT(*) FROM sheet_presence WHERE file_id = :f AND seen_at > :t', ['f' => $fid, 't' => time() - 3600])) {
                continue;
            }
            DB::run('DELETE FROM sheet_ops WHERE file_id = :f', ['f' => $fid]);
            DB::run('DELETE FROM sheet_sessions WHERE file_id = :f', ['f' => $fid]);
            @unlink(self::workPath($fid));
            @unlink(self::cachePath($fid));
            @unlink(self::dir() . '/' . $fid . '.lock');
        }
        DB::run('DELETE FROM sheet_presence WHERE seen_at < :t', ['t' => time() - 3600]);
        return $n;
    }
}
