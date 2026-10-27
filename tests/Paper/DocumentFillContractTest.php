<?php

/**
 * The fill event as openhealthcare-common's Contract\Forms\DocumentFill (1.5.0) describes it to other modules: its
 * name, the four properties a listener reads and fillTable(). A change to the event that the contract doesn't match
 * fails here, before a listener in another module (Sliding Fee's notice) stops filling.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Tests\Paper;

use OpenHealthcare\ClinicForms\Event\DocumentFillEvent;
use OpenHealthcare\Common\Contract\Forms\DocumentFill as FillContract;
use PHPUnit\Framework\TestCase;

final class DocumentFillContractTest extends TestCase
{
    public function testTheEventIsTheOneTheContractNames(): void
    {
        $this->assertSame(FillContract::EVENT_NAME, DocumentFillEvent::EVENT_NAME);
    }

    public function testAListenerUsingTheContractFillsTheTable(): void
    {
        $event = new DocumentFillEvent('sliding-fee-notice', 'es', ['discounts', 'income'], 12);
        $this->assertTrue(FillContract::asks($event, 'sliding-fee-notice', 'discounts'));
        $this->assertFalse(FillContract::asks($event, 'sliding-fee-notice', 'fees'));
        $this->assertFalse(FillContract::asks($event, 'privacy-notice', 'discounts'));
        $this->assertSame(['es', 12], [FillContract::language($event), FillContract::pid($event)]);
        $this->assertNull(FillContract::pid(new DocumentFillEvent('sliding-fee-notice', 'en', ['discounts'])), 'a blank print');

        FillContract::fill($event, 'discounts', [['100%', 20]], 'From the schedule in force');
        $this->assertSame(['discounts' => ['rows' => [['100%', '20']], 'note' => 'From the schedule in force']], $event->filled());

        $this->expectException(\InvalidArgumentException::class);
        FillContract::fill($event, 'fees', []);
    }
}
