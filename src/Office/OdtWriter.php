<?php

/**
 * A LibreOffice Writer document (.odt, OpenDocument Text), written directly: the content, the
 * page layout and a page header that carries the patient line. Opens in LibreOffice, Word and
 * Google Docs.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Office;

final class OdtWriter implements OfficeWriter
{
    private const NS = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"'
        . ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
        . ' xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0"';
    private const MIME = 'application/vnd.oasis.opendocument.text';
    private const BOX = "\u{2610}";
    private const BLANK = '______________________________';

    private string $body = '';

    /** @param string $dateLabel the word printed under a signature line beside it */
    public function __construct(private readonly string $dateLabel = 'Date')
    {
    }
    /** @var array<string, string> extra automatic styles (table columns), by name */
    private array $columns = [];

    public function extension(): string
    {
        return 'odt';
    }

    public function mimeType(): string
    {
        return self::MIME;
    }

    public function letterhead(string $name, array $lines): void
    {
        $this->body .= $this->p('Clinic', self::e($name));
        foreach ($lines as $line) {
            $this->body .= $this->p('Small', self::e($line));
        }
        $this->body .= $this->p('Rule', '');
    }

    public function title(string $text): void
    {
        $this->body .= '<text:h text:style-name="Title" text:outline-level="1">' . self::e($text) . '</text:h>';
    }

    public function heading(string $text): void
    {
        $this->body .= '<text:h text:style-name="H2" text:outline-level="2">' . self::e($text) . '</text:h>';
    }

    public function paragraph(string $text): void
    {
        $this->body .= $this->p('Body', self::e($text));
    }

    public function stamp(string $text): void
    {
        $this->body .= $this->p('Stamp', self::e($text));
    }

    public function fields(array $fields): void
    {
        foreach ($fields as [$label, $value]) {
            $this->body .= $this->p('Body', $this->bold($label . ': ') . self::e($value !== '' ? $value : self::BLANK));
        }
    }

    public function checklist(?string $label, array $items): void
    {
        if ($label !== null) {
            $this->body .= $this->p('Label', self::e($label));
        }
        foreach ($items as $item) {
            $this->body .= $this->p('Item', self::e(self::BOX . ' ' . $item));
        }
    }

    public function lines(string $label, int $count): void
    {
        $this->body .= $this->p('Label', self::e($label)) . str_repeat($this->p('Line', ''), $count);
    }

    public function signatures(array $signers): void
    {
        foreach ($signers as $signer) {
            $this->body .= $this->p('Sign', '') . $this->p('SignUnder', self::e($signer) . '<text:tab/>' . self::e($this->dateLabel));
        }
    }

    public function scale(string $label, array $columns, array $items, ?array $scores, ?string $total): void
    {
        $this->body .= $this->p('Label', self::e($label));
        $n = max(1, count($columns));
        $narrow = round(3.65 / $n, 3);
        $header = ['<text:p text:style-name="Cell"/>'];
        foreach ($columns as $i => $column) {
            $header[] = $this->p('CellHead', self::e($column)) . ($scores !== null ? $this->p('CellCenter', self::e((string)$scores[$i])) : '');
        }
        $rows = [$header];
        foreach ($items as $k => $item) {
            $row = [$this->p('Cell', self::e(($k + 1) . '. ' . $item))];
            foreach ($columns as $unused) {
                $row[] = $this->p('CellCenter', self::e(self::BOX));
            }
            $rows[] = $row;
        }
        $this->body .= $this->tbl(array_merge(['3.65in'], array_fill(0, $n, $narrow . 'in')), $rows, false);
        $this->body .= $total !== null ? $this->p('Right', $this->bold($total . ': ') . '__________') : $this->p('Body', '');
    }

    public function table(string $label, array $columns, int $rows): void
    {
        $this->body .= $this->p('Label', self::e($label));
        $n = max(1, count($columns));
        $cells = [array_map(fn(string $c): string => $this->p('CellHead', self::e($c)), $columns)];
        for ($i = 0; $i < $rows; $i++) {
            $cells[] = array_fill(0, $n, '<text:p text:style-name="Cell"/>');
        }
        $this->body .= $this->tbl(array_fill(0, $n, round(7.3 / $n, 3) . 'in'), $cells, true) . $this->p('Body', '');
    }

    public function pageBreak(): void
    {
        $this->body .= '<text:p text:style-name="Break"/>';
    }

    public function file(string $running, string $paper): string
    {
        [$width, $height] = $paper === 'A4' ? ['8.268in', '11.693in'] : ['8.5in', '11in'];
        $content = '<?xml version="1.0" encoding="UTF-8"?><office:document-content ' . self::NS . ' office:version="1.3"><office:automatic-styles>'
            . self::paragraphStyles() . implode('', $this->columns)
            . '<style:style style:name="Strong" style:family="text"><style:text-properties fo:font-weight="bold"/></style:style>'
            . '<style:style style:name="Tbl" style:family="table"><style:table-properties style:width="7.3in" table:align="left" fo:margin-top="0.04in" fo:margin-bottom="0.04in"/></style:style>'
            . '<style:style style:name="TblCell" style:family="table-cell"><style:table-cell-properties fo:border="0.5pt solid #000000" fo:padding="0.03in" style:vertical-align="middle"/></style:style>'
            . '<style:style style:name="TallRow" style:family="table-row"><style:table-row-properties style:min-row-height="0.28in"/></style:style>'
            . '</office:automatic-styles><office:body><office:text>' . $this->body . '</office:text></office:body></office:document-content>';
        $styles = '<?xml version="1.0" encoding="UTF-8"?><office:document-styles ' . self::NS . ' office:version="1.3"><office:styles>'
            . '<style:default-style style:family="paragraph"><style:paragraph-properties fo:margin-top="0in" fo:margin-bottom="0.04in"/>'
            . '<style:text-properties fo:font-family="&apos;Times New Roman&apos;, &apos;Liberation Serif&apos;, serif" fo:font-size="11pt"/></style:default-style>'
            . '</office:styles><office:automatic-styles>'
            . '<style:page-layout style:name="Page"><style:page-layout-properties fo:page-width="' . $width . '" fo:page-height="' . $height . '"'
            . ' fo:margin-top="0.35in" fo:margin-bottom="0.6in" fo:margin-left="0.6in" fo:margin-right="0.6in"/>'
            . '<style:header-style><style:header-footer-properties fo:min-height="0in" fo:margin-bottom="0.15in"/></style:header-style></style:page-layout>'
            . '<style:style style:name="Running" style:family="paragraph"><style:paragraph-properties fo:border-bottom="0.5pt solid #000000" fo:padding-bottom="0.02in"/>'
            . '<style:text-properties fo:font-family="Arial, &apos;Liberation Sans&apos;, sans-serif" fo:font-size="8pt"/></style:style>'
            . '</office:automatic-styles><office:master-styles><style:master-page style:name="Standard" style:page-layout-name="Page"><style:header>'
            . '<text:p text:style-name="Running">' . self::e($running) . '</text:p></style:header></style:master-page></office:master-styles></office:document-styles>';
        return Zip::build([
            'mimetype' => self::MIME,
            'META-INF/manifest.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
                . '<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.3">'
                . '<manifest:file-entry manifest:full-path="/" manifest:version="1.3" manifest:media-type="' . self::MIME . '"/>'
                . '<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>'
                . '<manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/>'
                . '</manifest:manifest>',
            'content.xml' => $content,
            'styles.xml' => $styles,
        ]);
    }

    private static function paragraphStyles(): string
    {
        $styles = [
            'Clinic' => ['', 'fo:font-weight="bold" fo:font-size="14pt"'],
            'Small' => ['fo:margin-bottom="0in"', 'fo:font-size="9.5pt"'],
            'Rule' => ['fo:border-bottom="1.5pt solid #000000" fo:padding-bottom="0.02in" fo:margin-bottom="0.12in"', ''],
            'Title' => ['fo:margin-top="0.04in" fo:margin-bottom="0.1in" fo:keep-with-next="always"', 'fo:font-weight="bold" fo:font-size="15pt"'],
            'H2' => ['fo:margin-top="0.16in" fo:margin-bottom="0.06in" fo:border-bottom="0.5pt solid #666666" fo:keep-with-next="always"', 'fo:font-weight="bold" fo:font-size="11.5pt"'],
            'Body' => ['', ''],
            'Stamp' => ['fo:text-align="end" fo:margin-top="0.12in"', 'fo:font-size="7pt" fo:color="#555555"'],
            'Label' => ['fo:margin-top="0.06in" fo:keep-with-next="always"', 'fo:font-weight="bold"'],
            'Item' => ['fo:margin-bottom="0.02in"', ''],
            'Line' => ['fo:border-bottom="0.75pt solid #000000" fo:margin-top="0.16in" fo:margin-bottom="0in" style:join-border="false"', ''],
            'Sign' => ['fo:border-bottom="0.75pt solid #000000" fo:margin-top="0.4in" fo:margin-right="3in" fo:keep-with-next="always"', ''],
            'SignUnder' => ['fo:margin-bottom="0.1in"', 'fo:font-size="9pt"'],
            'Cell' => ['fo:margin-bottom="0in"', ''],
            'CellHead' => ['fo:margin-bottom="0in" fo:text-align="center"', 'fo:font-weight="bold" fo:font-size="8.5pt"'],
            'CellCenter' => ['fo:margin-bottom="0in" fo:text-align="center"', ''],
            'Right' => ['fo:text-align="end"', ''],
            'Break' => ['fo:break-before="page" fo:margin-bottom="0in"', ''],
        ];
        $xml = '';
        foreach ($styles as $name => [$paragraph, $text]) {
            $tabs = $name === 'SignUnder' ? '<style:tab-stops><style:tab-stop style:position="4.3in" style:type="right"/></style:tab-stops>' : '';
            $xml .= '<style:style style:name="' . $name . '" style:family="paragraph">'
                . ($paragraph !== '' || $tabs !== '' ? '<style:paragraph-properties ' . $paragraph . '>' . $tabs . '</style:paragraph-properties>' : '')
                . ($text !== '' ? '<style:text-properties ' . $text . '/>' : '') . '</style:style>';
        }
        return $xml;
    }

    private function p(string $style, string $inner): string
    {
        return $inner === '' ? '<text:p text:style-name="' . $style . '"/>' : '<text:p text:style-name="' . $style . '">' . $inner . '</text:p>';
    }

    private function bold(string $text): string
    {
        return '<text:span text:style-name="Strong">' . self::e($text) . '</text:span>';
    }

    /**
     * @param list<string>       $widths CSS-style lengths, one per column
     * @param list<list<string>> $rows   each cell's paragraphs
     */
    private function tbl(array $widths, array $rows, bool $tall): string
    {
        $xml = '<table:table table:style-name="Tbl">';
        foreach ($widths as $w) {
            $name = 'Col' . str_replace(['.', 'in'], ['_', ''], $w);
            $this->columns[$name] = '<style:style style:name="' . $name . '" style:family="table-column"><style:table-column-properties style:column-width="' . $w . '"/></style:style>';
            $xml .= '<table:table-column table:style-name="' . $name . '"/>';
        }
        foreach ($rows as $r => $cells) {
            $xml .= '<table:table-row' . ($tall && $r > 0 ? ' table:style-name="TallRow"' : '') . '>';
            foreach ($cells as $content) {
                $xml .= '<table:table-cell table:style-name="TblCell" office:value-type="string">' . $content . '</table:table-cell>';
            }
            $xml .= '</table:table-row>';
        }
        return $xml . '</table:table>';
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
