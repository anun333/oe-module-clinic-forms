<?php

/**
 * Offers an added questionnaire in visits of the types a clinic picks, through OpenEMR's own
 * questionnaire availability (8.5 and later, #14485): the questionnaire becomes an encounter form
 * of its own, and one encounter rule per visit type lists it in those visits' Questionnaires menu.
 *
 * Only adds. A rule an administrator wrote is never changed or removed here, so a questionnaire
 * already offered in every visit stays that way; offer() says so, for the page to tell the clinic
 * where to narrow it (the Availability tab). With no visit type picked nothing is done, and the
 * questionnaire is offered as before, through New Questionnaire.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Fhir;

final class VisitOffering
{
    /**
     * @param \Closure(int, string): void $registerForm makes the questionnaire (id, title) an encounter form, unless it is one
     * @param \Closure(int, int): void $assign an encounter rule for the questionnaire in visits of that type (category id)
     * @param \Closure(int): bool $offeredEverywhere whether a rule already offers the questionnaire in every visit
     */
    public function __construct(private \Closure $registerForm, private \Closure $assign, private \Closure $offeredEverywhere)
    {
    }

    /**
     * Offers one questionnaire in visits of these types.
     *
     * @param list<int> $visitTypes calendar category ids, as chosen() returns them
     * @return array{offered: bool, everywhere: bool} whether rules were added, and whether it was already in every visit
     */
    public function offer(int $questionnaireId, string $title, array $visitTypes): array
    {
        if ($questionnaireId <= 0 || $visitTypes === []) {
            return ['offered' => false, 'everywhere' => false];
        }
        ($this->registerForm)($questionnaireId, $title);
        foreach ($visitTypes as $visitType) {
            ($this->assign)($questionnaireId, $visitType);
        }
        return ['offered' => true, 'everywhere' => ($this->offeredEverywhere)($questionnaireId)];
    }

    /**
     * The visit types picked on the page, kept only where the site has them, each once, in the
     * site's order.
     *
     * @param mixed $posted the form's visit_type[] values
     * @param array<int, string> $known the site's visit types, id => name
     * @return list<int>
     */
    public static function chosen(mixed $posted, array $known): array
    {
        $picked = [];
        foreach (is_array($posted) ? $posted : [] as $value) {
            if (is_string($value) && ctype_digit($value)) {
                $picked[(int)$value] = true;
            } elseif (is_int($value)) {
                $picked[$value] = true;
            }
        }
        return array_values(array_filter(array_keys($known), static fn(int $id): bool => isset($picked[$id])));
    }
}
