<?php
declare(strict_types=1);

namespace M4W\Service;

/**
 * Word documents ↔ editable HTML, for the built-in document editor.
 *
 * Reading turns word/document.xml into simple HTML (paragraphs, headings, lists, tables,
 * links, pictures, bold/italic/underline/colours/sizes, alignment, page breaks).
 * Writing regenerates only the body of word/document.xml from that HTML and keeps the
 * rest of the package as it was: styles, numbering, headers and footers, page setup,
 * fonts, theme, properties. What the editor cannot keep (tracked changes, comments,
 * text boxes, footnotes, tables of contents, equations…) is reported, and the document
 * is then saved as a copy.
 */
final class WordDoc
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const REL_IMAGE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image';
    private const REL_LINK = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink';
    private const REL_NUMBERING = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering';
    private const MAX_IMAGE = 8 * 1048576;

    private \ZipArchive $zip;
    private \DOMXPath $xp;
    /** @var array<string,array{type:string,target:string,mode:string}> */
    private array $rels = [];
    /** heading level by paragraph style id */
    private array $headings = [];
    /** "numId:ilvl" → 'ul' | 'ol' */
    private array $numFormats = [];

    // ================================================================ reading

    /** @return array{html:string,issues:string[]} */
    public static function toHtml(string $path): array
    {
        $d = new self();
        $d->zip = new \ZipArchive();
        if ($d->zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new \InvalidArgumentException(t('doc.unreadable'));
        }
        try {
            $xml = (string) $d->zip->getFromName('word/document.xml');
            $dom = self::xml($xml);
            if (!$dom) {
                throw new \InvalidArgumentException(t('doc.unreadable'));
            }
            $d->xp = self::xpath($dom);
            $d->rels = self::readRels((string) $d->zip->getFromName('word/_rels/document.xml.rels'));
            $d->readStyles();
            $d->readNumbering();
            $body = $d->xp->query('/w:document/w:body')->item(0);
            $html = $body ? $d->blocks($body) : '';
            return ['html' => $html !== '' ? $html : '<p><br></p>', 'issues' => self::issues($xml)];
        } finally {
            $d->zip->close();
        }
    }

    private static function xml(string $s): ?\DOMDocument
    {
        if ($s === '' || stripos($s, '<!DOCTYPE') !== false || stripos($s, '<!ENTITY') !== false) {
            return null;
        }
        $d = new \DOMDocument();
        return @$d->loadXML($s, LIBXML_NONET | LIBXML_COMPACT) ? $d : null;
    }

    private static function xpath(\DOMDocument $d): \DOMXPath
    {
        $x = new \DOMXPath($d);
        $x->registerNamespace('w', self::W);
        $x->registerNamespace('r', self::R);
        $x->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $x->registerNamespace('wp', 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing');
        $x->registerNamespace('pic', 'http://schemas.openxmlformats.org/drawingml/2006/picture');
        $x->registerNamespace('v', 'urn:schemas-microsoft-com:vml');
        $x->registerNamespace('mc', 'http://schemas.openxmlformats.org/markup-compatibility/2006');
        return $x;
    }

    /** What would be lost by saving through this editor. @return string[] */
    public static function issues(string $documentXml): array
    {
        $checks = [
            'tracked' => '/<w:(ins|del|moveFrom|moveTo)\b/',
            'comments' => '/<w:commentReference\b/',
            'textboxes' => '/<w:txbxContent\b|<v:textbox\b/',
            'notes' => '/<w:(footnote|endnote)Reference\b/',
            'toc' => '/<w:instrText[^>]*>\s*TOC\b/',
            'equations' => '/<m:oMath\b/',
            'sections' => '/<w:pPr>(?:(?!<\/w:pPr>).)*<w:sectPr\b/s',
            'objects' => '/<w:object\b|<c:chart\b/',
            'controls' => '/<w:sdt\b/',
        ];
        $out = [];
        foreach ($checks as $k => $re) {
            if (preg_match($re, $documentXml)) {
                $out[] = $k;
            }
        }
        return $out;
    }

    private static function readRels(string $xml): array
    {
        $out = [];
        $d = self::xml($xml);
        if (!$d) {
            return $out;
        }
        foreach ($d->getElementsByTagName('Relationship') as $r) {
            $out[(string) $r->getAttribute('Id')] = ['type' => (string) $r->getAttribute('Type'), 'target' => (string) $r->getAttribute('Target'),
                'mode' => (string) $r->getAttribute('TargetMode')];
        }
        return $out;
    }

    private function readStyles(): void
    {
        $d = self::xml((string) $this->zip->getFromName('word/styles.xml'));
        if (!$d) {
            return;
        }
        $x = self::xpath($d);
        foreach ($x->query('//w:style[@w:type="paragraph"]') as $st) {
            $id = $st->getAttributeNS(self::W, 'styleId');
            $name = strtolower((string) ($x->query('w:name', $st)->item(0)?->getAttributeNS(self::W, 'val') ?? ''));
            if (preg_match('/^heading\s*([1-6])$/', $name, $m)) {
                $this->headings[$id] = (int) $m[1];
            } elseif ($name === 'title') {
                $this->headings[$id] = 1;
            } elseif ($name === 'subtitle') {
                $this->headings[$id] = 2;
            }
        }
    }

    private function readNumbering(): void
    {
        $d = self::xml((string) $this->zip->getFromName('word/numbering.xml'));
        if (!$d) {
            return;
        }
        $x = self::xpath($d);
        $abstract = [];
        foreach ($x->query('//w:abstractNum') as $an) {
            foreach ($x->query('w:lvl', $an) as $lvl) {
                $fmt = (string) ($x->query('w:numFmt', $lvl)->item(0)?->getAttributeNS(self::W, 'val') ?? 'bullet');
                $abstract[$an->getAttributeNS(self::W, 'abstractNumId')][$lvl->getAttributeNS(self::W, 'ilvl')] = $fmt === 'bullet' || $fmt === 'none' ? 'ul' : 'ol';
            }
        }
        foreach ($x->query('//w:num') as $n) {
            $aid = (string) ($x->query('w:abstractNumId', $n)->item(0)?->getAttributeNS(self::W, 'val') ?? '');
            foreach ($abstract[$aid] ?? [] as $lvl => $kind) {
                $this->numFormats[$n->getAttributeNS(self::W, 'numId') . ':' . $lvl] = $kind;
            }
        }
    }

    private function attr(?\DOMNode $n, string $path, string $attr = 'val'): ?string
    {
        $e = $n ? $this->xp->query($path, $n)->item(0) : null;
        return $e instanceof \DOMElement && $e->hasAttributeNS(self::W, $attr) ? $e->getAttributeNS(self::W, $attr) : ($e ? '' : null);
    }

    /** Body or table cell content: paragraphs and tables, lists grouped. */
    private function blocks(\DOMNode $parent): string
    {
        $html = '';
        $stack = []; // open lists: [kind, level]
        $close = static function (int $toLevel) use (&$stack, &$html): void {
            while ($stack && end($stack)[1] >= $toLevel) {
                $html .= '</li></' . array_pop($stack)[0] . '>';
            }
        };
        foreach ($parent->childNodes as $n) {
            if (!$n instanceof \DOMElement || $n->namespaceURI !== self::W) {
                if ($n instanceof \DOMElement && $n->localName === 'AlternateContent') {
                    continue;
                }
                continue;
            }
            if ($n->localName === 'sdt') {
                $content = $this->xp->query('w:sdtContent', $n)->item(0);
                if ($content) {
                    $close(0);
                    $html .= $this->blocks($content);
                }
                continue;
            }
            if ($n->localName === 'tbl') {
                $close(0);
                $html .= $this->table($n);
                continue;
            }
            if ($n->localName !== 'p') {
                continue;
            }
            $ppr = $this->xp->query('w:pPr', $n)->item(0);
            $numId = $this->attr($ppr, 'w:numPr/w:numId');
            $ilvl = (int) ($this->attr($ppr, 'w:numPr/w:ilvl') ?? 0);
            $inner = $this->runs($n);
            if ($numId !== null && $numId !== '0') {
                $kind = $this->numFormats[$numId . ':' . $ilvl] ?? 'ul';
                if ($stack && end($stack)[1] === $ilvl && end($stack)[0] === $kind) {
                    $html .= '</li><li>';
                } else {
                    while ($stack && (end($stack)[1] > $ilvl || (end($stack)[1] === $ilvl && end($stack)[0] !== $kind))) {
                        $html .= '</li></' . array_pop($stack)[0] . '>';
                    }
                    if ($stack && end($stack)[1] === $ilvl) {
                        $html .= '</li><li>';
                    } else {
                        $html .= '<' . $kind . '><li>';
                        $stack[] = [$kind, $ilvl];
                    }
                }
                $html .= $inner === '' ? '<br>' : $inner;
                continue;
            }
            $close(0);
            $style = (string) ($this->attr($ppr, 'w:pStyle') ?? '');
            $align = (string) ($this->attr($ppr, 'w:jc') ?? '');
            $css = match ($align) {
                'center' => 'text-align:center',
                'right', 'end' => 'text-align:right',
                'both', 'distribute' => 'text-align:justify',
                default => '',
            };
            $tag = isset($this->headings[$style]) ? 'h' . $this->headings[$style] : 'p';
            if ($this->xp->query('.//w:br[@w:type="page"]', $n)->length && trim(strip_tags($inner)) === '') {
                $html .= '<hr class="m4w-pagebreak">';
                continue;
            }
            $html .= '<' . $tag . ($css !== '' ? ' style="' . $css . '"' : '') . '>' . ($inner === '' ? '<br>' : $inner) . '</' . $tag . '>';
        }
        $close(0);
        return $html;
    }

    private function table(\DOMElement $tbl): string
    {
        $html = '<table><tbody>';
        foreach ($this->xp->query('w:tr', $tbl) as $tr) {
            $html .= '<tr>';
            foreach ($this->xp->query('w:tc', $tr) as $tc) {
                $tcPr = $this->xp->query('w:tcPr', $tc)->item(0);
                $vm = $this->attr($tcPr, 'w:vMerge');
                if ($vm !== null && $vm !== 'restart') {
                    $html .= '<td></td>';
                    continue;
                }
                $span = (int) ($this->attr($tcPr, 'w:gridSpan') ?? 1);
                $shade = $this->attr($tcPr, 'w:shd', 'fill');
                $style = $shade && preg_match('/^[0-9A-F]{6}$/i', $shade) ? ' style="background-color:#' . strtolower($shade) . '"' : '';
                $html .= '<td' . ($span > 1 ? ' colspan="' . $span . '"' : '') . $style . '>' . $this->blocks($tc) . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table>';
    }

    /** Text runs of a paragraph (hyperlinks, fields, pictures). */
    private function runs(\DOMElement $p): string
    {
        $out = '';
        $inField = 0;
        $showResult = true;
        foreach ($p->childNodes as $n) {
            if (!$n instanceof \DOMElement) {
                continue;
            }
            if ($n->localName === 'hyperlink') {
                $rid = $n->getAttributeNS(self::R, 'id');
                $href = $this->rels[$rid]['target'] ?? '';
                $inner = '';
                foreach ($this->xp->query('w:r', $n) as $r) {
                    $inner .= $this->run($r, true);
                }
                $out .= preg_match('#^(https?:|mailto:)#i', $href) ? '<a href="' . e($href) . '">' . $inner . '</a>' : $inner;
                continue;
            }
            if ($n->localName === 'fldSimple') {
                foreach ($this->xp->query('w:r', $n) as $r) {
                    $out .= $this->run($r);
                }
                continue;
            }
            if ($n->localName === 'smartTag' || $n->localName === 'ins') {
                foreach ($this->xp->query('w:r', $n) as $r) {
                    $out .= $this->run($r);
                }
                continue;
            }
            if ($n->localName !== 'r') {
                continue;
            }
            // Complex fields: only their displayed result.
            $fld = $this->xp->query('w:fldChar', $n)->item(0);
            if ($fld instanceof \DOMElement) {
                $type = $fld->getAttributeNS(self::W, 'fldCharType');
                if ($type === 'begin') {
                    $inField++;
                    $showResult = false;
                } elseif ($type === 'separate') {
                    $showResult = true;
                } elseif ($type === 'end') {
                    $inField = max(0, $inField - 1);
                    $showResult = true;
                }
                continue;
            }
            if ($inField && !$showResult) {
                continue;
            }
            $out .= $this->run($n);
        }
        return $out;
    }

    private function run(\DOMElement $r, bool $inLink = false): string
    {
        $rpr = $this->xp->query('w:rPr', $r)->item(0);
        $text = '';
        foreach ($r->childNodes as $c) {
            if (!$c instanceof \DOMElement) {
                continue;
            }
            $text .= match ($c->localName) {
                't' => e($c->textContent),
                'tab' => '&emsp;',
                'br', 'cr' => $c->getAttributeNS(self::W, 'type') === 'page' ? '' : '<br>',
                'drawing' => $this->picture($c),
                'pict' => $this->vmlPicture($c),
                'noBreakHyphen' => '-',
                default => '',
            };
        }
        if ($text === '' || !$rpr) {
            return $text;
        }
        $on = function (string $tag) use ($rpr): bool {
            $v = $this->attr($rpr, 'w:' . $tag);
            return $v !== null && !in_array($v, ['0', 'false', 'none'], true);
        };
        $css = [];
        $color = $this->attr($rpr, 'w:color');
        if ($color && preg_match('/^[0-9A-F]{6}$/i', $color) && strtoupper($color) !== '000000' && !($inLink && in_array(strtoupper($color), ['0563C1', '0000FF'], true))) {
            $css[] = 'color:#' . strtolower($color);
        }
        $sz = (int) ($this->attr($rpr, 'w:sz') ?? 0);
        if ($sz > 0 && $sz !== 22 && $sz !== 24) {
            $css[] = 'font-size:' . ($sz / 2) . 'pt';
        }
        $hl = $this->attr($rpr, 'w:highlight');
        $hlMap = ['yellow' => '#ffff00', 'green' => '#00ff00', 'cyan' => '#00ffff', 'magenta' => '#ff00ff', 'blue' => '#0000ff', 'red' => '#ff0000', 'lightGray' => '#d3d3d3', 'darkGray' => '#a9a9a9'];
        if ($hl && isset($hlMap[$hl])) {
            $css[] = 'background-color:' . $hlMap[$hl];
        }
        if ($css) {
            $text = '<span style="' . implode(';', $css) . '">' . $text . '</span>';
        }
        if ($on('strike') || $on('dstrike')) {
            $text = '<s>' . $text . '</s>';
        }
        if ($on('u') && !$inLink) {
            $text = '<u>' . $text . '</u>';
        }
        if ($on('i')) {
            $text = '<em>' . $text . '</em>';
        }
        if ($on('b')) {
            $text = '<strong>' . $text . '</strong>';
        }
        $va = $this->attr($rpr, 'w:vertAlign');
        if ($va === 'superscript') {
            $text = '<sup>' . $text . '</sup>';
        } elseif ($va === 'subscript') {
            $text = '<sub>' . $text . '</sub>';
        }
        return $text;
    }

    /** Older pictures (VML, written by some tools). */
    private function vmlPicture(\DOMElement $pict): string
    {
        $img = $this->xp->query('.//v:imagedata', $pict)->item(0);
        if (!$img instanceof \DOMElement) {
            return '';
        }
        $w = 0;
        $shape = $this->xp->query('.//v:shape', $pict)->item(0);
        if ($shape instanceof \DOMElement && preg_match('/width\s*:\s*([\d.]+)(pt|px)/', $shape->getAttribute('style'), $m)) {
            $w = (int) round((float) $m[1] * ($m[2] === 'pt' ? 4 / 3 : 1));
        }
        return $this->imageTag($img->getAttributeNS(self::R, 'id'), $w);
    }

    private function picture(\DOMElement $drawing): string
    {
        $blip = $this->xp->query('.//a:blip', $drawing)->item(0);
        if (!$blip instanceof \DOMElement) {
            return '';
        }
        $ext = $this->xp->query('.//wp:extent', $drawing)->item(0);
        return $this->imageTag($blip->getAttributeNS(self::R, 'embed'), $ext instanceof \DOMElement ? (int) round((int) $ext->getAttribute('cx') / 9525) : 0);
    }

    private function imageTag(string $rid, int $w): string
    {
        $target = $this->rels[$rid]['target'] ?? '';
        if ($target === '' || str_contains($target, '..')) {
            return '';
        }
        $bytes = $this->zip->getFromName('word/' . ltrim($target, '/'));
        if (!is_string($bytes) || strlen($bytes) > self::MAX_IMAGE) {
            return '';
        }
        $mime = match (strtolower(pathinfo($target, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => '',
        };
        if ($mime === '') {
            return '';
        }
        return '<img src="data:' . $mime . ';base64,' . base64_encode($bytes) . '"' . ($w > 0 ? ' width="' . $w . '"' : '') . ' alt="">';
    }

    // ================================================================ writing

    /**
     * New .docx from $template (the original, or a blank one) with $html as its body.
     * Everything else in the package is kept.
     */
    public static function fromHtml(string $html, ?string $template, string $out): void
    {
        $tpl = $template ?? self::blank();
        if (!copy($tpl, $out)) {
            throw new \RuntimeException('copy');
        }
        if ($template === null) {
            @unlink($tpl);
        }
        $w = new DocxWriter($out);
        $w->writeBody($html);
    }

    /** Minimal Word package (new documents, and .doc / .odt saved as .docx). */
    public static function blank(): string
    {
        $p = (string) tempnam(sys_get_temp_dir(), 'docx');
        $z = new \ZipArchive();
        $z->open($p, \ZipArchive::OVERWRITE);
        $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/><Default Extension="jpeg" ContentType="image/jpeg"/><Default Extension="gif" ContentType="image/gif"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/></Types>');
        $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $z->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="rId2" Type="' . self::REL_NUMBERING . '" Target="numbering.xml"/></Relationships>');
        $heading = static fn(int $n, int $sz) => '<w:style w:type="paragraph" w:styleId="Heading' . $n . '"><w:name w:val="heading ' . $n . '"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>'
            . '<w:pPr><w:keepNext/><w:spacing w:before="240" w:after="80"/><w:outlineLvl w:val="' . ($n - 1) . '"/></w:pPr><w:rPr><w:b/><w:color w:val="1F3864"/><w:sz w:val="' . $sz . '"/></w:rPr></w:style>';
        $z->addFromString('word/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="' . self::W . '">'
            . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/><w:sz w:val="22"/><w:lang w:val="fr-FR"/></w:rPr></w:rPrDefault>'
            . '<w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="264" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
            . $heading(1, 36) . $heading(2, 30) . $heading(3, 26) . $heading(4, 24) . $heading(5, 22) . $heading(6, 22)
            . '<w:style w:type="table" w:styleId="TableGrid"><w:name w:val="Table Grid"/><w:tblPr><w:tblBorders><w:top w:val="single" w:sz="4" w:color="auto"/><w:left w:val="single" w:sz="4" w:color="auto"/>'
            . '<w:bottom w:val="single" w:sz="4" w:color="auto"/><w:right w:val="single" w:sz="4" w:color="auto"/><w:insideH w:val="single" w:sz="4" w:color="auto"/><w:insideV w:val="single" w:sz="4" w:color="auto"/></w:tblBorders></w:tblPr></w:style>'
            . '<w:style w:type="character" w:styleId="Hyperlink"><w:name w:val="Hyperlink"/><w:rPr><w:color w:val="0563C1"/><w:u w:val="single"/></w:rPr></w:style></w:styles>');
        $z->addFromString('word/numbering.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:numbering xmlns:w="' . self::W . '"></w:numbering>');
        $z->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="' . self::W . '" xmlns:r="' . self::R . '"><w:body><w:p/>'
            . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1417" w:right="1417" w:bottom="1417" w:left="1417" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr></w:body></w:document>');
        $z->close();
        return $p;
    }
}

/**
 * Writes an HTML body into an existing .docx package (see WordDoc::fromHtml).
 * @internal
 */
final class DocxWriter
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private \ZipArchive $z;
    private \DOMDocument $rels;
    private int $relSeq = 1000;
    private int $imgSeq = 0;
    private int $drawSeq = 5000;
    /** heading level → style id */
    private array $headingStyle = [];
    private ?string $tableStyle = null;
    private ?int $bulletNum = null;
    private ?int $decimalNum = null;
    private array $numbers = [];
    private ?\DOMDocument $numbering = null;
    private array $contentDefaults = [];

    public function __construct(string $path)
    {
        $this->z = new \ZipArchive();
        if ($this->z->open($path) !== true) {
            throw new \RuntimeException('docx');
        }
    }

    private static function xml(string $s): \DOMDocument
    {
        $d = new \DOMDocument();
        if ($s === '' || stripos($s, '<!DOCTYPE') !== false || !@$d->loadXML($s, LIBXML_NONET)) {
            throw new \RuntimeException('xml');
        }
        return $d;
    }

    public function writeBody(string $html): void
    {
        $doc = self::xml((string) $this->z->getFromName('word/document.xml'));
        $relsXml = (string) $this->z->getFromName('word/_rels/document.xml.rels');
        $this->rels = self::xml($relsXml !== '' ? $relsXml : '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>');
        foreach ($this->rels->getElementsByTagName('Relationship') as $r) {
            if (preg_match('/^rId(\d+)$/', $r->getAttribute('Id'), $m)) {
                $this->relSeq = max($this->relSeq, (int) $m[1] + 1);
            }
        }
        $this->readStyles();
        $this->readNumbering();

        $x = new \DOMXPath($doc);
        $x->registerNamespace('w', self::W);
        $body = $x->query('/w:document/w:body')->item(0);
        if (!$body instanceof \DOMElement) {
            throw new \RuntimeException('body');
        }
        $sect = null;
        foreach ($body->childNodes as $c) {
            if ($c instanceof \DOMElement && $c->localName === 'sectPr') {
                $sect = $c;
            }
        }
        $sectXml = $sect ? $doc->saveXML($sect) : '';
        $root = $doc->documentElement;
        // Prefixes our markup uses, declared on the root as Word expects.
        foreach (['r' => self::R, 'wp' => 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing',
            'a' => 'http://schemas.openxmlformats.org/drawingml/2006/main', 'pic' => 'http://schemas.openxmlformats.org/drawingml/2006/picture'] as $p => $ns) {
            if (!$root->lookupNamespaceURI($p)) {
                $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:' . $p, $ns);
            }
        }
        $inner = $this->html($html);
        $xmlStr = $doc->saveXML();
        $start = strpos($xmlStr, '<w:body>');
        $end = strrpos($xmlStr, '</w:body>');
        if ($start === false || $end === false) {
            // Self-closed or prefixed differently: rebuild the body element.
            while ($body->firstChild) {
                $body->removeChild($body->firstChild);
            }
            $xmlStr = $doc->saveXML();
            $xmlStr = preg_replace('#<w:body\s*/>#', '<w:body></w:body>', $xmlStr) ?? $xmlStr;
            $start = strpos($xmlStr, '<w:body>');
            $end = strrpos($xmlStr, '</w:body>');
        }
        $xmlStr = substr($xmlStr, 0, $start + 8) . $inner . $sectXml . substr($xmlStr, $end);
        self::xml($xmlStr); // well-formed, or nothing is written
        $this->z->addFromString('word/document.xml', $xmlStr);
        $this->z->addFromString('word/_rels/document.xml.rels', $this->rels->saveXML());
        if ($this->numbering) {
            $this->z->addFromString('word/numbering.xml', $this->numbering->saveXML());
        }
        $this->contentTypes();
        $this->z->close();
    }

    // ---------------------------------------------------------------- package

    private function readStyles(): void
    {
        $s = (string) $this->z->getFromName('word/styles.xml');
        if ($s === '') {
            return;
        }
        $d = self::xml($s);
        $x = new \DOMXPath($d);
        $x->registerNamespace('w', self::W);
        foreach ($x->query('//w:style') as $st) {
            $id = $st->getAttributeNS(self::W, 'styleId');
            $name = strtolower((string) ($x->query('w:name', $st)->item(0)?->getAttributeNS(self::W, 'val') ?? ''));
            if ($st->getAttributeNS(self::W, 'type') === 'paragraph' && preg_match('/^heading\s*([1-6])$/', $name, $m)) {
                $this->headingStyle[(int) $m[1]] = $id;
            }
            if ($st->getAttributeNS(self::W, 'type') === 'table' && in_array($name, ['table grid', 'grille du tableau'], true)) {
                $this->tableStyle = $id;
            }
        }
    }

    private function readNumbering(): void
    {
        $s = (string) $this->z->getFromName('word/numbering.xml');
        if ($s === '') {
            $s = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:numbering xmlns:w="' . self::W . '"></w:numbering>';
            $this->addRel('http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering', 'numbering.xml');
            $this->contentDefaults['/word/numbering.xml'] = 'application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml';
        }
        $this->numbering = self::xml($s);
    }

    /** Our own list definitions (bullets, 1. 2. 3.), added once to numbering.xml. */
    private function numId(string $kind): int
    {
        if ($kind === 'ul' && $this->bulletNum !== null) {
            return $this->bulletNum;
        }
        if ($kind === 'ol' && $this->decimalNum !== null) {
            return $this->decimalNum;
        }
        $d = $this->numbering;
        $x = new \DOMXPath($d);
        $x->registerNamespace('w', self::W);
        $maxA = 0;
        foreach ($x->query('//w:abstractNum') as $a) {
            $maxA = max($maxA, (int) $a->getAttributeNS(self::W, 'abstractNumId'));
        }
        $maxN = 0;
        foreach ($x->query('//w:num') as $n) {
            $maxN = max($maxN, (int) $n->getAttributeNS(self::W, 'numId'));
        }
        $aid = $maxA + 1;
        $nid = $maxN + 1;
        $lvls = '';
        $bullets = ['•', 'o', '▪'];
        for ($i = 0; $i < 9; $i++) {
            $fmt = $kind === 'ul' ? 'bullet' : (['decimal', 'lowerLetter', 'lowerRoman'][$i % 3]);
            $text = $kind === 'ul' ? $bullets[$i % 3] : '%' . ($i + 1) . '.';
            $lvls .= '<w:lvl w:ilvl="' . $i . '"><w:start w:val="1"/><w:numFmt w:val="' . $fmt . '"/><w:lvlText w:val="' . $text . '"/><w:lvlJc w:val="left"/>'
                . '<w:pPr><w:ind w:left="' . (720 * ($i + 1)) . '" w:hanging="360"/></w:pPr></w:lvl>';
        }
        $frag = self::xml('<w:numbering xmlns:w="' . self::W . '"><w:abstractNum w:abstractNumId="' . $aid . '"><w:multiLevelType w:val="hybridMultilevel"/>' . $lvls . '</w:abstractNum>'
            . '<w:num w:numId="' . $nid . '"><w:abstractNumId w:val="' . $aid . '"/></w:num></w:numbering>');
        $root = $d->documentElement;
        $abs = $d->importNode($frag->documentElement->firstChild, true);
        $num = $d->importNode($frag->documentElement->lastChild, true);
        // abstractNum elements must come before every num element.
        $firstNum = $x->query('/w:numbering/w:num')->item(0);
        $firstNum ? $root->insertBefore($abs, $firstNum) : $root->appendChild($abs);
        $root->appendChild($num);
        if ($kind === 'ul') {
            $this->bulletNum = $nid;
        } else {
            $this->decimalNum = $nid;
        }
        return $nid;
    }

    private function addRel(string $type, string $target, bool $external = false): string
    {
        $id = 'rId' . $this->relSeq++;
        $r = $this->rels->createElementNS('http://schemas.openxmlformats.org/package/2006/relationships', 'Relationship');
        $r->setAttribute('Id', $id);
        $r->setAttribute('Type', $type);
        $r->setAttribute('Target', $target);
        if ($external) {
            $r->setAttribute('TargetMode', 'External');
        }
        $this->rels->documentElement->appendChild($r);
        return $id;
    }

    private function contentTypes(): void
    {
        $ct = self::xml((string) $this->z->getFromName('[Content_Types].xml'));
        $root = $ct->documentElement;
        $have = [];
        foreach ($ct->getElementsByTagName('Default') as $d) {
            $have[strtolower($d->getAttribute('Extension'))] = true;
        }
        foreach (['png' => 'image/png', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif'] as $ext => $type) {
            if (!isset($have[$ext])) {
                $e = $ct->createElementNS('http://schemas.openxmlformats.org/package/2006/content-types', 'Default');
                $e->setAttribute('Extension', $ext);
                $e->setAttribute('ContentType', $type);
                $root->insertBefore($e, $root->firstChild);
            }
        }
        $over = [];
        foreach ($ct->getElementsByTagName('Override') as $o) {
            $over[$o->getAttribute('PartName')] = true;
        }
        foreach ($this->contentDefaults as $part => $type) {
            if (!isset($over[$part])) {
                $e = $ct->createElementNS('http://schemas.openxmlformats.org/package/2006/content-types', 'Override');
                $e->setAttribute('PartName', $part);
                $e->setAttribute('ContentType', $type);
                $root->appendChild($e);
            }
        }
        $this->z->addFromString('[Content_Types].xml', $ct->saveXML());
    }

    // ---------------------------------------------------------------- HTML → WordprocessingML

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function html(string $html): string
    {
        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . html_ascii($html) . '</body></html>', LIBXML_NONET | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $body = $dom->getElementsByTagName('body')->item(0);
        $out = $body ? $this->blocks($body, 0) : '';
        return $out !== '' ? $out : '<w:p/>';
    }

    /** Block-level content; loose inline content is gathered into paragraphs. */
    private function blocks(\DOMNode $parent, int $depth): string
    {
        $out = '';
        $pending = [];
        $flush = function () use (&$pending, &$out): void {
            if ($pending) {
                $runs = '';
                foreach ($pending as $n) {
                    $runs .= $this->inline($n, []);
                }
                if (trim(strip_tags($runs)) !== '' || str_contains($runs, '<w:drawing')) {
                    $out .= '<w:p>' . $runs . '</w:p>';
                }
                $pending = [];
            }
        };
        foreach ($parent->childNodes as $n) {
            $tag = $n instanceof \DOMElement ? strtolower($n->nodeName) : '#text';
            switch ($tag) {
                case 'p':
                case 'div':
                case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
                case 'blockquote':
                case 'pre':
                    $flush();
                    if ($tag === 'div' && $this->hasBlocks($n)) {
                        $out .= $this->blocks($n, $depth);
                    } else {
                        $out .= $this->paragraph($n, $tag);
                    }
                    break;
                case 'ul':
                case 'ol':
                    $flush();
                    $out .= $this->listXml($n, $tag, 0);
                    break;
                case 'table':
                    $flush();
                    $out .= $this->tableXml($n, $depth);
                    break;
                case 'hr':
                    $flush();
                    $out .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
                    break;
                default:
                    $pending[] = $n;
            }
        }
        $flush();
        return $out;
    }

    private function hasBlocks(\DOMNode $n): bool
    {
        foreach ($n->childNodes as $c) {
            if ($c instanceof \DOMElement && in_array(strtolower($c->nodeName), ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'table', 'blockquote', 'hr', 'pre'], true)) {
                return true;
            }
        }
        return false;
    }

    private function align(\DOMNode $n): string
    {
        $style = $n instanceof \DOMElement ? strtolower($n->getAttribute('style') . ';' . $n->getAttribute('align')) : '';
        if (preg_match('/text-align\s*:\s*(center|right|justify)|^;?(center|right|justify)/', $style, $m)) {
            $a = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
            return '<w:jc w:val="' . ($a === 'justify' ? 'both' : $a) . '"/>';
        }
        return '';
    }

    private function paragraph(\DOMNode $n, string $tag, string $extraPpr = ''): string
    {
        $ppr = '';
        if (preg_match('/^h([1-6])$/', $tag, $m)) {
            $level = (int) $m[1];
            $ppr .= isset($this->headingStyle[$level]) ? '<w:pStyle w:val="' . self::esc($this->headingStyle[$level]) . '"/>' : '<w:outlineLvl w:val="' . ($level - 1) . '"/>';
        }
        $ppr .= $extraPpr;
        if ($tag === 'blockquote') {
            $ppr .= '<w:ind w:left="720"/>';
        }
        $ppr .= $this->align($n);
        $fmt = [];
        if (preg_match('/^h([1-6])$/', $tag, $m) && !isset($this->headingStyle[(int) $m[1]])) {
            $fmt = ['b' => true, 'sz' => [1 => 36, 30, 26, 24, 22, 22][(int) $m[1]]];
        }
        if ($tag === 'pre') {
            $fmt['mono'] = true;
        }
        $runs = '';
        foreach ($n->childNodes as $c) {
            $runs .= $this->inline($c, $fmt);
        }
        return '<w:p>' . ($ppr !== '' ? '<w:pPr>' . $ppr . '</w:pPr>' : '') . $runs . '</w:p>';
    }

    private function listXml(\DOMNode $list, string $kind, int $level): string
    {
        $out = '';
        $num = $this->numId($kind);
        foreach ($list->childNodes as $li) {
            if (!$li instanceof \DOMElement) {
                continue;
            }
            if (in_array(strtolower($li->nodeName), ['ul', 'ol'], true)) {
                $out .= $this->listXml($li, strtolower($li->nodeName), min(8, $level + 1));
                continue;
            }
            // Item text first, then its nested lists.
            $runs = '';
            $nested = '';
            foreach ($li->childNodes as $c) {
                $t = $c instanceof \DOMElement ? strtolower($c->nodeName) : '';
                if ($t === 'ul' || $t === 'ol') {
                    $nested .= $this->listXml($c, $t, min(8, $level + 1));
                } elseif ($t === 'p' || $t === 'div') {
                    foreach ($c->childNodes as $cc) {
                        $runs .= $this->inline($cc, []);
                    }
                } else {
                    $runs .= $this->inline($c, []);
                }
            }
            $out .= '<w:p><w:pPr><w:numPr><w:ilvl w:val="' . $level . '"/><w:numId w:val="' . $num . '"/></w:numPr>' . $this->align($li) . '</w:pPr>' . $runs . '</w:p>' . $nested;
        }
        return $out;
    }

    private function tableXml(\DOMNode $table, int $depth): string
    {
        $rows = [];
        $collect = static function (\DOMNode $n) use (&$collect, &$rows): void {
            foreach ($n->childNodes as $c) {
                if (!$c instanceof \DOMElement) {
                    continue;
                }
                $t = strtolower($c->nodeName);
                if ($t === 'tr') {
                    $rows[] = $c;
                } elseif (in_array($t, ['thead', 'tbody', 'tfoot'], true)) {
                    $collect($c);
                }
            }
        };
        $collect($table);
        if (!$rows) {
            return '';
        }
        $cols = 0;
        foreach ($rows as $tr) {
            $n = 0;
            foreach ($tr->childNodes as $td) {
                if ($td instanceof \DOMElement && in_array(strtolower($td->nodeName), ['td', 'th'], true)) {
                    $n += max(1, (int) $td->getAttribute('colspan'));
                }
            }
            $cols = max($cols, $n);
        }
        $cols = max(1, min(63, $cols));
        $w = (int) floor(9000 / $cols);
        $xml = '<w:tbl><w:tblPr>' . ($this->tableStyle ? '<w:tblStyle w:val="' . self::esc($this->tableStyle) . '"/>' : '')
            . '<w:tblW w:w="5000" w:type="pct"/><w:tblBorders><w:top w:val="single" w:sz="4" w:color="auto"/><w:left w:val="single" w:sz="4" w:color="auto"/><w:bottom w:val="single" w:sz="4" w:color="auto"/>'
            . '<w:right w:val="single" w:sz="4" w:color="auto"/><w:insideH w:val="single" w:sz="4" w:color="auto"/><w:insideV w:val="single" w:sz="4" w:color="auto"/></w:tblBorders><w:tblLook w:val="04A0"/></w:tblPr><w:tblGrid>'
            . str_repeat('<w:gridCol w:w="' . $w . '"/>', $cols) . '</w:tblGrid>';
        foreach ($rows as $tr) {
            $xml .= '<w:tr>';
            $used = 0;
            foreach ($tr->childNodes as $td) {
                if (!$td instanceof \DOMElement || !in_array(strtolower($td->nodeName), ['td', 'th'], true)) {
                    continue;
                }
                $span = max(1, min($cols - $used, (int) $td->getAttribute('colspan') ?: 1));
                $used += $span;
                $tcpr = '<w:tcW w:w="' . ($w * $span) . '" w:type="dxa"/>' . ($span > 1 ? '<w:gridSpan w:val="' . $span . '"/>' : '');
                if (preg_match('/background(?:-color)?\s*:\s*#([0-9a-f]{6})/i', $td->getAttribute('style'), $m)) {
                    $tcpr .= '<w:shd w:val="clear" w:color="auto" w:fill="' . strtoupper($m[1]) . '"/>';
                }
                $content = $this->hasBlocks($td) ? $this->blocks($td, $depth + 1) : $this->paragraph($td, strtolower($td->nodeName) === 'th' ? 'th' : 'p');
                if (strtolower($td->nodeName) === 'th' && !$this->hasBlocks($td)) {
                    $content = str_replace('<w:r>', '<w:r><w:rPr><w:b/></w:rPr>', $content);
                    $content = str_replace('<w:r><w:rPr><w:b/></w:rPr><w:rPr>', '<w:r><w:rPr><w:b/>', $content);
                }
                if ($content === '' || str_ends_with($content, '</w:tbl>')) {
                    $content .= '<w:p/>'; // a cell ends with a paragraph
                }
                $xml .= '<w:tc><w:tcPr>' . $tcpr . '</w:tcPr>' . $content . '</w:tc>';
            }
            // Word wants every grid column filled.
            for (; $used < $cols; $used++) {
                $xml .= '<w:tc><w:tcPr><w:tcW w:w="' . $w . '" w:type="dxa"/></w:tcPr><w:p/></w:tc>';
            }
            $xml .= '</w:tr>';
        }
        return $xml . '</w:tbl>';
    }

    /** @param array<string,mixed> $fmt inherited formatting */
    private function inline(\DOMNode $n, array $fmt): string
    {
        if ($n instanceof \DOMText) {
            $t = preg_replace('/[ \t\r\n]+/', ' ', $n->data) ?? '';
            return $t === '' ? '' : $this->run($t, $fmt);
        }
        if (!$n instanceof \DOMElement) {
            return '';
        }
        $tag = strtolower($n->nodeName);
        switch ($tag) {
            case 'br':
                return '<w:r><w:br/></w:r>';
            case 'img':
                return $this->image($n);
            case 'strong': case 'b': $fmt['b'] = true; break;
            case 'em': case 'i': $fmt['i'] = true; break;
            case 'u': $fmt['u'] = true; break;
            case 's': case 'strike': case 'del': $fmt['s'] = true; break;
            case 'sup': $fmt['va'] = 'superscript'; break;
            case 'sub': $fmt['va'] = 'subscript'; break;
            case 'code': $fmt['mono'] = true; break;
            case 'a':
                $href = trim($n->getAttribute('href'));
                if (preg_match('#^(https?:|mailto:)#i', $href) && strlen($href) < 2000) {
                    $rid = $this->addRel('http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink', $href, true);
                    $runs = '';
                    $fmt['link'] = true;
                    foreach ($n->childNodes as $c) {
                        $runs .= $this->inline($c, $fmt);
                    }
                    return '<w:hyperlink r:id="' . $rid . '">' . $runs . '</w:hyperlink>';
                }
                break;
            case 'script': case 'style':
                return '';
        }
        $style = $n->getAttribute('style');
        if ($style !== '') {
            if (preg_match('/(?:^|;)\s*color\s*:\s*(#[0-9a-f]{3,6}|rgb\([^)]*\))/i', $style, $m) && ($c = self::hex($m[1]))) {
                $fmt['color'] = $c;
            }
            if (preg_match('/background(?:-color)?\s*:\s*(#[0-9a-f]{3,6}|rgb\([^)]*\))/i', $style, $m) && ($c = self::hex($m[1]))) {
                $fmt['bg'] = $c;
            }
            if (preg_match('/font-size\s*:\s*([\d.]+)\s*(pt|px)/i', $style, $m)) {
                $pt = strtolower($m[2]) === 'px' ? (float) $m[1] * 0.75 : (float) $m[1];
                $fmt['sz'] = (int) round(max(6, min(96, $pt)) * 2);
            }
            if (preg_match('/font-weight\s*:\s*(bold|[6-9]00)/i', $style)) {
                $fmt['b'] = true;
            }
            if (preg_match('/font-style\s*:\s*italic/i', $style)) {
                $fmt['i'] = true;
            }
            if (preg_match('/text-decoration[^;]*underline/i', $style)) {
                $fmt['u'] = true;
            }
            if (preg_match('/text-decoration[^;]*line-through/i', $style)) {
                $fmt['s'] = true;
            }
        }
        $out = '';
        foreach ($n->childNodes as $c) {
            $out .= $this->inline($c, $fmt);
        }
        return $out;
    }

    private static function hex(string $c): ?string
    {
        if (preg_match('/^#([0-9a-f]{6})$/i', $c, $m)) {
            return strtoupper($m[1]);
        }
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/i', $c, $m)) {
            return strtoupper($m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3]);
        }
        if (preg_match('/rgb\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i', $c, $m)) {
            return sprintf('%02X%02X%02X', min(255, (int) $m[1]), min(255, (int) $m[2]), min(255, (int) $m[3]));
        }
        return null;
    }

    private function run(string $text, array $fmt): string
    {
        $rpr = '';
        if (!empty($fmt['link'])) {
            $rpr .= '<w:rStyle w:val="Hyperlink"/>';
        }
        if (!empty($fmt['mono'])) {
            $rpr .= '<w:rFonts w:ascii="Consolas" w:hAnsi="Consolas" w:cs="Consolas"/>';
        }
        if (!empty($fmt['b'])) {
            $rpr .= '<w:b/>';
        }
        if (!empty($fmt['i'])) {
            $rpr .= '<w:i/>';
        }
        if (!empty($fmt['s'])) {
            $rpr .= '<w:strike/>';
        }
        if (!empty($fmt['color'])) {
            $rpr .= '<w:color w:val="' . $fmt['color'] . '"/>';
        } elseif (!empty($fmt['link'])) {
            $rpr .= '<w:color w:val="0563C1"/>';
        }
        if (!empty($fmt['sz'])) {
            $rpr .= '<w:sz w:val="' . (int) $fmt['sz'] . '"/><w:szCs w:val="' . (int) $fmt['sz'] . '"/>';
        }
        if (!empty($fmt['bg'])) {
            $rpr .= '<w:shd w:val="clear" w:color="auto" w:fill="' . $fmt['bg'] . '"/>';
        }
        if (!empty($fmt['u']) || !empty($fmt['link'])) {
            $rpr .= '<w:u w:val="single"/>';
        }
        if (!empty($fmt['va'])) {
            $rpr .= '<w:vertAlign w:val="' . $fmt['va'] . '"/>';
        }
        // A tab typed in the editor arrives as an em space.
        $parts = explode("\u{2003}", $text);
        $runs = '';
        foreach ($parts as $i => $p) {
            if ($i > 0) {
                $runs .= '<w:tab/>';
            }
            if ($p !== '') {
                $runs .= '<w:t xml:space="preserve">' . self::esc($p) . '</w:t>';
            }
        }
        return '<w:r>' . ($rpr !== '' ? '<w:rPr>' . $rpr . '</w:rPr>' : '') . $runs . '</w:r>';
    }

    private function image(\DOMElement $img): string
    {
        $src = $img->getAttribute('src');
        if (!preg_match('#^data:image/(png|jpe?g|gif);base64,(.+)$#is', $src, $m)) {
            return '';
        }
        $bytes = base64_decode($m[2], true);
        if ($bytes === false || strlen($bytes) > 8 * 1048576) {
            return '';
        }
        $info = @getimagesizefromstring($bytes);
        if (!$info) {
            return '';
        }
        $ext = strtolower($m[1]) === 'jpg' ? 'jpeg' : strtolower($m[1]);
        $name = 'media/m4w-image' . (++$this->imgSeq) . '-' . substr(sha1($bytes), 0, 8) . '.' . $ext;
        $this->z->addFromString('word/' . $name, $bytes);
        $rid = $this->addRel('http://schemas.openxmlformats.org/officeDocument/2006/relationships/image', $name);
        $wPx = (int) $img->getAttribute('width') ?: (int) $info[0];
        if (preg_match('/width\s*:\s*(\d+)px/i', $img->getAttribute('style'), $mm)) {
            $wPx = (int) $mm[1];
        }
        $wPx = max(8, min(620, $wPx));
        $hPx = (int) round($wPx * $info[1] / max(1, $info[0]));
        $cx = $wPx * 9525;
        $cy = $hPx * 9525;
        $id = ++$this->drawSeq;
        return '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:docPr id="' . $id . '" name="Image ' . $id . '"/>'
            . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic><pic:nvPicPr><pic:cNvPr id="' . $id . '" name="Image ' . $id . '"/><pic:cNvPicPr/></pic:nvPicPr>'
            . '<pic:blipFill><a:blip r:embed="' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
    }
}
