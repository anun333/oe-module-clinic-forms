<?php

/**
 * Builds an editable office document, block by block, from Clinic Forms content: Word (.docx)
 * or LibreOffice (.odt). Text arrives with its placeholders already filled.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Office;

interface OfficeWriter
{
    /** @param list<string> $lines address, phones and so on, under the clinic's name */
    public function letterhead(string $name, array $lines): void;

    public function title(string $text): void;

    public function heading(string $text): void;

    public function paragraph(string $text): void;

    /** @param list<array{string, string}> $fields [label, value]; an empty value prints a line to write on */
    public function fields(array $fields): void;

    /** @param list<string> $items */
    /** @param ?int $ticked the item an answer carried from the chart ticks (Fill) */
    public function checklist(?string $label, array $items, ?int $ticked = null): void;

    public function lines(string $label, int $count): void;

    /** @param list<string> $signers */
    public function signatures(array $signers): void;

    /**
     * @param list<string> $columns
     * @param list<string> $items
     * @param ?list<int>   $scores
     * @param ?list<list<int>> $itemScores points per question, in place of $scores, when the questions score differently
     * @param int $start the first row's number
     */
    public function scale(string $label, array $columns, array $items, ?array $scores, ?string $total, ?array $itemScores = null, int $start = 1): void;

    /**
     * @param list<string>       $columns
     * @param list<list<string>> $values rows another module filled, printed in place of the blank rows; a note under them
     */
    public function table(string $label, array $columns, int $rows, array $values = [], ?string $note = null): void;

    public function pageBreak(): void;

    /** Small, right-aligned: the document's id and version, at the end of each document. */
    public function stamp(string $text): void;

    /** The finished file. $running is the line at the top of every page; $paper 'letter' or 'A4'. */
    public function file(string $running, string $paper): string;

    public function extension(): string;

    public function mimeType(): string;
}
