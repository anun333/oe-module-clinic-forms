<?php

/**
 * Every shipped document is held to what it claims to follow, or held back from release with its
 * reason (src/Paper/Conformance.php): a rule's elements, a validated instrument's LOINC reference,
 * another instrument's publisher wording. A new document of any kind fails here until it is.
 *
 * @package   OpenHealthcare\ClinicForms
 * @link      https://github.com/anun333/oe-module-clinic-forms
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests\Paper;

use OpenHealthcare\ClinicForms\Paper\Conformance;
use OpenHealthcare\ClinicForms\Paper\Document;
use OpenHealthcare\ClinicForms\Paper\DocumentLibrary;
use OpenHealthcare\ClinicForms\Paper\Packets;
use OpenHealthcare\ClinicForms\Paper\Requirements;
use PHPUnit\Framework\TestCase;

final class ConformanceTest extends TestCase
{
    public function testEveryDocumentIsHeldToItsSourceOrHeldBack(): void
    {
        $requirements = Requirements::shipped();
        $problems = [];
        $kinds = [];
        foreach (DocumentLibrary::shipped()->all() as $doc) {
            $c = Conformance::of($doc, $requirements);
            $kinds[$c['kind']] = ($kinds[$c['kind']] ?? 0) + 1;
            foreach ($c['problems'] as $p) {
                $problems[] = "{$doc->id}: $p";
            }
            self::assertNotSame([], $c['checks'], "{$doc->id} says how it is checked");
        }
        self::assertSame([], $problems);
        // A floor, so a library that read nothing doesn't pass: every kind is there.
        self::assertGreaterThan(20, $kinds['rule'] ?? 0);
        self::assertGreaterThan(15, $kinds['instrument'] ?? 0);
        self::assertGreaterThan(20, $kinds['written'] ?? 0);
    }

    public function testThePublishersWordingIsChecked(): void
    {
        self::assertSame(['bstad', 'everyday-discrimination', 'healthy-days'], self::sorted(array_keys(Conformance::references())));
        $doc = DocumentLibrary::shipped()->get('bstad');
        self::assertInstanceOf(Document::class, $doc);
        // Control: the same check on a document with one question reworded finds it.
        $json = (string)json_encode($doc->blocks);
        $changed = self::with($doc, ['blocks' => json_decode(str_replace('on how many days did you use heroin?', 'on how many days did you take heroin?', $json), true)]);
        $found = Conformance::of($changed, Requirements::shipped())['problems'];
        self::assertSame(['not as published: "In the PAST YEAR, on how many days did you use heroin?"'], $found);
    }

    public function testAHeldDocumentIsADraftInNoPacket(): void
    {
        $library = DocumentLibrary::shipped();
        $held = Conformance::held();
        // The drafts are exactly the held documents (both may be empty, as after 0.35.0 released the visit record).
        $drafts = array_values(array_map(static fn(Document $doc): string => $doc->id, array_filter($library->all(), static fn(Document $doc): bool => $doc->status === 'draft')));
        self::assertSame(self::sorted($drafts), self::sorted(array_keys($held)));
        foreach ($held as $id => $why) {
            $doc = $library->get($id);
            self::assertInstanceOf(Document::class, $doc, "$id is a shipped document");
            self::assertSame('draft', $doc->status, "$id is held, so a draft");
            self::assertGreaterThan(40, strlen($why), "$id says why it is held");
        }
        foreach (Packets::shipped($library)->packets as $packet) {
            foreach ($packet['items'] as $item) {
                self::assertArrayNotHasKey(substr($item, 4), str_starts_with($item, 'doc:') ? $held : [], "{$packet['id']} offers a held document");
            }
        }
    }

    public function testSnomedUsExtensionCodesAreHeldAutomatically(): void
    {
        $doc = DocumentLibrary::shipped()->get('title-x-visit-record');
        self::assertInstanceOf(Document::class, $doc);
        // The visit record as it was before 0.35.0: two US Extension pregnancy-intention answers.
        $blocks = $doc->blocks;
        foreach ($blocks as $i => $block) {
            if (($block['code']['code'] ?? '') === '86645-9') {
                $blocks[$i]['answer_codes'][0] = ['system' => 'http://snomed.info/sct', 'code' => '454411000124108', 'display' => 'Wants to become pregnant'];
                $blocks[$i]['answer_codes'][1] = ['system' => 'http://snomed.info/sct', 'code' => '454401000124105', 'display' => 'No desire to become pregnant'];
            }
        }
        $unheld = self::with($doc, ['blocks' => $blocks, 'id' => 'title-x-visit-record-copy']);
        self::assertCount(2, Conformance::usExtensionCodes($unheld));
        self::assertContains('2 SNOMED CT US Extension codes, not held for release (data/release-gates.json)', Conformance::of($unheld, Requirements::shipped())['problems']);
    }

    public function testNoShippedDocumentCarriesASnomedUsExtensionCode(): void
    {
        foreach (DocumentLibrary::shipped()->all() as $doc) {
            self::assertSame([], Conformance::usExtensionCodes($doc), "{$doc->id} carries a SNOMED CT US Extension code");
        }
    }

    /** A document with SNOMED CT codes keeps them out of its CC0 dedication and carries the Global Patient Set's notice. */
    public function testSnomedCodesCarryTheGlobalPatientSetNotice(): void
    {
        $checked = 0;
        foreach (DocumentLibrary::shipped()->all() as $doc) {
            if (!str_contains((string)json_encode($doc->blocks, JSON_UNESCAPED_SLASHES), 'http://snomed.info/sct')) {
                continue;
            }
            $checked++;
            $terms = (string)($doc->licence['terms'] ?? '');
            self::assertStringContainsString('It does not cover the codes', $terms, "{$doc->id} keeps SNOMED CT out of CC0");
            self::assertStringContainsString('SNOMED CT Global Patient Set (GPS)', $terms, $doc->id);
            self::assertStringContainsString('CC BY-ND 4.0', $terms, $doc->id);
            self::assertStringContainsString('registered trademarks of SNOMED International', $terms, $doc->id);
        }
        self::assertGreaterThan(0, $checked, 'at least one shipped document carries SNOMED CT codes (the Title X visit record)');
    }

    /** @param array<string, mixed> $changes */
    private static function with(Document $doc, array $changes): Document
    {
        return new Document(...array_merge(get_object_vars($doc), $changes));
    }

    /** @param list<string> $list @return list<string> */
    private static function sorted(array $list): array
    {
        sort($list);
        return $list;
    }
}
