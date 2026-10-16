<?php

/**
 * Fills a clinic's own Word (.docx) or LibreOffice (.odt) template: every placeholder in the
 * document, its headers and footers is replaced with the clinic's, the patient's, the
 * recipient's or the user's details. Formatting is kept, and nothing else in the file changes.
 *
 * Placeholders are Clinic Forms' own ({patient.name}, {recipient.address}: Context::KEYS) or the
 * tags OpenEMR's Document Templates use for the same facts ({PatientName}, {PatientDOB}…), so a
 * clinic's existing templates work too.
 *
 * Word and LibreOffice often split typed text into pieces inside the file (after an edit, a
 * spelling check, a change of formatting), so "{patient.name}" may be stored as "{pat" and
 * "ient.name}" with markup between. A placeholder is found in the text with the markup set
 * aside, within one paragraph. Its text is replaced and its markup kept, so the file stays valid.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Office;

use OpenHealthcare\ClinicForms\Paper\Context;

final class TemplateFiller
{
    /** OpenEMR Document Templates tags (interface/patient_file/download_template.php) => Context keys. */
    public const CORE_TAGS = [
        'PatientName' => 'patient.name',
        'PatientID' => 'patient.id',
        'Address' => 'patient.street',
        'City' => 'patient.city',
        'State' => 'patient.state',
        'Zip' => 'patient.zip',
        'PatientPhone' => 'patient.phone',
        'PatientDOB' => 'patient.dob',
        'PatientSex' => 'patient.sex',
        'CurrentDate' => 'clinic.today',
        'Allergies' => 'patient.allergies',
        'Medications' => 'patient.medications',
        'ProblemList' => 'patient.problems',
    ];

    /** What a placeholder with no value becomes: a line to write on. */
    public const BLANK = '__________';

    /** A brace, then text and markup with no brace in it, then a brace; paragraph boundaries and length are checked after. */
    private const CANDIDATE = '/\{((?:[^{}<]++|<[^>]*+>)*+)\}/s';
    /** A placeholder split more finely than this is not one (the markup of a whole page, say). */
    private const MAX_MATCH = 4000;
    private const PARAGRAPH_BOUNDARY = '#</?w:p[\s>/]|</?text:(p|h)[\s>/]#';

    /** @return ?string 'docx' or 'odt', from the package's own contents */
    public static function format(array $entries): ?string
    {
        if (isset($entries['word/document.xml'])) {
            return 'docx';
        }
        if (trim($entries['mimetype'] ?? '') === 'application/vnd.oasis.opendocument.text' && isset($entries['content.xml'])) {
            return 'odt';
        }
        return null;
    }

    /**
     * @param array<string, string> $entries
     * @return list<string> the parts that hold text: the document, headers and footers
     */
    public static function parts(array $entries, string $format): array
    {
        $names = array_keys($entries);
        return $format === 'docx'
            ? array_values(array_filter($names, static fn(string $n): bool => preg_match('#^word/(document|header\d*|footer\d*)\.xml$#', $n) === 1))
            : array_values(array_filter($names, static fn(string $n): bool => $n === 'content.xml' || $n === 'styles.xml'));
    }

    /** The template with every known placeholder filled; unknown ones are left as typed. */
    public static function fill(string $bytes, Context $context): string
    {
        $entries = Zip::read($bytes);
        $format = self::format($entries);
        if ($format === null) {
            throw new \InvalidArgumentException('not a Word (.docx) or LibreOffice (.odt) document');
        }
        foreach (self::parts($entries, $format) as $part) {
            $entries[$part] = self::fillXml($entries[$part], $context);
        }
        return Zip::build($entries);
    }

    /**
     * The placeholders a template uses, for checking it when it's added.
     *
     * @return array{known: list<string>, unknown: list<string>} each as typed, with braces
     */
    public static function placeholders(string $bytes): array
    {
        $entries = Zip::read($bytes);
        $format = self::format($entries);
        if ($format === null) {
            throw new \InvalidArgumentException('not a Word (.docx) or LibreOffice (.odt) document');
        }
        $known = [];
        $unknown = [];
        foreach (self::parts($entries, $format) as $part) {
            preg_match_all(self::CANDIDATE, $entries[$part], $m);
            foreach ($m[1] as $inner) {
                if (strlen($inner) > self::MAX_MATCH || preg_match(self::PARAGRAPH_BOUNDARY, $inner) === 1) {
                    continue;
                }
                $text = html_entity_decode(strip_tags($inner), ENT_XML1 | ENT_QUOTES, 'UTF-8');
                if (self::key($text) !== null) {
                    $known['{' . $text . '}'] = true;
                } elseif (preg_match('/^([a-z]+\.[a-z]+|[A-Z][A-Za-z]+|(LBF\w*|DEM|HIS):\w+|\/?GRP|ITEMSEP)$/', $text) === 1) {
                    $unknown['{' . $text . '}'] = true;
                }
            }
        }
        return ['known' => array_keys($known), 'unknown' => array_keys($unknown)];
    }

    /** The Context key a placeholder's text names, or null. */
    public static function key(string $text): ?string
    {
        if (preg_match('/^[a-z]+\.[a-z]+$/', $text) === 1 && Context::isKnown($text)) {
            return $text;
        }
        return self::CORE_TAGS[$text] ?? null;
    }

    /**
     * Replaces a placeholder's text with the value and keeps all of its markup, so the file stays
     * well formed however the placeholder was split: the value takes the place of the first piece
     * of text (keeping that piece's formatting), and the other pieces become empty.
     */
    private static function replaceText(string $match, string $value): string
    {
        $out = '';
        $placed = false;
        foreach (preg_split('/(<[^>]*>)/', $match, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $i => $token) {
            if ($i % 2 === 1) {
                $out .= $token;
            } elseif (!$placed && $token !== '') {
                $out .= $value;
                $placed = true;
            }
        }
        return $out;
    }

    public static function fillXml(string $xml, Context $context): string
    {
        return (string)preg_replace_callback(self::CANDIDATE, static function (array $m) use ($context): string {
            if (strlen($m[1]) > self::MAX_MATCH || preg_match(self::PARAGRAPH_BOUNDARY, $m[1]) === 1) {
                return $m[0];
            }
            $key = self::key(html_entity_decode(strip_tags($m[1]), ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            if ($key === null) {
                return $m[0];
            }
            $value = $context->value($key);
            return self::replaceText($m[0], htmlspecialchars($value !== '' ? $value : self::BLANK, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }, $xml);
    }
}
