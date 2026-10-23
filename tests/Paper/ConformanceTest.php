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
        self::assertNotSame([], $held);
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
        self::assertCount(11, Conformance::usExtensionCodes($doc));
        $unheld = self::with($doc, ['status' => 'active', 'id' => 'title-x-visit-record-copy']);
        self::assertContains('11 SNOMED CT US Extension codes, not held for release (data/release-gates.json)', Conformance::of($unheld, Requirements::shipped())['problems']);
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
