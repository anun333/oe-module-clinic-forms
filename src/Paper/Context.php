<?php

/**
 * What a printed document is filled with: the clinic (one facility), and optionally a patient and
 * a recipient (an Address Book entry, for referral letters). Every value is plain text; anything
 * missing prints as a blank line to fill in by hand.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Paper;

final readonly class Context
{
    /** The placeholders a document may use, by group. */
    public const KEYS = [
        'facility' => ['name', 'address', 'phone', 'fax', 'email', 'website', 'npi'],
        'patient' => ['name', 'dob', 'id', 'address', 'phone'],
        'recipient' => ['name', 'organization', 'specialty', 'address', 'phone', 'fax', 'email'],
        'clinic' => ['today'],
    ];

    /**
     * @param array<string, string> $facility  keys from KEYS['facility']
     * @param array<string, string> $patient   keys from KEYS['patient']; empty for a blank form
     * @param array<string, string> $recipient keys from KEYS['recipient']; empty when none is chosen
     * @param string                $today     the date printed for {clinic.today}, as the site formats dates
     * @param ?string               $logoUrl   the clinic's logo, or null for none
     */
    public function __construct(
        public array $facility,
        public array $patient = [],
        public array $recipient = [],
        public string $today = '',
        public ?string $logoUrl = null,
    ) {
    }

    /** The value of "group.key", or '' when it isn't known. */
    public function value(string $placeholder): string
    {
        [$group, $key] = array_pad(explode('.', $placeholder, 2), 2, '');
        $value = match ($group) {
            'facility' => $this->facility[$key] ?? '',
            'patient' => $this->patient[$key] ?? '',
            'recipient' => $this->recipient[$key] ?? '',
            'clinic' => $key === 'today' ? $this->today : '',
            default => '',
        };
        return trim($value);
    }

    public static function isKnown(string $placeholder): bool
    {
        [$group, $key] = array_pad(explode('.', $placeholder, 2), 2, '');
        return in_array($key, self::KEYS[$group] ?? [], true);
    }
}
