<?php

/**
 * A clinic's own Word (.docx) and LibreOffice (.odt) documents, kept in the site's Clinic Forms
 * folder (sites/<site>/documents/clinic-forms) beside its JSON documents, so they survive module
 * updates. Each is checked when it's added: a real document of its type, not too large, and its
 * placeholders listed so a typo shows at once.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Office;

use OpenHealthcare\ClinicForms\Paper\DocumentLibrary;

final readonly class TemplateLibrary
{
    public const MAX_BYTES = 5_000_000;
    public const EXTENSIONS = ['docx', 'odt'];

    public function __construct(private string $dir)
    {
    }

    public static function forSite(string $siteDir): self
    {
        return new self($siteDir . '/' . DocumentLibrary::LOCAL_FOLDER);
    }

    /** @return list<array{file: string, name: string, format: string}> by name */
    public function all(): array
    {
        $out = [];
        foreach (self::EXTENSIONS as $ext) {
            foreach (glob($this->dir . '/*.' . $ext) ?: [] as $path) {
                $file = basename($path);
                $out[] = ['file' => $file, 'name' => self::displayName($file), 'format' => $ext];
            }
        }
        usort($out, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    /** The template's contents, or null when there's no such template. */
    public function bytes(string $file): ?string
    {
        $clean = self::cleanName($file);
        if ($clean === null || $clean !== $file || !is_file($this->dir . '/' . $clean)) {
            return null;
        }
        $bytes = file_get_contents($this->dir . '/' . $clean);
        return $bytes === false ? null : $bytes;
    }

    /**
     * Checks and keeps an uploaded template; one with the same name is replaced.
     *
     * @return array{file: string, known: list<string>, unknown: list<string>}
     * @throws \InvalidArgumentException naming what's wrong
     */
    public function add(string $tmpPath, string $originalName): array
    {
        $file = self::cleanName($originalName);
        if ($file === null) {
            throw new \InvalidArgumentException('the file must be a Word (.docx) or LibreOffice (.odt) document');
        }
        $size = is_file($tmpPath) ? (int)filesize($tmpPath) : 0;
        if ($size === 0 || $size > self::MAX_BYTES) {
            throw new \InvalidArgumentException('the file is empty or larger than 5 MB');
        }
        $bytes = (string)file_get_contents($tmpPath);
        $format = TemplateFiller::format(Zip::read($bytes));
        if ($format !== pathinfo($file, PATHINFO_EXTENSION)) {
            throw new \InvalidArgumentException("the file isn't the kind of document its name says (.docx for Word, .odt for LibreOffice)");
        }
        $found = TemplateFiller::placeholders($bytes);
        if (!is_dir($this->dir) && !mkdir($this->dir, 0770, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Could not create ' . $this->dir);
        }
        $tmp = $this->dir . '/.' . $file . '.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $bytes) === false || !rename($tmp, $this->dir . '/' . $file)) {
            @unlink($tmp);
            throw new \RuntimeException('Could not save ' . $file);
        }
        return ['file' => $file] + $found;
    }

    public function remove(string $file): bool
    {
        $clean = self::cleanName($file);
        return $clean !== null && $clean === $file && is_file($this->dir . '/' . $clean) && unlink($this->dir . '/' . $clean);
    }

    /** A safe file name for a template, or null when it isn't a .docx or .odt name. */
    public static function cleanName(string $name): ?string
    {
        $name = basename(str_replace('\\', '/', $name));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, self::EXTENSIONS, true)) {
            return null;
        }
        $stem = (string)preg_replace('/[^A-Za-z0-9 ._-]+/', '-', pathinfo($name, PATHINFO_FILENAME));
        $stem = trim((string)preg_replace('/\s+/', ' ', $stem), " .-");
        if ($stem === '' || strlen($stem) > 120) {
            return null;
        }
        return $stem . '.' . $ext;
    }

    public static function displayName(string $file): string
    {
        return trim(str_replace(['_', '-'], ' ', pathinfo($file, PATHINFO_FILENAME)));
    }
}
