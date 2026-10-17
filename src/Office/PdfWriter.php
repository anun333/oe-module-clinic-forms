<?php

/**
 * Clinic Forms documents as one PDF, for the patient's Documents or to download, through the mPDF
 * that OpenEMR ships (vendor/mpdf/mpdf; OpenEMR's own PDFs use it). mPDF has no flexbox or grid and
 * can't split one table row across pages, so the printed page's markup doesn't suit it: this writes
 * plain blocks and tables instead, and puts the patient line and each document's title in mPDF's
 * page header, and its version stamp and the chart number's barcode in its page footer, so each
 * page carries them as the printed one does.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Office;

use OpenHealthcare\ClinicForms\Paper\Code128;

final class PdfWriter implements OfficeWriter
{
    private const BOX = "\u{2610}";
    private const CSS = <<<'CSS'
        body { font-family: dejavusans; font-size: 10pt; line-height: 1.3; }
        h1 { font-size: 15pt; margin: 6pt 0 6pt 0; }
        h2 { font-size: 11.5pt; margin: 9pt 0 3pt 0; }
        p { margin: 0 0 5pt 0; }
        table { border-collapse: collapse; }
        .lh { width: 100%; border-bottom: 1.2pt solid #000; margin-bottom: 8pt; }
        .lh .name { font-size: 13pt; font-weight: bold; }
        .lh .line { font-size: 8.5pt; }
        .fields { width: 100%; margin: 3pt 0 5pt 0; }
        .fields td { padding: 2pt 4pt 2pt 0; vertical-align: bottom; }
        .fields .label { font-weight: bold; white-space: nowrap; width: 1%; }
        .fields .value { border-bottom: 0.6pt solid #000; }
        .label-only { font-weight: bold; margin: 6pt 0 2pt 0; }
        .item { margin: 1pt 0 1pt 0; }
        .rules { width: 100%; }
        .rules td { border-bottom: 0.6pt solid #000; height: 18pt; }
        .signatures { width: 100%; margin-top: 18pt; }
        .signatures td { padding: 22pt 12pt 6pt 0; vertical-align: top; width: 50%; }
        .sign-line { border-top: 0.6pt solid #000; font-size: 8.5pt; }
        .grid { width: 100%; margin: 3pt 0 6pt 0; }
        .grid th { border: 0.6pt solid #000; font-size: 8.5pt; padding: 2pt; }
        .grid td { border: 0.6pt solid #000; padding: 3pt; font-size: 9.5pt; }
        .grid .box { text-align: center; }
        .grid .blank { height: 18pt; }
        .total { margin-top: 3pt; }
        .stamp { font-size: 7pt; color: #555; text-align: right; }
        .running { width: 100%; border-bottom: 0.5pt solid #666; font-size: 8pt; }
        .running .title { text-align: right; }
        .foot { width: 100%; }
        CSS;

    /** @var list<array{title: string, html: string, stamp: string, running: ?string}> */
    private array $documents = [];
    private string $html = '';
    private string $title = '';
    private ?string $running = null;

    /**
     * @param string $patientId the chart number to carry as a barcode on every page; '' on a blank print
     * @param array<string, mixed> $config mPDF settings to use in place of OpenEMR's (tests pass their own)
     */
    public function __construct(private readonly string $dateLabel = 'Date', private readonly string $patientId = '', private readonly ?array $config = null)
    {
    }

    /** Whether this OpenEMR has mPDF to write with. */
    public static function available(): bool
    {
        return class_exists(\Mpdf\Mpdf::class);
    }

    public function extension(): string
    {
        return 'pdf';
    }

    public function mimeType(): string
    {
        return 'application/pdf';
    }

    public function letterhead(string $name, array $lines): void
    {
        $html = "<table class='lh'><tr><td><div class='name'>" . self::e($name) . "</div>";
        foreach ($lines as $line) {
            $html .= "<div class='line'>" . self::e($line) . "</div>";
        }
        $this->html .= $html . "</td></tr></table>";
    }

    public function title(string $text): void
    {
        $this->title = $text;
        $this->html .= "<h1>" . self::e($text) . "</h1>";
    }

    public function heading(string $text): void
    {
        $this->html .= "<h2>" . self::e($text) . "</h2>";
    }

    public function paragraph(string $text): void
    {
        $this->html .= "<p>" . self::e($text) . "</p>";
    }

    public function fields(array $fields): void
    {
        // Two to a row, as on the printed page; an empty value is a line to write on.
        $rows = '';
        foreach (array_chunk($fields, 2) as $pair) {
            $rows .= '<tr>';
            foreach ($pair as [$label, $value]) {
                $rows .= "<td class='label'>" . self::e($label) . "</td><td class='value'>" . ($value !== '' ? self::e($value) : '&nbsp;') . "</td>";
            }
            $rows .= count($pair) === 1 ? '<td></td><td></td>' : '';
            $rows .= '</tr>';
        }
        $this->html .= "<table class='fields'>" . $rows . "</table>";
    }

    public function checklist(?string $label, array $items): void
    {
        if ($label !== null) {
            $this->html .= "<div class='label-only'>" . self::e($label) . "</div>";
        }
        foreach ($items as $item) {
            $this->html .= "<div class='item'>" . self::BOX . ' ' . self::e($item) . "</div>";
        }
    }

    public function lines(string $label, int $count): void
    {
        $this->html .= "<div class='label-only'>" . self::e($label) . "</div><table class='rules'>" . str_repeat('<tr><td>&nbsp;</td></tr>', max(1, $count)) . "</table>";
    }

    public function signatures(array $signers): void
    {
        $rows = '';
        foreach (array_chunk($signers, 2) as $pair) {
            $rows .= '<tr>';
            foreach ($pair as $signer) {
                $rows .= "<td><div class='sign-line'>" . self::e($signer) . ' &nbsp;&nbsp;&nbsp; ' . self::e($this->dateLabel) . "</div></td>";
            }
            $rows .= count($pair) === 1 ? '<td></td>' : '';
            $rows .= '</tr>';
        }
        $this->html .= "<table class='signatures'>" . $rows . "</table>";
    }

    public function scale(string $label, array $columns, array $items, ?array $scores, ?string $total, ?array $itemScores = null): void
    {
        $head = '<tr><th></th>';
        foreach ($columns as $i => $column) {
            $head .= '<th>' . self::e($column) . (isset($scores[$i]) ? '<br>(' . self::e((string)$scores[$i]) . ')' : '') . '</th>';
        }
        $body = '';
        foreach ($items as $n => $item) {
            $body .= '<tr><td>' . ($n + 1) . '. ' . self::e($item) . '</td>';
            foreach ($columns as $i => $unused) {
                $body .= "<td class='box'>" . self::BOX . (isset($itemScores[$n][$i]) ? ' ' . self::e((string)$itemScores[$n][$i]) : '') . '</td>';
            }
            $body .= '</tr>';
        }
        $this->html .= "<div class='label-only'>" . self::e($label) . "</div><table class='grid'>" . $head . '</tr>' . $body . '</table>'
            . ($total !== null ? "<div class='total'><b>" . self::e($total) . ':</b> ____________</div>' : '');
    }

    public function table(string $label, array $columns, int $rows): void
    {
        $head = '<tr>' . implode('', array_map(static fn(string $c): string => '<th>' . self::e($c) . '</th>', $columns)) . '</tr>';
        $blank = '<tr>' . str_repeat("<td class='blank'>&nbsp;</td>", max(1, count($columns))) . '</tr>';
        $this->html .= "<div class='label-only'>" . self::e($label) . "</div><table class='grid'>" . $head . str_repeat($blank, max(1, $rows)) . '</table>';
    }

    public function pageBreak(): void
    {
        // Each document starts its own page with its own header and footer: see stamp() and file().
    }

    /**
     * The document being written is filed without a name (a grievance): its pages carry this blank
     * patient line in place of the file's, and no barcode.
     */
    public function withoutPatient(string $blankRunning): void
    {
        $this->running = $blankRunning;
    }

    public function stamp(string $text): void
    {
        $this->documents[] = ['title' => $this->title, 'html' => $this->html, 'stamp' => $text, 'running' => $this->running];
        $this->html = '';
        $this->title = '';
        $this->running = null;
    }

    public function file(string $running, string $paper): string
    {
        if ($this->html !== '') {
            $this->stamp('');
        }
        $mpdf = new \Mpdf\Mpdf(array_merge($this->config ?? self::openEmrConfig(), [
            'format' => $paper === 'A4' ? 'A4' : 'Letter',
            'orientation' => 'P',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 22,
            'margin_header' => 8,
            'margin_bottom' => 24,
            'margin_footer' => 8,
        ]));
        $mpdf->SetTitle('Clinic Forms');
        $mpdf->WriteHTML(self::CSS, \Mpdf\HTMLParserMode::HEADER_CSS);
        foreach ($this->documents as $i => $doc) {
            // Named, and attached as the page opens: mPDF draws a footer when its page closes, so a
            // footer set any later would land on the previous document's last page.
            $mpdf->DefHTMLHeaderByName("h$i", "<table class='running'><tr><td>" . self::e($doc['running'] ?? $running) . "</td><td class='title'>" . self::e($doc['title']) . "</td></tr></table>");
            $mpdf->DefHTMLFooterByName("f$i", $this->footer($doc['stamp'], $doc['running'] === null));
            $mpdf->AddPageByArray(['ohname' => "h$i", 'ohvalue' => 1, 'ehname' => "h$i", 'ehvalue' => 1, 'ofname' => "f$i", 'ofvalue' => 1, 'efname' => "f$i", 'efvalue' => 1]);
            $mpdf->WriteHTML($doc['html'], \Mpdf\HTMLParserMode::HTML_BODY);
        }
        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    /** The chart number's barcode (Code 128, as on the printed page) and the document's stamp. */
    private function footer(string $stamp, bool $withPatient = true): string
    {
        $code = $withPatient && Code128::encodable($this->patientId)
            ? "<barcode code='" . self::e($this->patientId) . "' type='C128B' size='0.9' height='0.75' />" : '';
        return "<table class='foot'><tr><td>" . $code . "</td><td class='stamp'>" . self::e($stamp) . "</td></tr></table>";
    }

    /** @return array<string, mixed> OpenEMR's PDF settings (paper, fonts, temp folder), or plain ones outside OpenEMR */
    private static function openEmrConfig(): array
    {
        if (class_exists(\OpenEMR\Pdf\Config_Mpdf::class)) {
            return \OpenEMR\Pdf\Config_Mpdf::getConfigMpdf();
        }
        return ['tempDir' => sys_get_temp_dir() . '/clinic-forms-mpdf', 'default_font' => 'dejavusans', 'mode' => 'utf-8'];
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
