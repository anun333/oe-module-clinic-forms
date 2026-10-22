<?php

/**
 * Asked before a document is printed or downloaded: another module may fill a table the document
 * names by its "id" (Sliding Fee fills the notice's discounts from its schedule in force). It goes
 * through OpenEMR's event dispatcher, so Clinic Forms knows nothing of the module that answers.
 * A table nobody fills prints blank, as it always has. FHIR Questionnaires aren't filled: they're
 * blank forms.
 *
 *   $dispatcher->addListener(DocumentFillEvent::EVENT_NAME, function (object $event): void { ... });
 *
 * A listener in another module can use the name 'clinic_forms.document.fill' without this class.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Event;

final class DocumentFillEvent
{
    public const EVENT_NAME = 'clinic_forms.document.fill';

    /** @var array<string, array{rows: list<list<string>>, note: ?string}> */
    private array $tables = [];

    /**
     * @param string       $documentId the document's id, such as sliding-fee-notice
     * @param string       $language   the document's language, en or es
     * @param list<string> $tableIds   the ids of its tables, which a listener may fill
     * @param ?int         $pid        the patient it's filled for, or null for a blank print
     */
    public function __construct(
        public readonly string $documentId,
        public readonly string $language,
        public readonly array $tableIds,
        public readonly ?int $pid = null,
    ) {
    }

    /**
     * Fill a table: each row's cells in the table's column order, as text. A note prints under the
     * table, saying where the figures come from.
     *
     * @param list<list<string>> $rows
     */
    public function fillTable(string $id, array $rows, ?string $note = null): void
    {
        if (!in_array($id, $this->tableIds, true)) {
            throw new \InvalidArgumentException("{$this->documentId} has no table \"$id\"");
        }
        $this->tables[$id] = ['rows' => array_values(array_map(static fn(array $r): array => array_values(array_map('strval', $r)), $rows)), 'note' => $note];
    }

    /** @return array<string, array{rows: list<list<string>>, note: ?string}> */
    public function filled(): array
    {
        return $this->tables;
    }
}
