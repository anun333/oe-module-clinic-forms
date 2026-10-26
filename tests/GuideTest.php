<?php

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests;

use OpenHealthcare\ClinicForms\Guide;
use OpenHealthcare\Common\GuideLabels;
use PHPUnit\Framework\TestCase;

/**
 * GUIDE.md quotes the labels staff click in bold; each must be a string the module translates.
 * Help opens the guide at the installed version's tag.
 */
final class GuideTest extends TestCase
{
    public function testEveryLabelTheGuideQuotesIsOnThePages(): void
    {
        $result = GuideLabels::check(dirname(__DIR__));
        self::assertGreaterThan(20, $result['labels'], 'the guide quotes its labels');
        self::assertGreaterThan(100, $result['strings'], 'the translated strings were read');
        self::assertSame([], $result['missing']);
    }

    public function testHelpOpensTheGuideAtThisVersionsTag(): void
    {
        self::assertSame(1, preg_match('/ v(\d+\.\d+\.\d+)\b/', (string)file_get_contents(dirname(__DIR__) . '/info.txt'), $m));
        self::assertSame("https://github.com/anun333/oe-module-clinic-forms/blob/v{$m[1]}/GUIDE.md", Guide::url());
        self::assertSame('https://github.com/anun333/oe-module-clinic-forms/blob/main/GUIDE.md', Guide::url('/nonexistent/info.txt'));
    }
}
