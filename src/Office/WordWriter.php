<?php

/**
 * A Word document (.docx, Office Open XML), written directly: the document, its styles and a
 * page header that carries the patient line. Opens in Word, LibreOffice and Google Docs.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Office;

final class WordWriter implements OfficeWriter
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    /** The text width in twentieths of a point: 8.5in less two 0.6in margins (A4 is narrower; Word scales the grid). */
    private const TEXT_WIDTH = 10512;
    private const BOX = "\u{2610}";
    private const BLANK = '______________________________';

    private string $body = '';

    public function extension(): string
    {
        return 'docx';
    }

    public function mimeType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    }

    public function letterhead(string $name, array $lines): void
    {
        $this->body .= $this->p($this->run($name, bold: true, size: 28), '<w:spacing w:after="0"/>');
        foreach ($lines as $line) {
            $this->body .= $this->p($this->run($line, size: 19), '<w:spacing w:after="0"/>');
        }
        $this->body .= $this->p('', self::border() . '<w:spacing w:after="160"/>');
    }

    public function title(string $text): void
    {
        $this->body .= $this->p($this->run($text), '<w:pStyle w:val="Title"/>');
    }

    public function heading(string $text): void
    {
        $this->body .= $this->p($this->run($text), '<w:pStyle w:val="Heading2"/>');
    }

    public function paragraph(string $text): void
    {
        $this->body .= $this->p($this->run($text));
    }

    public function fields(array $fields): void
    {
        foreach ($fields as [$label, $value]) {
            $this->body .= $this->p($this->run($label . ': ', bold: true) . $this->run($value !== '' ? $value : self::BLANK), '<w:spacing w:after="80"/>');
        }
    }

    public function checklist(?string $label, array $items): void
    {
        if ($label !== null) {
            $this->body .= $this->p($this->run($label, bold: true), '<w:keepNext/><w:spacing w:after="40"/>');
        }
        foreach ($items as $item) {
            $this->body .= $this->p($this->run(self::BOX . ' ' . $item), '<w:spacing w:after="20"/>');
        }
    }

    public function lines(string $label, int $count): void
    {
        $this->body .= $this->p($this->run($label, bold: true), '<w:keepNext/><w:spacing w:after="0"/>');
        // A one-column table with a rule under each row: Word and LibreOffice draw consecutive
        // paragraphs with the same border as one box, which would leave a single line.
        $rule = '<w:%s w:val="%s" w:sz="6" w:space="0" w:color="000000"/>';
        $borders = sprintf($rule, 'top', 'nil') . sprintf($rule, 'left', 'nil') . sprintf($rule, 'bottom', 'single')
            . sprintf($rule, 'right', 'nil') . sprintf($rule, 'insideH', 'single') . sprintf($rule, 'insideV', 'nil');
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="' . self::TEXT_WIDTH . '" w:type="dxa"/><w:tblBorders>' . $borders . '</w:tblBorders></w:tblPr>'
            . '<w:tblGrid><w:gridCol w:w="' . self::TEXT_WIDTH . '"/></w:tblGrid>';
        for ($i = 0; $i < $count; $i++) {
            $xml .= '<w:tr><w:trPr><w:trHeight w:val="380"/></w:trPr><w:tc><w:tcPr><w:tcW w:w="' . self::TEXT_WIDTH . '" w:type="dxa"/></w:tcPr><w:p/></w:tc></w:tr>';
        }
        $this->body .= $xml . '</w:tbl>' . $this->p('', '<w:spacing w:after="0"/>');
    }

    public function signatures(array $signers): void
    {
        foreach ($signers as $signer) {
            $this->body .= $this->p('', self::border() . '<w:spacing w:before="560" w:after="0"/><w:ind w:right="4320"/><w:keepNext/>');
            $this->body .= $this->p($this->run($signer, size: 18) . $this->run("\t" . 'Date', size: 18), '<w:tabs><w:tab w:val="right" w:pos="6192"/></w:tabs><w:spacing w:after="120"/>');
        }
    }

    public function scale(string $label, array $columns, array $items, ?array $scores, ?string $total): void
    {
        $this->body .= $this->p($this->run($label, bold: true), '<w:keepNext/>');
        $first = (int)(self::TEXT_WIDTH * 0.5);
        $other = (int)((self::TEXT_WIDTH - $first) / max(1, count($columns)));
        $widths = array_merge([$first], array_fill(0, count($columns), $other));
        $header = [$this->p('')];
        foreach ($columns as $i => $column) {
            $header[] = $this->p($this->run($column, bold: true, size: 17), '<w:jc w:val="center"/><w:spacing w:after="0"/>')
                . ($scores !== null ? $this->p($this->run((string)$scores[$i], size: 16), '<w:jc w:val="center"/><w:spacing w:after="0"/>') : '');
        }
        $rows = [$header];
        foreach ($items as $n => $item) {
            $row = [$this->p($this->run(($n + 1) . '. ' . $item), '<w:spacing w:after="0"/>')];
            foreach ($columns as $unused) {
                $row[] = $this->p($this->run(self::BOX), '<w:jc w:val="center"/><w:spacing w:after="0"/>');
            }
            $rows[] = $row;
        }
        $this->body .= $this->tbl($widths, $rows, 0);
        if ($total !== null) {
            $this->body .= $this->p($this->run($total . ': ', bold: true) . $this->run('__________'), '<w:jc w:val="right"/>');
        } else {
            $this->body .= $this->p('');
        }
    }

    public function table(string $label, array $columns, int $rows): void
    {
        $this->body .= $this->p($this->run($label, bold: true), '<w:keepNext/>');
        $width = (int)(self::TEXT_WIDTH / max(1, count($columns)));
        $cells = [array_map(fn(string $c): string => $this->p($this->run($c, bold: true, size: 17), '<w:spacing w:after="0"/>'), $columns)];
        for ($i = 0; $i < $rows; $i++) {
            $cells[] = array_fill(0, count($columns), $this->p(''));
        }
        $this->body .= $this->tbl(array_fill(0, count($columns), $width), $cells, 400) . $this->p('');
    }

    public function pageBreak(): void
    {
        $this->body .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
    }

    public function file(string $running, string $paper): string
    {
        $size = $paper === 'A4' ? '<w:pgSz w:w="11906" w:h="16838"/>' : '<w:pgSz w:w="12240" w:h="15840"/>';
        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="' . self::W . '" xmlns:r="' . self::R . '"><w:body>' . $this->body
            . '<w:sectPr><w:headerReference w:type="default" r:id="rIdHeader1"/>' . $size
            . '<w:pgMar w:top="864" w:right="864" w:bottom="864" w:left="864" w:header="432" w:footer="432" w:gutter="0"/></w:sectPr>'
            . '</w:body></w:document>';
        $header = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:hdr xmlns:w="' . self::W . '">'
            . $this->p($this->run($running, size: 16, font: 'Arial'), self::border() . '<w:spacing w:after="0"/>') . '</w:hdr>';
        return Zip::build([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
                . '<Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>'
                . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
                . '</Relationships>',
            'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                . '<Relationship Id="rIdHeader1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/>'
                . '</Relationships>',
            'word/document.xml' => $document,
            'word/styles.xml' => self::styles(),
            'word/header1.xml' => $header,
        ]);
    }

    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="' . self::W . '">'
            . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman" w:eastAsia="Times New Roman"/>'
            . '<w:sz w:val="22"/><w:szCs w:val="22"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="100" w:line="264" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="60" w:after="160"/></w:pPr><w:rPr><w:b/><w:sz w:val="30"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="240" w:after="80"/>'
            . '<w:pBdr><w:bottom w:val="single" w:sz="4" w:space="1" w:color="666666"/></w:pBdr></w:pPr><w:rPr><w:b/><w:sz w:val="23"/></w:rPr></w:style>'
            . '</w:styles>';
    }

    private static function border(): string
    {
        return '<w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="000000"/></w:pBdr>';
    }

    private function p(string $runs, string $properties = ''): string
    {
        return '<w:p>' . ($properties !== '' ? '<w:pPr>' . self::orderProperties($properties) . '</w:pPr>' : '') . $runs . '</w:p>';
    }

    /**
     * Word validates the order of paragraph properties (keepNext, pBdr, tabs, spacing, ind, jc,
     * in schema order); callers pass them in any order.
     */
    private static function orderProperties(string $xml): string
    {
        $order = ['pStyle', 'keepNext', 'pBdr', 'tabs', 'spacing', 'ind', 'jc'];
        preg_match_all('#<w:(\w+)(?:\s[^>]*)?(?:/>|>.*?</w:\1>)#s', $xml, $m, PREG_SET_ORDER);
        usort($m, static fn(array $a, array $b): int => array_search($a[1], $order, true) <=> array_search($b[1], $order, true));
        return implode('', array_map(static fn(array $x): string => $x[0], $m));
    }

    private function run(string $text, bool $bold = false, ?int $size = null, ?string $font = null): string
    {
        $props = ($font !== null ? '<w:rFonts w:ascii="' . $font . '" w:hAnsi="' . $font . '"/>' : '') . ($bold ? '<w:b/>' : '')
            . ($size !== null ? '<w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/>' : '');
        $parts = explode("\t", $text);
        $out = '';
        foreach ($parts as $i => $part) {
            $out .= ($i > 0 ? '<w:tab/>' : '') . ($part !== '' ? '<w:t xml:space="preserve">' . self::e($part) . '</w:t>' : '');
        }
        return '<w:r>' . ($props !== '' ? '<w:rPr>' . $props . '</w:rPr>' : '') . $out . '</w:r>';
    }

    /**
     * @param list<int>          $widths
     * @param list<list<string>> $rows   each cell's paragraphs
     */
    private function tbl(array $widths, array $rows, int $rowHeight): string
    {
        $edge = '<w:%s w:val="single" w:sz="4" w:space="0" w:color="000000"/>';
        $borders = implode('', array_map(static fn(string $side): string => sprintf($edge, $side), ['top', 'left', 'bottom', 'right', 'insideH', 'insideV']));
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="' . array_sum($widths) . '" w:type="dxa"/><w:tblBorders>' . $borders . '</w:tblBorders>'
            . '<w:tblCellMar><w:left w:w="72" w:type="dxa"/><w:right w:w="72" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>';
        foreach ($widths as $w) {
            $xml .= '<w:gridCol w:w="' . $w . '"/>';
        }
        $xml .= '</w:tblGrid>';
        foreach ($rows as $r => $cells) {
            $xml .= '<w:tr>' . ($r > 0 && $rowHeight > 0 ? '<w:trPr><w:trHeight w:val="' . $rowHeight . '"/></w:trPr>' : '');
            foreach ($cells as $c => $content) {
                $xml .= '<w:tc><w:tcPr><w:tcW w:w="' . $widths[$c] . '" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>' . ($content !== '' ? $content : '<w:p/>') . '</w:tc>';
            }
            $xml .= '</w:tr>';
        }
        return $xml . '</w:tbl>';
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
