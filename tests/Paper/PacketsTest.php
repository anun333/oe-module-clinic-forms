<?php

/**
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests\Paper;

use OpenHealthcare\ClinicForms\Paper\DocumentLibrary;
use OpenHealthcare\ClinicForms\Paper\Packets;
use PHPUnit\Framework\TestCase;

final class PacketsTest extends TestCase
{
    public function testTheShippedPacketsAreValid(): void
    {
        $packets = Packets::shipped(DocumentLibrary::shipped())->packets;
        self::assertGreaterThanOrEqual(5, count($packets));
        self::assertSame('new-patient', $packets[0]['id']);
        self::assertSame('layout:DEM', $packets[0]['items'][0]);
    }

    public function testItems(): void
    {
        self::assertTrue(Packets::isItem('doc:consent-to-treat'));
        self::assertTrue(Packets::isItem('layout:DEM'));
        self::assertTrue(Packets::isItem('layout:LBFintake_2'));
        self::assertTrue(Packets::isItem('layout:HIS'));
        self::assertTrue(Packets::isItem('layout:LBTref'));
        self::assertTrue(Packets::isItem('layout:UDS'));
        self::assertFalse(Packets::isItem('layout:FACUSR'));
        self::assertFalse(Packets::isItem('layout:'));
        self::assertFalse(Packets::isItem('layout:LBF x'));
        self::assertFalse(Packets::isItem('doc:Consent'));
        self::assertFalse(Packets::isItem('doc:../etc/passwd'));
        self::assertFalse(Packets::isItem('file:consent'));
    }

    public function testAPacketNamingAMissingDocumentIsRefused(): void
    {
        $this->expectExceptionMessage('packets[0] items[1]: no document no-such-thing');
        $this->read(['packets' => [['id' => 'p', 'title' => 'P', 'items' => ['layout:DEM', 'doc:no-such-thing']]]]);
    }

    public function testADuplicateIdIsRefused(): void
    {
        $this->expectExceptionMessage('packets[1] id: must be a new id');
        $p = ['id' => 'p', 'title' => 'P', 'items' => ['layout:DEM']];
        $this->read(['packets' => [$p, $p]]);
    }

    public function testAPacketMaySayWhatItIsFor(): void
    {
        $packets = $this->read(['packets' => [
            ['id' => 'p', 'title' => 'P', 'about' => 'Every adult, once a year.', 'items' => ['layout:DEM']],
            ['id' => 'q', 'title' => 'Q', 'items' => ['layout:DEM']],
        ]])->packets;
        self::assertSame(['Every adult, once a year.', ''], array_column($packets, 'about'));
    }

    public function testShippedTitlesAndAboutsHaveNoQuotes(): void
    {
        // The page passes them through xl(), which prints ' and " as backticks.
        $seen = 0;
        foreach ([Packets::shipped(DocumentLibrary::shipped(), null), Packets::shipped(DocumentLibrary::shipped())] as $set) {
            foreach ($set->packets as $packet) {
                foreach ([$packet['title'], $packet['about']] as $text) {
                    $seen++;
                    self::assertDoesNotMatchRegularExpression('/[\'"]/', $text, $packet['id']);
                }
            }
        }
        self::assertGreaterThan(20, $seen);
    }

    public function testAnEmptyAboutIsRefused(): void
    {
        $this->expectExceptionMessage('packets[0] about: one line saying what the packet is for');
        $this->read(['packets' => [['id' => 'p', 'title' => 'P', 'about' => ' ', 'items' => ['layout:DEM']]]]);
    }

    public function testEveryScreeningAndFollowUpPacketSaysWhatItIsFor(): void
    {
        foreach ([Packets::shipped(DocumentLibrary::shipped(), null), Packets::shipped(DocumentLibrary::shipped())] as $set) {
            foreach ($set->packets as $packet) {
                if (preg_match('/screen|follow-up|who-we-serve|add-on/', $packet['id']) === 1) {
                    self::assertNotSame('', $packet['about'], $packet['id']);
                }
            }
        }
    }

    public function testAnUnknownFieldIsRefused(): void
    {
        $this->expectExceptionMessage('packets[0]: needs id, title and items ("about" is the only other field)');
        $this->read(['packets' => [['id' => 'p', 'title' => 'P', 'items' => ['layout:DEM'], 'colour' => 'red']]]);
    }

    public function testAClinicPacketIsAddedAndOneWithAShippedIdReplacesIt(): void
    {
        $site = $this->site(['packets' => [
            ['id' => 'telehealth', 'title' => 'Our telehealth', 'items' => ['doc:telehealth-consent']],
            ['id' => 'sports-physical', 'title' => 'Sports physical', 'items' => ['layout:DEM', 'doc:consent-to-treat']],
        ]]);
        $packets = Packets::forSite(DocumentLibrary::shipped(), $site);
        $byId = array_column($packets->packets, null, 'id');
        self::assertSame('Our telehealth', $byId['telehealth']['title']);
        self::assertSame('clinic', $byId['telehealth']['source'] ?? null);
        self::assertSame('clinic', $byId['sports-physical']['source'] ?? null);
        self::assertArrayNotHasKey('source', $byId['new-patient']);
        self::assertSame([], $packets->problems);
    }

    public function testABrokenClinicFileIsReportedAndTheShippedPacketsStay(): void
    {
        $site = $this->site(['packets' => [['id' => 'x', 'title' => 'X', 'items' => ['doc:not-a-document']]]]);
        $packets = Packets::forSite(DocumentLibrary::shipped(), $site);
        self::assertSame(['sites/' . basename($site) . '/documents/clinic-forms/packets.json packets[0] items[0]: no document not-a-document'], $packets->problems);
        self::assertSame(array_column(Packets::shipped(DocumentLibrary::shipped())->packets, 'id'), array_column($packets->packets, 'id'));
    }

    public function testNoClinicFileIsNotAProblem(): void
    {
        $packets = Packets::forSite(DocumentLibrary::shipped(), sys_get_temp_dir() . '/cf-no-such-site');
        self::assertSame([], $packets->problems);
        self::assertNotSame([], $packets->packets);
    }

    public function testAnUnknownTopLevelFieldIsRefused(): void
    {
        $this->expectExceptionMessage('needs "packets", a list');
        $this->read(['packets' => [], 'colour' => 'red']);
    }

    /** @param array<string, mixed> $data the clinic's packets.json */
    private function site(array $data): string
    {
        $site = sys_get_temp_dir() . '/cf-site-' . bin2hex(random_bytes(4));
        mkdir($site . '/documents/clinic-forms', 0777, true);
        file_put_contents($site . '/documents/clinic-forms/packets.json', json_encode($data, JSON_THROW_ON_ERROR));
        register_shutdown_function(static function () use ($site): void {
            @unlink($site . '/documents/clinic-forms/packets.json');
            @rmdir($site . '/documents/clinic-forms');
            @rmdir($site . '/documents');
            @rmdir($site);
        });
        return $site;
    }

    /** @param array<string, mixed> $data */
    private function read(array $data): Packets
    {
        $file = tempnam(sys_get_temp_dir(), 'cf-packets');
        self::assertIsString($file);
        try {
            file_put_contents($file, json_encode($data, JSON_THROW_ON_ERROR));
            return Packets::read($file, DocumentLibrary::shipped());
        } finally {
            unlink($file);
        }
    }
}
