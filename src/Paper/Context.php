<?php

/**
 * What a printed document is filled with: the clinic (one facility, and its own contacts from
 * settings.json), and optionally a patient, the facts the patient's chart already holds (carried
 * into a document's questions, printed for the patient to check and correct: see Fill), and a
 * recipient (an Address Book entry, for referral letters). Every value is plain text; anything
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
        'patient' => ['name', 'dob', 'id', 'address', 'street', 'city', 'state', 'zip', 'phone', 'sex', 'allergies', 'medications', 'problems'],
        'recipient' => ['name', 'organization', 'specialty', 'address', 'phone', 'fax', 'email'],
        'user' => ['name'],
        'provider' => ['name', 'credentials', 'npi', 'specialty'],
        'clinic' => ['today', 'privacy', 'privacyphone', 'privacyemail', 'noticedate', 'coordinator', 'coordinatorphone', 'coordinatoremail'],
        'chart' => [
            'email', 'mobile', 'language', 'interpreter', 'race', 'ethnicity', 'emergency', 'emergencyphone', 'pharmacy',
            'guardian', 'guardianrelationship', 'guardianphone', 'pcp', 'householdsize', 'monthlyincome',
            'housing', 'veteran', 'agricultural', 'insurance', 'memberid',
        ],
        // Answers kept on file from an earlier form (Kept::keys()).
        'kept' => [
            'repname', 'reprelationship', 'repauthority', 'repphone', 'repaddress', 'repemail', 'repother', 'repstarts', 'repends',
            'packsperday', 'yearssmoked', 'yearquit', 'familycancers', 'countries', 'school', 'grade', 'livewith', 'othercare',
            'medicines', 'readlanguage', 'reprole',
        ],
    ];

    /**
     * @param array<string, string> $facility  keys from KEYS['facility']
     * @param array<string, string> $patient   keys from KEYS['patient']; empty for a blank form
     * @param array<string, string> $recipient keys from KEYS['recipient']; empty when none is chosen
     * @param string                $today     the date printed for {clinic.today}, as the site formats dates
     * @param ?string               $logoUrl   the clinic's logo, or null for none
     * @param array<string, string> $user      keys from KEYS['user']: the person printing
     * @param array<string, string> $provider  keys from KEYS['provider']: the clinician who signs letters; empty when none is chosen
     * @param array<string, string> $chart     keys from KEYS['chart']: what the patient's chart holds, shown as text; empty for a blank form
     * @param array<string, string> $site      the clinic's own contacts (KEYS['clinic'] except today), from settings.json
     * @param array<string, string> $chartCodes for the chart facts a checklist ticks (housing, veteran, agricultural): the stored option id
     * @param array<string, array{value: string, from: string, date: string}> $kept answers kept on file from an earlier form (Kept), with the form and the date they came from
     */
    public function __construct(
        public array $facility,
        public array $patient = [],
        public array $recipient = [],
        public string $today = '',
        public ?string $logoUrl = null,
        public array $user = [],
        public array $provider = [],
        public array $chart = [],
        public array $site = [],
        public array $chartCodes = [],
        public array $kept = [],
    ) {
    }

    /** The same clinic, recipient, date and provider, with no patient: for a document filed without a name. */
    public function withoutPatient(): self
    {
        return new self($this->facility, [], $this->recipient, $this->today, $this->logoUrl, $this->user, $this->provider, [], $this->site);
    }

    /** The stored option id behind a chart fact ("chart.housing" => "doubling_up"), or '' when none is on file. */
    public function code(string $placeholder): string
    {
        [$group, $key] = array_pad(explode('.', $placeholder, 2), 2, '');
        return $group === 'chart' ? trim($this->chartCodes[$key] ?? '') : '';
    }

    /** The value of "group.key", or '' when it isn't known. */
    public function value(string $placeholder): string
    {
        [$group, $key] = array_pad(explode('.', $placeholder, 2), 2, '');
        $value = match ($group) {
            'facility' => $this->facility[$key] ?? '',
            'patient' => $this->patient[$key] ?? '',
            'recipient' => $this->recipient[$key] ?? '',
            'user' => $this->user[$key] ?? '',
            'provider' => $this->provider[$key] ?? '',
            'clinic' => $key === 'today' ? $this->today : ($this->site[$key] ?? ''),
            'chart' => $this->chart[$key] ?? '',
            'kept' => $this->kept[$key]['value'] ?? '',
            default => '',
        };
        return trim($value);
    }

    /**
     * Where a kept answer came from ("kept.repname"): the form and the date it was given, or null.
     *
     * @return ?array{from: string, date: string}
     */
    public function keptFrom(string $placeholder): ?array
    {
        [$group, $key] = array_pad(explode('.', $placeholder, 2), 2, '');
        $kept = $group === 'kept' ? ($this->kept[$key] ?? null) : null;
        return $kept !== null && trim($kept['value']) !== '' ? ['from' => $kept['from'], 'date' => $kept['date']] : null;
    }

    public static function isKnown(string $placeholder): bool
    {
        [$group, $key] = array_pad(explode('.', $placeholder, 2), 2, '');
        return in_array($key, self::KEYS[$group] ?? [], true);
    }
}
