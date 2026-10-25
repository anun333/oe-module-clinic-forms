<?php

/**
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests\Paper;

use OpenHealthcare\ClinicForms\Paper\Document;
use OpenHealthcare\ClinicForms\Paper\DocumentLibrary;
use OpenHealthcare\ClinicForms\Paper\Pack;
use OpenHealthcare\ClinicForms\Paper\Packets;
use OpenHealthcare\ClinicForms\Paper\Requirements;
use OpenHealthcare\ClinicForms\Paper\Taglines;
use OpenHealthcare\ClinicForms\Paper\Translation;
use PHPUnit\Framework\TestCase;

final class PackTest extends TestCase
{
    public function testTheUsPack(): void
    {
        $us = Pack::get('us');
        self::assertSame(['United States', 'letter', ['UDS']], [$us->name, $us->paper, $us->layouts]);
        self::assertCount(52, $us->regions, 'the states, DC and Puerto Rico');
        foreach ($us->regions as $code => $region) {
            self::assertCount(15, $region['languages'], $code);
        }
        self::assertSame('Spanish', $us->regions['TX']['languages'][0]['census']);
    }

    public function testWhichPackASiteUses(): void
    {
        self::assertSame('us', Pack::choose(null, '')[0]?->id, 'a blank facility country is the US, as before packs');
        self::assertSame('us', Pack::choose(null, 'USA')[0]?->id);
        self::assertSame('us', Pack::choose(null, 'united states')[0]?->id);
        self::assertSame('us', Pack::choose(null, ' us ')[0]?->id);
        [$none, $notes] = Pack::choose(null, 'India');
        self::assertNull($none);
        self::assertStringContainsString('no pack for it yet', $notes[0]);
        self::assertSame([null, []], Pack::choose('none', 'USA'), 'the setting wins: core documents only');
        self::assertSame('us', Pack::choose('us', 'India')[0]?->id);
        [$fallback, $notes] = Pack::choose('xx', '');
        self::assertSame('us', $fallback?->id);
        self::assertStringContainsString('there is no "xx" pack', $notes[0]);
    }

    public function testAFacilityStateNamesARegion(): void
    {
        $us = Pack::get('us');
        self::assertSame('TX', $us->region('Texas'));
        self::assertSame('TX', $us->region('tx'));
        self::assertSame('PR', $us->region('Puerto Rico'));
        self::assertNull($us->region(''));
        self::assertNull($us->region('Ontario'));
        self::assertNull($us->regionDir('TX'), 'no state ships documents of its own yet');
    }

    public function testTheTaglineCheckNamesWhatAStateStillNeeds(): void
    {
        $us = Pack::get('us');
        $gaps = $us->taglineGaps('MN', ['en', 'es']);
        self::assertContains('Hmong', $gaps['missing']);
        self::assertContains('Vietnamese', $gaps['missing']);
        self::assertNotContains('Spanish', $gaps['missing']);
        self::assertContains('Amharic, Somali, or other Afro-Asiatic languages', $gaps['groups']);
        self::assertSame(15, count($gaps['missing']) + count($gaps['groups']) + 1, 'every language is counted once (Spanish is covered)');
        self::assertNotContains('Chinese (incl. Mandarin, Cantonese)', $us->taglineGaps('MN', ['en', 'es', 'zh-Hant'])['missing'], 'zh-Hant covers Chinese');
    }

    public function testTheCoreAloneHasNoUsLaw(): void
    {
        $core = DocumentLibrary::shipped('en', null)->all();
        self::assertGreaterThanOrEqual(15, count($core));
        self::assertArrayHasKey('consent-to-treat', $core);
        self::assertArrayNotHasKey('notice-of-privacy-practices', $core);
        foreach ($core as $id => $doc) {
            $text = (string)json_encode([$doc->title, $doc->about, $doc->blocks]);
            foreach (['HIPAA', 'CFR', 'HRSA', 'HHS', 'Section 1557', 'UDS', 'Medicare', 'Medicaid', 'Social Security'] as $us) {
                self::assertStringNotContainsString($us, $text, "$id is a core document but mentions $us; it belongs in the US pack");
            }
        }
        $us = DocumentLibrary::shipped()->all();
        self::assertSame(array_keys($core), array_values(array_intersect(array_keys($us), array_keys($core))), 'the US pack keeps every core document');
        self::assertGreaterThanOrEqual(13, count($us) - count($core));
    }

    public function testCorePacketsRulesAndTaglinesStandAlone(): void
    {
        $core = Packets::shipped(DocumentLibrary::shipped('en', null), null);
        $byId = array_column($core->packets, 'items', 'id');
        self::assertSame(['layout:DEM', 'layout:HIS', 'doc:consent-to-treat', 'doc:patient-rights', 'doc:missed-appointment-policy', 'doc:financial-policy', 'doc:communication-consent'], $byId['new-patient']);
        self::assertArrayNotHasKey('annual-update-short', $byId, 'the UDS packet is the US pack\'s');
        $us = array_column(Packets::shipped(DocumentLibrary::shipped())->packets, 'items', 'id');
        self::assertContains('doc:notice-of-privacy-practices', $us['new-patient'], 'the US pack replaces the core packet');
        self::assertContains('layout:UDS', $us['annual-update-short']);

        self::assertSame(['asq', 'audit-c', 'consult-note', 'gad-7', 'history-and-physical', 'phq-2', 'phq-9', 'plan-of-care', 'procedure-consent', 'procedure-note', 'taps-1'], self::sorted(array_keys(Requirements::shipped(null)->documents)));
        self::assertArrayHasKey('release-of-information', Requirements::shipped()->documents);
        self::assertSame([], Taglines::forSite(null, null)->taglines, 'taglines are Section 1557, so the US pack\'s');
        self::assertSame(['en', 'es'], array_keys(Taglines::forSite(null)->taglines));
    }

    public function testLaterLayersReplaceEarlierOnesByIdAndOutdateTheirTranslations(): void
    {
        $root = sys_get_temp_dir() . '/cf-pack-' . bin2hex(random_bytes(4));
        mkdir("$root/pack", 0777, true);
        mkdir("$root/region", 0777, true);
        try {
            $data = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/data/documents/consent-to-treat.json'), true, 64, JSON_THROW_ON_ERROR);
            $data['blocks'][1]['text'] = 'The pack\'s wording.';
            file_put_contents("$root/pack/consent-to-treat.json", json_encode($data, JSON_THROW_ON_ERROR));
            $data['blocks'][1]['text'] = 'The region\'s wording.';
            file_put_contents("$root/region/consent-to-treat.json", json_encode($data, JSON_THROW_ON_ERROR));
            $layers = [["$root/pack", 'data/packs/zz/documents'], ["$root/region", 'data/packs/zz/regions/ZZ/documents']];

            $library = new DocumentLibrary(dirname(__DIR__, 2) . '/data/documents', null, 'sites/default/documents/clinic-forms', 'en', [$layers[0]]);
            self::assertSame("The pack's wording.", $library->get('consent-to-treat')?->blocks[1]['text'] ?? null);
            $library = new DocumentLibrary(dirname(__DIR__, 2) . '/data/documents', null, 'sites/default/documents/clinic-forms', 'es', $layers);
            $doc = $library->get('consent-to-treat');
            self::assertSame(['en', "The region's wording."], [$doc?->language, $doc?->blocks[1]['text'] ?? null], 'the core Spanish translated other wording');
            self::assertSame('data/documents/es/consent-to-treat.json', $library->outOfDate()['consent-to-treat'] ?? null);

            // A region's own Spanish of its own wording is used.
            mkdir("$root/region/es");
            $es = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/data/documents/es/consent-to-treat.json'), true, 64, JSON_THROW_ON_ERROR);
            $es['blocks'][1]['text'] = 'El texto de la región.';
            $es['translates'] = Translation::fingerprint(DocumentLibrary::read("$root/region/consent-to-treat.json"));
            file_put_contents("$root/region/es/consent-to-treat.json", json_encode($es, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $library = new DocumentLibrary(dirname(__DIR__, 2) . '/data/documents', null, 'sites/default/documents/clinic-forms', 'es', $layers);
            self::assertSame('El texto de la región.', $library->get('consent-to-treat')?->blocks[1]['text'] ?? null);
            self::assertArrayNotHasKey('consent-to-treat', $library->outOfDate());
        } finally {
            foreach (["$root/region/es", "$root/region", "$root/pack"] as $dir) {
                array_map('unlink', glob("$dir/*.json") ?: []);
                rmdir($dir);
            }
            rmdir($root);
        }
    }

    public function testThePacksSpanishIsUsedLikeTheCores(): void
    {
        // Spanish in the US pack's folder translates the pack's documents, as the core's does its own.
        $spanish = DocumentLibrary::shipped('es');
        self::assertTrue($spanish->isTranslated('notice-of-privacy-practices'));
        self::assertTrue($spanish->isTranslated('consent-to-treat'));
        foreach (glob(dirname(__DIR__, 2) . '/data/packs/us/documents/es/*.json') ?: [] as $file) {
            $id = basename($file, '.json');
            self::assertNotNull(DocumentLibrary::shipped('en', 'us')->get($id), "$id translates a document that exists");
            self::assertSame('patient', $spanish->get($id)?->audience, "$id: only patient documents are translated");
        }
        self::assertInstanceOf(Document::class, $spanish->get('notice-of-privacy-practices'));
    }

    /** @param list<string> $list @return list<string> */
    private static function sorted(array $list): array
    {
        sort($list);
        return $list;
    }
}
