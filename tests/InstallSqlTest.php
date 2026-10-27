<?php

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The install SQL only adds the two indexes the answers kept on file need, each under a guard, and the
 * response_id one is word for word the line the UDS, Title X, CCBHC and Worklist modules add, so whichever
 * module runs first creates it and the others skip it. version.php is what lets Module Manager offer it.
 */
final class InstallSqlTest extends TestCase
{
    private const SHARED = "#IfNotIndex form_questionnaire_assessments response_id\n"
        . "ALTER TABLE `form_questionnaire_assessments` ADD INDEX `response_id` (`response_id`(64));\n"
        . "#EndIf";

    private const PATIENT = "#IfNotIndex questionnaire_response patient_id\n"
        . "ALTER TABLE `questionnaire_response` ADD INDEX `patient_id` (`patient_id`);\n"
        . "#EndIf";

    private function sql(): string
    {
        return (string)file_get_contents(dirname(__DIR__) . '/sql/install.sql');
    }

    public function testBothIndexesAreAddedUnderTheirGuards(): void
    {
        self::assertStringContainsString(self::PATIENT, $this->sql());
        self::assertStringContainsString(self::SHARED, $this->sql());
    }

    public function testNothingElseIsCreatedOrChanged(): void
    {
        $statements = array_values(array_filter(
            array_map('trim', explode("\n", $this->sql())),
            static fn(string $line): bool => $line !== '' && !str_starts_with($line, '--') && !str_starts_with($line, '#'),
        ));
        self::assertCount(2, $statements);
        foreach ($statements as $statement) {
            self::assertMatchesRegularExpression('/^ALTER TABLE `[a-z_]+` ADD INDEX `[a-z_]+` \(`[a-z_]+`(\(\d+\))?\);$/', $statement);
        }
    }

    public function testTheSharedIndexMatchesTheOtherModulesWordForWord(): void
    {
        $found = 0;
        foreach (['oe-module-uds-report', 'oe-module-title-x-report', 'oe-module-ccbhc-report', 'oe-module-staff-worklist'] as $module) {
            $file = dirname(__DIR__, 2) . "/$module/sql/install.sql";
            if (!is_file($file)) {
                continue; // only where the other modules are checked out beside this one
            }
            $found++;
            self::assertStringContainsString(self::SHARED, (string)file_get_contents($file), $module);
        }
        if ($found === 0) {
            self::markTestSkipped('no other module is checked out beside this one');
        }
    }

    public function testModuleManagerCanOfferTheInstallSql(): void
    {
        $file = dirname(__DIR__) . '/version.php';
        self::assertFileExists($file);
        self::assertMatchesRegularExpression("/\\\$v_major = '\\d+';\\s+\\\$v_minor = '\\d+';\\s+\\\$v_patch = '\\d+';/", (string)file_get_contents($file));
    }
}
