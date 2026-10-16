<?php

/**
 * Reads and writes the zip packages Word (.docx) and LibreOffice (.odt) files are. An ODT's
 * "mimetype" entry is written first and uncompressed, as the format requires.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Office;

final class Zip
{
    /** A template that unpacks to more than this is refused (a zip bomb, or not a letter). */
    public const MAX_UNPACKED = 25_000_000;

    /** @param array<string, string> $entries name => content, in order */
    public static function build(array $entries): string
    {
        $file = self::tempFile();
        try {
            $zip = new \ZipArchive();
            if ($zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not create a zip file');
            }
            foreach ($entries as $name => $content) {
                $zip->addFromString($name, $content);
                if ($name === 'mimetype') {
                    $zip->setCompressionName($name, \ZipArchive::CM_STORE);
                }
            }
            $zip->close();
            return (string)file_get_contents($file);
        } finally {
            @unlink($file);
        }
    }

    /**
     * @return array<string, string> name => content, in the package's order
     * @throws \InvalidArgumentException when it isn't a zip package, or unpacks too large
     */
    public static function read(string $bytes): array
    {
        $file = self::tempFile();
        try {
            file_put_contents($file, $bytes);
            $zip = new \ZipArchive();
            if ($zip->open($file, \ZipArchive::RDONLY) !== true) {
                throw new \InvalidArgumentException('not a Word or LibreOffice file (it is not a zip package)');
            }
            $total = 0;
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }
                $total += (int)$stat['size'];
                if ($total > self::MAX_UNPACKED) {
                    $zip->close();
                    throw new \InvalidArgumentException('too large once unpacked');
                }
                $entries[(string)$stat['name']] = (string)$zip->getFromIndex($i);
            }
            $zip->close();
            return $entries;
        } finally {
            @unlink($file);
        }
    }

    private static function tempFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'cfz');
        if ($file === false) {
            throw new \RuntimeException('No temporary file');
        }
        return $file;
    }
}
