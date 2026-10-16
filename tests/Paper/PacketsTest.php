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

    public function testAnUnknownFieldIsRefused(): void
    {
        $this->expectExceptionMessage('packets[0]: needs exactly id, title and items');
        $this->read(['packets' => [['id' => 'p', 'title' => 'P', 'items' => ['layout:DEM'], 'colour' => 'red']]]);
    }

    /** @param array<string, mixed> $data */
    private function read(array $data): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cf-packets');
        self::assertIsString($file);
        try {
            file_put_contents($file, json_encode($data, JSON_THROW_ON_ERROR));
            Packets::read($file, DocumentLibrary::shipped());
        } finally {
            unlink($file);
        }
    }
}
