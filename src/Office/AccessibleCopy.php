<?php

/**
 * Which file the "Accessible copy" download gives: the copy a patient who uses a screen reader can
 * read, from what this server and these documents allow.
 *
 * - LibreOffice on the server: a PDF tagged for PDF/UA (LibreOfficePdf), filled as asked.
 * - Without it, for documents printed blank: the PDF/UA copies made when the module was released
 *   (BlankPdfs), when every ticked document has one as the clinic has it.
 * - Otherwise the Word file, which a screen reader reads; all 88 patient documents pass PDF/UA
 *   when Word or LibreOffice saves them as PDF.
 *
 * OpenEMR's own PDF writer (mPDF) can't tag a PDF, so its PDF is the print copy and never the
 * accessible one. LibreOffice gives a file one page header, so documents filed for a patient beside
 * one filed without a name go to the Word file rather than an untagged PDF.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Office;

final class AccessibleCopy
{
    public const LIBREOFFICE_PDF = 'libreoffice-pdf';
    public const BLANK_PDF = 'blank-pdf';
    public const WORD = 'docx';

    /**
     * @param bool $libreOffice  LibreOffice is on the server and the clinic lets Clinic Forms use it
     * @param bool $mixedNames   a document filed without a name is ticked beside one with the patient's
     * @param bool $withPatient  the documents are filled with a patient's details
     * @param bool $releaseCopies every ticked document has a release PDF/UA copy as the clinic has it
     */
    public static function route(bool $libreOffice, bool $mixedNames, bool $withPatient, bool $releaseCopies): string
    {
        if ($libreOffice && !$mixedNames) {
            return self::LIBREOFFICE_PDF;
        }
        if (!$withPatient && $releaseCopies) {
            return self::BLANK_PDF;
        }
        return self::WORD;
    }
}
