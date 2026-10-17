<?php

/**
 * Adds Clinic Forms' Questionnaires to OpenEMR's own questionnaires (the questionnaire
 * repository), where the Questionnaire Assessments encounter form offers them: staff enter a
 * patient's paper answers there, and OpenEMR keeps them as a QuestionnaireResponse, with the
 * total worked out by its own runtime.
 *
 * OpenEMR keys questionnaires by title. A title Clinic Forms added before, from the same document,
 * is updated in place; a title that is already there for any other reason is left alone, and so is
 * one the clinic has turned off. Each Questionnaire carries an identifier naming its document, since
 * OpenEMR replaces a new questionnaire's id and url with its own.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Fhir;

final class QuestionnaireRegistration
{
    public const ADDED = 'added';
    public const UPDATED = 'updated';
    public const NOTHING_TO_ANSWER = 'nothing to answer';
    public const NOT_OURS = 'not from Clinic Forms';
    public const TURNED_OFF = 'turned off';

    /**
     * @param \Closure(string): ?array{id: int, questionnaire_id: string, source_url: string, active: bool, questionnaire: string} $find
     *        the questionnaire OpenEMR keeps under this title
     * @param \Closure(array<string, mixed>, string): int $save saves a Questionnaire under this title: into the row
     *        with that title or the Questionnaire's id, or a new row; returns the row's id
     */
    public function __construct(private \Closure $find, private \Closure $save)
    {
    }

    /**
     * Adds one Questionnaire, or updates the one this document added before.
     *
     * @param array<string, mixed> $q a Questionnaire from QuestionnaireExport
     * @return array{0: string, 1: ?int} what happened (ADDED, UPDATED, or why it was left out) and the row's id
     */
    public function register(array $q): array
    {
        if (!self::answerable(is_array($q['item'] ?? null) ? $q['item'] : [])) {
            return [self::NOTHING_TO_ANSWER, null];
        }
        $title = trim(is_string($q['title'] ?? null) ? $q['title'] : '');
        $row = ($this->find)($title);
        if ($row === null) {
            // OpenEMR gives a new questionnaire its own id and url, as a FHIR create does.
            unset($q['id'], $q['url']);
            return [self::ADDED, ($this->save)($q, $title)];
        }
        if (!self::sameDocument($row['questionnaire'], $q)) {
            return [self::NOT_OURS, $row['id']];
        }
        if (!$row['active']) {
            return [self::TURNED_OFF, $row['id']];
        }
        // Kept as OpenEMR gave them, so links to the questionnaire still work.
        $q['id'] = $row['questionnaire_id'];
        $q['url'] = $row['source_url'];
        return [self::UPDATED, ($this->save)($q, $title)];
    }

    /**
     * The identifier naming the document a Questionnaire was made from.
     *
     * @return array{system: string, value: string}
     */
    public static function identifier(string $id): array
    {
        return ['system' => rtrim(QuestionnaireExport::CANONICAL, '/'), 'value' => $id];
    }

    /**
     * Whether a stored questionnaire (as JSON) was added from the same document as this one.
     *
     * @param array<string, mixed> $q
     */
    private static function sameDocument(string $stored, array $q): bool
    {
        $stored = json_decode($stored, true);
        $mine = is_array($q['identifier'] ?? null) ? ($q['identifier'][0] ?? null) : null;
        if (!is_array($stored) || !is_array($mine)) {
            return false;
        }
        foreach (is_array($stored['identifier'] ?? null) ? $stored['identifier'] : [] as $identifier) {
            if (is_array($identifier) && ($identifier['system'] ?? null) === $mine['system'] && ($identifier['value'] ?? null) === $mine['value']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether any item asks for an answer, rather than only showing text.
     *
     * @param array<array-key, mixed> $items
     */
    private static function answerable(array $items): bool
    {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = $item['type'] ?? '';
            if (($type !== 'display' && $type !== 'group') || self::answerable(is_array($item['item'] ?? null) ? $item['item'] : [])) {
                return true;
            }
        }
        return false;
    }
}
