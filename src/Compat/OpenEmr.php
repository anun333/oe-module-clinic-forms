<?php

/**
 * The OpenEMR calls Clinic Forms makes that changed between releases. The shared ones are
 * openhealthcare-common's OpenEmr, one copy for every module (7.0.x to 8.x: settings, session, CSRF,
 * access denied, audit log); this class keeps the names Clinic Forms's code calls, and the calls only
 * Clinic Forms makes.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Compat;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Services\Questionnaire\AssignmentRecord;
use OpenEMR\Services\Questionnaire\QuestionnaireAvailabilityService;
use OpenEMR\Services\Questionnaire\QuestionnaireSurface;
use OpenEMR\Services\QuestionnaireService;
use OpenHealthcare\Common\OpenEmr as Common;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class OpenEmr
{
    /** A string setting, such as OE_SITE_DIR. */
    public static function setting(string $name): string
    {
        return Common::setting($name);
    }

    public static function siteDir(): string
    {
        return Common::siteDir();
    }

    public static function webRoot(): string
    {
        return Common::webRoot();
    }

    public static function projectDir(): string
    {
        return Common::projectDir();
    }

    /** The event dispatcher, for subscribing to OpenEMR's events. */
    public static function eventDispatcher(): EventDispatcherInterface
    {
        return Common::eventDispatcher();
    }

    /** Sends an event to OpenEMR's dispatcher for other modules to answer; without a kernel, nothing happens. */
    public static function dispatch(object $event, string $name): void
    {
        Common::dispatch($event, $name);
    }

    /** A value from the logged-in user's session: pid, authUser, authProvider, authUserID. */
    public static function session(string $key): mixed
    {
        return Common::session($key);
    }

    public static function csrfToken(): string
    {
        return Common::csrfToken();
    }

    /** Ends the request (OpenEMR's own CSRF failure page) unless the posted token is valid. */
    public static function requireCsrf(mixed $token): void
    {
        Common::requireCsrf($token);
    }

    /** Ends the request with OpenEMR's access-denied page, or a plain 403 on releases without one. */
    public static function deny(string $logMessage, string $title): never
    {
        Common::deny($logMessage, $title, 'Clinic Forms');
    }

    /** Whether the logged-in user may add to patients' Documents (OpenEMR's own check for uploading). */
    public static function canWriteDocuments(): bool
    {
        return (bool)AclMain::aclCheckCore('patients', 'docs', '', ['write', 'addonly']);
    }

    /**
     * Files a copy in the patient's Documents through OpenEMR's own Document class, as the patient
     * portal does with its PDFs. @return array{0: ?int, 1: string} the new document's id, or null and why not
     */
    public static function saveDocument(int $pid, int $categoryId, string $filename, string $mimeType, string $data): array
    {
        if (!class_exists(\Document::class)) {
            return [null, 'OpenEMR\'s document storage is not available'];
        }
        $document = new \Document();
        $error = $document->createDocument((string)$pid, $categoryId, $filename, $mimeType, $data);
        if (!empty($error)) {
            return [null, (string)$error];
        }
        return [(int)$document->get_id(), ''];
    }

    /**
     * Whether the logged-in user may add to OpenEMR's questionnaires: the check OpenEMR makes before
     * importing forms (Admin > Forms), as the patient portal's template import does.
     */
    public static function canAddQuestionnaires(): bool
    {
        return class_exists(QuestionnaireService::class) && (bool)AclMain::aclCheckCore('admin', 'forms');
    }

    /**
     * The questionnaire OpenEMR keeps under this title, or null.
     *
     * @return ?array{id: int, questionnaire_id: string, source_url: string, active: bool, questionnaire: string}
     */
    public static function questionnaire(string $title): ?array
    {
        $row = (new QuestionnaireService())->fetchQuestionnaireResource($title);
        if (!is_array($row) || !isset($row['id'])) {
            return null;
        }
        $text = static fn(string $key): string => is_scalar($row[$key] ?? null) ? (string)$row[$key] : '';
        return [
            'id' => (int)$row['id'],
            'questionnaire_id' => $text('questionnaire_id'),
            'source_url' => $text('source_url'),
            'active' => $text('active') === '1',
            'questionnaire' => $text('questionnaire'),
        ];
    }

    /**
     * Saves a Questionnaire in OpenEMR's questionnaires under this title, through OpenEMR's own
     * QuestionnaireService: into the row with that title or the Questionnaire's id, or a new row.
     * Filed under Survey (Observation_Types), as OpenEMR's encounter form files its own; 8.0 added
     * the category, and 7.0's method ignores the extra argument.
     *
     * @param array<string, mixed> $q
     * @return int the row's id
     */
    public static function saveQuestionnaire(array $q, string $title): int
    {
        $json = json_encode($q, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $id = (new QuestionnaireService())->saveQuestionnaireResource($json, $title, null, null, null, 'Questionnaire', 'survey');
        return is_numeric($id) ? (int)$id : 0;
    }

    /**
     * Whether OpenEMR offers questionnaires by visit type: its questionnaire availability (8.5 and
     * later, #14485). The calls below run only when it does.
     */
    public static function canOfferByVisitType(): bool
    {
        return class_exists(QuestionnaireAvailabilityService::class) && class_exists(AssignmentRecord::class)
            && enum_exists(QuestionnaireSurface::class);
    }

    /**
     * The site's visit types (its active calendar categories), as OpenEMR's Availability tab lists
     * them.
     *
     * @return array<int, string> category id => name
     */
    public static function visitTypes(): array
    {
        $types = [];
        $rows = QueryUtils::fetchRecords('SELECT `pc_catid`, `pc_catname` FROM `openemr_postcalendar_categories` WHERE `pc_active` = 1 ORDER BY `pc_seq`, `pc_catname`');
        foreach ($rows as $row) {
            if (is_numeric($row['pc_catid'] ?? null) && is_string($row['pc_catname'] ?? null)) {
                $types[(int)$row['pc_catid']] = function_exists('xl_appt_category') ? (string)xl_appt_category($row['pc_catname']) : $row['pc_catname'];
            }
        }
        return $types;
    }

    /**
     * Makes the questionnaire an encounter form of its own, with the registry row OpenEMR's
     * Questionnaire Assessments form writes when it registers one, unless it is one already. That
     * form also adds a rule offering it in every visit; this doesn't, since the rules come from
     * the visit types picked.
     */
    public static function registerEncounterForm(int $questionnaireId, string $title): void
    {
        $registered = QueryUtils::fetchRecords(
            "SELECT `id` FROM `registry` WHERE `directory` = 'questionnaire_assessments' AND `form_foreign_id` = ? LIMIT 1",
            [$questionnaireId]
        );
        if ($registered !== []) {
            return;
        }
        QueryUtils::sqlInsert(
            "INSERT INTO `registry` SET `name` = ?, `state` = 1, `directory` = 'questionnaire_assessments', `sql_run` = 1, "
                . "`unpackaged` = 1, `category` = 'Questionnaires', `date` = NOW(), `form_foreign_id` = ?",
            [$title, $questionnaireId]
        );
    }

    /**
     * A rule offering the questionnaire in visits of this type, through OpenEMR's own service, which
     * updates a matching rule rather than adding a second.
     */
    public static function offerInVisitType(int $questionnaireId, int $visitType): void
    {
        (new QuestionnaireAvailabilityService())->assign(
            new AssignmentRecord(0, QuestionnaireSurface::Encounter, resourceId: $questionnaireId, visitCategoryId: $visitType)
        );
    }

    /** Whether an active rule already offers the questionnaire in every visit (no visit type, facility or provider). */
    public static function offeredInEveryVisit(int $questionnaireId): bool
    {
        return QueryUtils::fetchRecords(
            "SELECT `id` FROM `content_assignments` WHERE `resource_type` = 'questionnaire' AND `surface` = 'encounter' AND `resource_id` = ? "
                . "AND `active` = 1 AND `facility` IS NULL AND `provider` IS NULL AND `visit_category_id` IS NULL "
                . "AND (`client_id` IS NULL OR `client_id` = '') LIMIT 1",
            [$questionnaireId]
        ) !== [];
    }

    /** Writes an event to OpenEMR's audit log as the logged-in user. */
    public static function audit(string $event, string $comment, int $pid = 0): void
    {
        Common::audit($event, $comment, $pid);
    }
}
