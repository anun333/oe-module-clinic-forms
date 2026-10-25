<?php

/**
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests\Office;

use OpenHealthcare\ClinicForms\Office\BlankPdfs;
use OpenHealthcare\ClinicForms\Paper\Document;
use PHPUnit\Framework\TestCase;

/**
 * The blank documents' accessible PDFs made at release (data/pdf): there is one for every shipped
 * document, made from its current wording, and one is given only while it still matches.
 */
final class BlankPdfsTest extends TestCase
{
    private string $site;

    protected function setUp(): void
    {
        $this->site = sys_get_temp_dir() . '/cf-blank-' . bin2hex(random_bytes(4));
        mkdir($this->site);
    }

    protected function tearDown(): void
    {
        rmdir($this->site);
    }

    public function testEveryShippedDocumentHasACurrentPdf(): void
    {
        $documents = BlankPdfs::shipped($this->site);
        self::assertGreaterThanOrEqual(90, count($documents));
        self::assertArrayHasKey('en/consent-to-treat', $documents);
        self::assertArrayHasKey('es/consent-to-treat', $documents);
        self::assertSame([], (new BlankPdfs())->stale($documents), 'run php tools/blank-pdfs.php (it needs LibreOffice)');
    }

    public function testAPdfIsGivenOnlyWhileItsDocumentAndTaglinesMatch(): void
    {
        $documents = BlankPdfs::shipped($this->site);
        [$doc, $taglines] = $documents['en/consent-to-treat'];
        $pdfs = new BlankPdfs();
        $files = $pdfs->files([$doc, $documents['en/phq-9'][0]], $taglines);
        self::assertIsArray($files);
        self::assertSame(['consent-to-treat.pdf', 'phq-9.pdf'], array_keys($files));
        self::assertStringStartsWith('%PDF-', $files['consent-to-treat.pdf']);
        [$es, $esTaglines] = $documents['es/consent-to-treat'];
        self::assertSame(['consent-to-treat.es.pdf'], array_keys($pdfs->files([$es], $esTaglines) ?? []));
        self::assertNull($pdfs->files([$es], $taglines), 'a Spanish page puts its taglines in another order');

        self::assertNull($pdfs->files([$doc], ['Our own tagline.']), 'the clinic\'s own taglines');
        $edited = $doc->withBlocks([['type' => 'paragraph', 'text' => 'Our own wording.'], ...$doc->blocks]);
        self::assertNull($pdfs->files([$edited], $taglines), 'other wording');
        $own = new Document($doc->id, $doc->title, $doc->category, $doc->about, $doc->blocks, $doc->uses, 'clinic', $doc->audience, version: $doc->version);
        self::assertNull($pdfs->files([$own], $taglines), 'the clinic\'s own version, even with the same wording');
        self::assertNull($pdfs->files([$doc, $edited], $taglines), 'a packet is given whole or not at all');
    }
}
