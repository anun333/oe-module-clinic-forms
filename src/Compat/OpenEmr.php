<?php

/**
 * The OpenEMR calls Clinic Forms makes that changed between releases, made once here so the
 * module runs on 7.0.x as well as 8.x. Each uses the newer interface when the running OpenEMR
 * has it and the 7.0 way otherwise:
 *
 *   settings and web root   OEGlobalsBag (8.0+)               or $GLOBALS
 *   the session             SessionWrapperFactory (8.0+)      or $_SESSION
 *   CSRF tokens             CsrfUtils with a session argument  or without one (7.0)
 *   access denied           AccessDeniedHelper (8.1+)          or a plain 403 page
 *   the audit log           EventAuditLogger::getInstance()    or ::instance() (7.0's Singleton trait)
 *
 * Measured against the v7_0_3 and v8_0_0 tags and the official 7.0.3 and 8.x images (2026-10-16).
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Compat;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Services\QuestionnaireService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class OpenEmr
{
    private const GLOBALS_BAG = 'OpenEMR\\Core\\OEGlobalsBag';
    private const SESSION_FACTORY = 'OpenEMR\\Common\\Session\\SessionWrapperFactory';
    private const ACCESS_DENIED = 'OpenEMR\\Common\\Acl\\AccessDeniedHelper';

    /** A string setting, such as OE_SITE_DIR. */
    public static function setting(string $name): string
    {
        if (class_exists(self::GLOBALS_BAG)) {
            return (string)self::GLOBALS_BAG::getInstance()->getString($name);
        }
        $value = $GLOBALS[$name] ?? '';
        return is_scalar($value) ? (string)$value : '';
    }

    public static function siteDir(): string
    {
        return self::setting('OE_SITE_DIR');
    }

    public static function webRoot(): string
    {
        return class_exists(self::GLOBALS_BAG) ? (string)self::GLOBALS_BAG::getInstance()->getWebRoot() : self::setting('webroot');
    }

    public static function projectDir(): string
    {
        return class_exists(self::GLOBALS_BAG) ? (string)self::GLOBALS_BAG::getInstance()->getProjectDir() : self::setting('fileroot');
    }

    /** The event dispatcher, for subscribing to OpenEMR's events (the menu). */
    public static function eventDispatcher(): EventDispatcherInterface
    {
        $kernel = class_exists(self::GLOBALS_BAG) ? self::GLOBALS_BAG::getInstance()->getKernel() : ($GLOBALS['kernel'] ?? null);
        $dispatcher = is_object($kernel) && method_exists($kernel, 'getEventDispatcher') ? $kernel->getEventDispatcher() : null;
        if (!$dispatcher instanceof EventDispatcherInterface) {
            throw new \RuntimeException('OpenEMR kernel not available');
        }
        return $dispatcher;
    }

    /** A value from the logged-in user's session: pid, authUser, authProvider, authUserID. */
    public static function session(string $key): mixed
    {
        $session = self::sessionObject();
        if ($session !== null) {
            return $session->get($key);
        }
        return $_SESSION[$key] ?? null;
    }

    public static function csrfToken(): string
    {
        $session = self::sessionObject();
        return $session !== null && self::takesSession('collectCsrfToken')
            ? (string)CsrfUtils::collectCsrfToken(session: $session)
            : (string)CsrfUtils::collectCsrfToken();
    }

    /** Ends the request (OpenEMR's own CSRF failure page) unless the posted token is valid. */
    public static function requireCsrf(mixed $token): void
    {
        $token = is_string($token) ? $token : '';
        $session = self::sessionObject();
        $valid = $session !== null && self::takesSession('verifyCsrfToken')
            ? CsrfUtils::verifyCsrfToken($token, session: $session)
            : CsrfUtils::verifyCsrfToken($token);
        if (!$valid) {
            // 8.x answers 403; 7.0 prints the same refusal with 200. Make it 403 everywhere.
            if (!headers_sent()) {
                http_response_code(403);
            }
            CsrfUtils::csrfNotVerified();
            exit;
        }
    }

    /** Ends the request with OpenEMR's access-denied page, or a plain 403 on releases without one. */
    public static function deny(string $logMessage, string $title): never
    {
        if (class_exists(self::ACCESS_DENIED)) {
            self::ACCESS_DENIED::denyWithTemplate($logMessage, $title);
            exit;
        }
        error_log('Clinic Forms: ' . $logMessage);
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>' . htmlspecialchars($title, ENT_QUOTES) . '</title></head><body><h1>'
            . htmlspecialchars(xl('Not authorized'), ENT_QUOTES) . '</h1></body></html>';
        exit;
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

    /** Writes an event to OpenEMR's audit log as the logged-in user. */
    public static function audit(string $event, string $comment, int $pid = 0): void
    {
        $logger = method_exists(EventAuditLogger::class, 'getInstance') ? EventAuditLogger::getInstance() : EventAuditLogger::instance();
        $logger->newEvent($event, (string)self::session('authUser'), (string)self::session('authProvider'), 1, $comment, $pid);
    }

    private static function sessionObject(): ?object
    {
        if (!class_exists(self::SESSION_FACTORY)) {
            return null;
        }
        return self::SESSION_FACTORY::getInstance()->getActiveSession();
    }

    private static function takesSession(string $method): bool
    {
        foreach ((new \ReflectionMethod(CsrfUtils::class, $method))->getParameters() as $parameter) {
            if ($parameter->getName() === 'session') {
                return true;
            }
        }
        return false;
    }
}
