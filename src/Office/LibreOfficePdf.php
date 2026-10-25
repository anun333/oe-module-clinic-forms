<?php

/**
 * A PDF made by LibreOffice from the module's own .odt, with PDF/UA on: tagged, with the title and
 * language PDF/UA asks for, and fonts embedded, which the PDF mPDF writes can't be (it writes no
 * structure tree). Used for every PDF the module makes when LibreOffice is installed on the server
 * in one of the places it installs itself; otherwise PdfWriter's mPDF PDF is used, as before.
 *
 * Only those fixed places are looked in: the program run is never taken from a setting or a request,
 * since the clinic's settings file is one an administrator uploads. The conversion runs without a
 * shell, in a folder of its own with its own LibreOffice profile (two at once would otherwise share
 * one and the second would fail), and is stopped after a minute.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Office;

final class LibreOfficePdf
{
    /** Where LibreOffice installs itself: distribution packages, its own Linux packages, macOS, Windows. */
    public const PLACES = [
        '/usr/bin/soffice',
        '/usr/bin/libreoffice',
        '/usr/lib/libreoffice/program/soffice',
        '/usr/lib64/libreoffice/program/soffice',
        '/opt/libreoffice/program/soffice',
        '/Applications/LibreOffice.app/Contents/MacOS/soffice',
        'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
    ];
    /** The export filter's settings: PDF/UA-1 (ISO 14289-1), which also tags the PDF. */
    public const FILTER = 'pdf:writer_pdf_Export:{"PDFUACompliance":{"type":"boolean","value":"true"}}';
    private const TIMEOUT = 60;

    private function __construct(private readonly string $program)
    {
    }

    /**
     * LibreOffice, if it is installed in one of its own places; null otherwise. /opt/libreoffice<version>,
     * where LibreOffice's own packages install, is looked in too.
     *
     * @param list<string>|null $places where to look (tests pass their own)
     */
    public static function find(?array $places = null): ?self
    {
        $places ??= [...self::PLACES, ...(glob('/opt/libreoffice*/program/soffice') ?: [])];
        foreach ($places as $place) {
            if (is_file($place) && is_executable($place)) {
                return new self($place);
            }
        }
        return null;
    }

    public function program(): string
    {
        return $this->program;
    }

    /**
     * The PDF of an .odt, or null if LibreOffice didn't make one (it failed, or took over a minute).
     *
     * @param string $odt the .odt file's bytes
     */
    public function convert(string $odt): ?string
    {
        $dir = sys_get_temp_dir() . '/clinic-forms-pdf-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0700) || file_put_contents($dir . '/document.odt', $odt) === false) {
            return null;
        }
        try {
            $command = [$this->program, '-env:UserInstallation=file://' . $dir . '/profile', '--headless', '--norestore',
                '--convert-to', self::FILTER, '--outdir', $dir, $dir . '/document.odt'];
            $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $dir . '/out.txt', 'w'], 2 => ['file', $dir . '/out.txt', 'a']],
                $pipes, $dir, ['HOME' => $dir, 'PATH' => (string)getenv('PATH')]);
            if (!is_resource($process)) {
                return null;
            }
            $until = microtime(true) + self::TIMEOUT;
            while (proc_get_status($process)['running']) {
                if (microtime(true) > $until) {
                    proc_terminate($process, 9);
                    proc_close($process);
                    return null;
                }
                usleep(100_000);
            }
            proc_close($process);
            $pdf = is_file($dir . '/document.pdf') ? file_get_contents($dir . '/document.pdf') : false;
            return $pdf !== false && str_starts_with($pdf, '%PDF-') ? $pdf : null;
        } finally {
            self::remove($dir);
        }
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
