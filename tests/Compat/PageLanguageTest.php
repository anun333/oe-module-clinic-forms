<?php

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests\Compat;

use OpenHealthcare\ClinicForms\Compat\OpenEmr;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class PageLanguageTest extends TestCase
{
    public function testWithNoSessionThePageIsEnglishLeftToRight(): void
    {
        self::assertSame('en', OpenEmr::pageLang());
        self::assertSame('ltr', OpenEmr::pageDir());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnOlderLibraryLoadedFirstGivesEnglishInsteadOfStoppingThePage(): void
    {
        // What a site with hand-copied modules can load: openhealthcare-common before 1.4.0, without pageLang().
        eval('namespace OpenHealthcare\Common; final class OpenEmr { public static function webRoot(): string { return ""; } }');

        self::assertSame('en', OpenEmr::pageLang());
        self::assertSame('ltr', OpenEmr::pageDir());
    }

    public function testThePickerDeclaresTheUsersLanguageAndNamesTheSpanishBadge(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../../public/index.php');
        self::assertStringNotContainsString('<html lang="en">', $source);
        self::assertStringContainsString('lang="<?php echo attr(OpenEmr::pageLang()); ?>"', $source);
        self::assertStringNotContainsString('<span class="badge badge-info">ES</span>', $source, 'a bare ES reads as two letters');
        self::assertStringContainsString("<span class=\"sr-only\">' . xlt('Spanish') . '</span>", $source);
    }
}
