<?php

/**
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests\Paper;

use OpenHealthcare\ClinicForms\Paper\Taglines;
use PHPUnit\Framework\TestCase;

final class TaglinesTest extends TestCase
{
    public function testEnglishAndSpanishShip(): void
    {
        $t = Taglines::forSite(null);
        self::assertSame(['en', 'es'], array_keys($t->taglines));
        self::assertStringContainsString('free language assistance services and free auxiliary aids and services', $t->taglines['en']);
        self::assertStringContainsString('{facility.phone}', $t->taglines['es']);
        self::assertStringStartsWith('ATENCIÓN', $t->forPage('es')[0]);
        self::assertStringStartsWith('ATTENTION', $t->forPage('en')[0]);
    }

    public function testAClinicAddsItsStatesLanguages(): void
    {
        $site = $this->site(['taglines' => ['vi' => 'CHÚ Ý: Gọi {facility.phone}.', 'zh-Hant' => '注意：請致電 {facility.phone}。']]);
        $t = Taglines::forSite($site);
        self::assertSame(['en', 'es', 'vi', 'zh-Hant'], array_keys($t->taglines));
        self::assertSame([], $t->problems);
    }

    public function testABrokenClinicFileKeepsTheShippedTaglines(): void
    {
        $t = Taglines::forSite($this->site(['taglines' => ['Vietnamese' => 'x']]));
        self::assertSame(['en', 'es'], array_keys($t->taglines));
        self::assertStringContainsString('"Vietnamese" must be a language code', $t->problems[0]);
        $t = Taglines::forSite($this->site(['taglines' => ['vi' => 'Gọi {patient.name}']]));
        self::assertStringContainsString('only {facility.…} placeholders', $t->problems[0]);
    }

    /** @param array<string, mixed> $data */
    private function site(array $data): string
    {
        $site = sys_get_temp_dir() . '/cf-tag-' . bin2hex(random_bytes(4));
        mkdir($site . '/documents/clinic-forms', 0777, true);
        file_put_contents($site . '/documents/clinic-forms/taglines.json', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        register_shutdown_function(static function () use ($site): void {
            @unlink($site . '/documents/clinic-forms/taglines.json');
            @rmdir($site . '/documents/clinic-forms');
            @rmdir($site . '/documents');
            @rmdir($site);
        });
        return $site;
    }
}
