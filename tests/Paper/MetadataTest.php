<?php

/**
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests\Paper;

use OpenHealthcare\ClinicForms\Paper\Conformance;
use OpenHealthcare\ClinicForms\Paper\Context;
use OpenHealthcare\ClinicForms\Paper\Document;
use OpenHealthcare\ClinicForms\Paper\DocumentLibrary;
use OpenHealthcare\ClinicForms\Paper\DocumentVersions;
use OpenHealthcare\ClinicForms\Paper\LayoutForm;
use OpenHealthcare\ClinicForms\Paper\Pack;
use OpenHealthcare\ClinicForms\Paper\Packets;
use OpenHealthcare\ClinicForms\Paper\Renderer;
use OpenHealthcare\ClinicForms\Paper\Settings;
use OpenHealthcare\ClinicForms\Paper\Translation;
use PHPUnit\Framework\TestCase;

final class MetadataTest extends TestCase
{
    private string $site = '';

    protected function tearDown(): void
    {
        if ($this->site !== '') {
            $dir = $this->site . '/' . DocumentLibrary::LOCAL_FOLDER;
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
            rmdir(dirname($dir));
            rmdir($this->site);
            $this->site = '';
        }
    }

    public function testEveryShippedDocumentSaysItsVersionStatusSourceAndLicence(): void
    {
        $shipped = DocumentVersions::shipped();
        self::assertGreaterThanOrEqual(28, count($shipped));
        foreach ($shipped as $key => $doc) {
            self::assertGreaterThanOrEqual(1, $doc->version, $key);
            // Active, unless held back from release with its reason (data/release-gates.json), when it's a draft.
            self::assertSame(isset(Conformance::held()[$doc->id]) ? 'draft' : 'active', $doc->status, $key);
            self::assertNotNull($doc->origin, "$key has a source");
            self::assertNotNull($doc->licence, "$key has a licence");
            if ($doc->validated) {
                self::assertSame('instrument', $doc->origin['kind'], $key);
                self::assertArrayHasKey('permission', $doc->licence, "$key: a validated instrument records its permission");
                self::assertStringStartsWith('https://', $doc->origin['url'] ?? '', $key);
            }
            if ($doc->origin['kind'] === 'rule') {
                self::assertStringStartsWith('https://', $doc->origin['url'] ?? '', "$key links to its rule");
            }
        }
        self::assertSame('Pfizer Inc.', $shipped['documents/phq-9']->licence['holder'] ?? null);
    }

    public function testEveryVersionIsRecordedWithItsWording(): void
    {
        self::assertSame([], DocumentVersions::problems(DocumentVersions::load(), DocumentVersions::shipped()));
    }

    public function testAWordingChangeWithoutANewVersionIsCaught(): void
    {
        $recorded = DocumentVersions::load();
        $doc = DocumentVersions::shipped()['documents/consent-to-treat'];
        $blocks = $doc->blocks;
        $blocks[1]['text'] .= ' One more sentence.';
        $changed = new Document($doc->id, $doc->title, $doc->category, $doc->about, $blocks, $doc->uses, version: 1, origin: $doc->origin, licence: $doc->licence);
        $problems = DocumentVersions::problems($recorded, ['documents/consent-to-treat' => $changed]);
        self::assertStringContainsString('documents/consent-to-treat: its wording changed since version 1 was recorded; raise its version to 2', $problems[0] ?? '');

        $raised = new Document($doc->id, $doc->title, $doc->category, $doc->about, $blocks, $doc->uses, version: 2, origin: $doc->origin, licence: $doc->licence);
        self::assertStringContainsString('version 2 is not recorded', DocumentVersions::problems($recorded, ['documents/consent-to-treat' => $raised])[0] ?? '');
        $after = DocumentVersions::record($recorded, ['documents/consent-to-treat' => $raised]);
        self::assertSame($recorded['documents/consent-to-treat']['1'], $after['documents/consent-to-treat']['1'], 'an earlier version is never rewritten');
        self::assertSame([], DocumentVersions::problems($after, ['documents/consent-to-treat' => $raised]));
        self::assertSame(['documents/consent-to-treat: version 1 to 2'], DocumentVersions::changes($recorded, $after));
        self::assertSame(['documents/x: new (version 1)', 'documents/consent-to-treat: removed'], DocumentVersions::changes(
            ['documents/consent-to-treat' => ['1' => 'a']],
            ['documents/x' => ['1' => 'b']],
        ));
    }

    public function testThePageFootNamesTheDocumentAndItsVersion(): void
    {
        $doc = DocumentLibrary::shipped()->get('consent-to-treat') ?? throw new \LogicException();
        $html = (new Renderer(new Context(['name' => 'C'])))->document($doc);
        self::assertStringContainsString("<tfoot><tr><td><div class='cf-foot'><div class='cf-stamp'>consent-to-treat v1</div></div></td></tr></tfoot>", $html);
        $draft = new Document('x', 'X', 'C', 'A', [['type' => 'paragraph', 'text' => 'Hi']], [], 'clinic', version: 3, status: 'draft');
        self::assertSame('x v3 · borrador · versión local', (new Renderer(new Context(['name' => 'C']), 'es'))->stamp($draft));
        $layout = (new Renderer(new Context(['name' => 'C'])))->layoutForm(new LayoutForm('DEM', 'Registration', ['1' => 'Who'], [], []));
        self::assertStringContainsString("<div class='cf-stamp'>layout DEM</div>", $layout);
    }

    public function testAClinicVersionSaysWhenTheModulesVersionHasMoved(): void
    {
        $shipped = DocumentLibrary::shipped()->get('consent-to-treat') ?? throw new \LogicException();
        $current = Translation::fingerprint($shipped);
        $data = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/data/documents/consent-to-treat.json'), true, 64, JSON_THROW_ON_ERROR);

        $data['based_on'] = $current;
        $library = $this->site(['consent-to-treat.json' => $data]);
        self::assertSame([], $library->problems());
        self::assertSame([], $library->unstatedBasis());

        $data['based_on'] = '0123456789abcdef';
        $library = $this->site(['consent-to-treat.json' => $data]);
        self::assertStringContainsString("copied from an earlier version of the module's \"Consent to Treatment\", which is now version 1", $library->problems()[0] ?? '');
        self::assertStringContainsString("data/documents/consent-to-treat.json in the Clinic Forms module folder), then set \"based_on\": \"$current\"", $library->problems()[0] ?? '');

        unset($data['based_on']);
        $library = $this->site(['consent-to-treat.json' => $data]);
        self::assertSame([], $library->problems());
        self::assertSame([['id' => 'consent-to-treat', 'title' => 'Consent to Treatment', 'version' => 1, 'fingerprint' => $current]], $library->unstatedBasis());

        $data['based_on'] = 'nope';
        $library = $this->site(['consent-to-treat.json' => $data]);
        self::assertStringContainsString('based_on: must be the 16-digit fingerprint', $library->problems()[0] ?? '');
    }

    public function testHiddenAndRetiredDocumentsLeaveThePickerAndThePackets(): void
    {
        $retired = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/data/documents/telehealth-consent.json'), true, 64, JSON_THROW_ON_ERROR);
        $retired['status'] = 'retired';
        $library = $this->site(['settings.json' => ['hide' => ['financial-policy', 'nope']], 'telehealth-consent.json' => $retired]);
        self::assertNull($library->get('financial-policy'));
        self::assertNull($library->get('telehealth-consent'));
        self::assertSame(['telehealth-consent' => 'retired', 'financial-policy' => 'hidden'], $library->withdrawn());
        self::assertContains('settings.json: "hide" names nope, which is not a document', $library->problems());
        $packets = array_column(Packets::forSite($library, $this->site)->packets, 'items', 'id');
        self::assertNotContains('doc:financial-policy', $packets['new-patient']);
        self::assertContains('doc:consent-to-treat', $packets['new-patient']);
        self::assertSame(['doc:communication-consent'], $packets['telehealth'], 'the retired consent leaves its packet; the rest stays');
    }

    public function testSettingsFileMistakesAreReportedNotUsed(): void
    {
        $this->site(['settings.json' => ['country' => ' US ', 'hide' => ['x']]]);
        self::assertEquals(new Settings('us', ['x']), Settings::forSite($this->site));
        file_put_contents($this->site . '/' . DocumentLibrary::LOCAL_FOLDER . '/settings.json', '{"hide": "financial-policy"}');
        $bad = Settings::forSite($this->site);
        self::assertSame([], $bad->hide);
        self::assertStringContainsString('"hide" must be a list of document ids', $bad->problems[0] ?? '');
        self::assertEquals(new Settings(), Settings::forSite(null));
        file_put_contents($this->site . '/' . DocumentLibrary::LOCAL_FOLDER . '/settings.json', '{"label_box": true}');
        self::assertTrue(Settings::forSite($this->site)->labelBox);
        file_put_contents($this->site . '/' . DocumentLibrary::LOCAL_FOLDER . '/settings.json', '{"label_box": "yes"}');
        self::assertStringContainsString('"label_box" must be true or false', Settings::forSite($this->site)->problems[0] ?? '');
        self::assertFalse(Settings::forSite($this->site)->labelBox);
        self::assertSame('Medical Record', Settings::forSite(null)->documentsCategory, 'a saved PDF goes under Medical Record unless the clinic says');
        file_put_contents($this->site . '/' . DocumentLibrary::LOCAL_FOLDER . '/settings.json', '{"documents_category": " Clinic paperwork "}');
        self::assertSame('Clinic paperwork', Settings::forSite($this->site)->documentsCategory);
        file_put_contents($this->site . '/' . DocumentLibrary::LOCAL_FOLDER . '/settings.json', '{"documents_category": ""}');
        self::assertStringContainsString('"documents_category" must be the name of a Documents category', Settings::forSite($this->site)->problems[0] ?? '');
    }

    public function testPacksCarryTheirIsoCodes(): void
    {
        $us = Pack::get('us');
        self::assertSame('US', $us->jurisdiction);
        self::assertSame('US-TX', $us->isoRegion('TX'));
        self::assertNull($us->isoRegion(null));
    }

    public function testNoTranslatedStringIsLeftInEnglish(): void
    {
        // A Spanish file whose string is the English one is usually a string the translator missed.
        $same = ['Fax', 'No'];
        $checked = 0;
        foreach (DocumentVersions::shipped() as $key => $english) {
            $file = dirname(__DIR__, 2) . '/data/' . dirname($key) . '/es/' . basename($key) . '.json';
            if (!is_file($file)) {
                continue;
            }
            $es = DocumentLibrary::read($file, 'es', 'shipped', $english, 'es');
            if ($es->translates !== Translation::fingerprint($english)) {
                continue; // out of date: waits for the language pass
            }
            // The wording only: skip logic (ids, conditions) is copied, not translated.
            $a = self::strings([$english->title, $english->category, $english->about, Translation::wording($english->blocks)]);
            $b = self::strings([$es->title, $es->category, $es->about, Translation::wording($es->blocks)]);
            foreach ($a as $i => $text) {
                $checked++;
                $words = trim((string)preg_replace('/\{[a-z]+\.[a-z]+\}/', '', $text));
                if (($b[$i] ?? null) === $text && preg_match('/[A-Za-z]{2}/', $words) === 1) {
                    self::assertContains($text, $same, "$key: \"$text\" is the same in the Spanish");
                }
            }
        }
        self::assertGreaterThan(500, $checked, 'the comparison walked the documents');
    }

    public function testNoSiteDataInShippedFiles(): void
    {
        // A shipped file holds placeholders, never a real clinic's phone, email or street address,
        // except the official contacts a rule makes us print: HHS Office for Civil Rights (Section 1557
        // complaints, and the HIPAA complaint line the privacy notice gives), and CMS's No Surprises
        // help line and email, where a good faith estimate says how to dispute a bill (45 CFR 149.610).
        $official = ['1-800-368-1019', '1-800-537-7697', '1-877-696-6775', '200 Independence Avenue', '1-800-985-3059', 'FederalPPDRQuestions@cms.hhs.gov'];
        $files = array_merge(glob(dirname(__DIR__, 2) . '/data/*.json') ?: [], glob(dirname(__DIR__, 2) . '/data/documents/{,es/}*.json', GLOB_BRACE) ?: [], glob(dirname(__DIR__, 2) . '/data/packs/*/{,documents/,documents/es/}*.json', GLOB_BRACE) ?: []);
        self::assertGreaterThan(50, count($files));
        $seen = [];
        foreach ($files as $file) {
            $text = (string)file_get_contents($file);
            preg_match_all('/\b1?-?\d{3}[-.) ]\s?\d{3}[-. ]\d{4}\b|[\w.+-]+@[\w-]+\.[\w.]+|\b\d{2,5} [A-Z][a-z]+ (?:Street|St\.|Avenue|Ave\.?|Road|Rd\.?|Boulevard|Blvd\.?)\b/', $text, $m);
            foreach ($m[0] as $found) {
                $seen[$found] = true;
                self::assertContains($found, $official, basename($file) . " contains \"$found\"");
            }
        }
        self::assertArrayHasKey('200 Independence Avenue', $seen, 'the pattern finds an address (the official one)');
    }

    /** @return list<string> every text in the value, in order */
    private static function strings(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $key => $item) {
            if (!in_array($key, ['type', 'count', 'rows', 'scores'], true)) {
                $out = array_merge($out, self::strings($item));
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $files the clinic's folder: file name => JSON value */
    private function site(array $files): DocumentLibrary
    {
        if ($this->site === '') {
            $this->site = sys_get_temp_dir() . '/cf-site-' . bin2hex(random_bytes(4));
            mkdir($this->site . '/' . DocumentLibrary::LOCAL_FOLDER, 0777, true);
        }
        $dir = $this->site . '/' . DocumentLibrary::LOCAL_FOLDER;
        array_map('unlink', glob($dir . '/*') ?: []);
        foreach ($files as $name => $value) {
            file_put_contents("$dir/$name", json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
        return DocumentLibrary::forSite($this->site);
    }
}
