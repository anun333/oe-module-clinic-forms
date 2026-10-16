<?php

/**
 * A layout form (Demographics, History, an LBF encounter form or an LBT transaction form) as the site has it:
 * its groups, its fields and the options of the lists its fields use. Read by SiteData; printed
 * by LayoutPaper.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Paper;

final readonly class LayoutForm
{
    /**
     * @param array<string, string>        $groups group id => title, in the layout's order
     * @param list<array{field_id: string, group_id: string, title: string, data_type: int, list_id: string, fld_rows: int, description: string}> $fields
     *                                             the fields in use (uor > 0), in the layout's order
     * @param array<string, list<string>>  $lists  list id => its active options' titles, in order
     * @param array<string, list<string>>  $common field id => the titles of the options this site's
     *                                             patients use most, most used first; only for fields
     *                                             whose list is too long to print in full
     * @param array<string, array<string, string>> $optionTitles list id => option id => title,
     *                                             for reading a patient's stored answers
     */
    public function __construct(
        public string $id,
        public string $title,
        public array $groups,
        public array $fields,
        public array $lists,
        public array $common = [],
        public array $optionTitles = [],
    ) {
    }

    /**
     * Demographics, History, an LBF encounter form or an LBT transaction form (Referral...), or
     * UDS: the Demographics fields health center reporting uses, as a short form of their own.
     */
    public static function isPrintable(string $formId): bool
    {
        return preg_match('/^(DEM|HIS|UDS|LBF\w+|LBT\w+)$/', $formId) === 1;
    }
}
