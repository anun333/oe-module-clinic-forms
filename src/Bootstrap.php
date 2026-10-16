<?php

/**
 * Adds Clinic Forms to the Miscellaneous menu, right after Blank Forms.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms;

use OpenEMR\Menu\MenuEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Bootstrap
{
    public const MODULE_INSTALLATION_PATH = '/interface/modules/custom_modules/oe-module-clinic-forms';

    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function subscribeToEvents(): void
    {
        $this->eventDispatcher->addListener(MenuEvent::MENU_UPDATE, $this->addMenuItem(...));
    }

    public function addMenuItem(MenuEvent $event): MenuEvent
    {
        $item = new \stdClass();
        $item->requirement = 0;
        $item->target = 'cfm';
        $item->menu_id = 'cfm0';
        $item->label = xlt('Clinic Forms');
        $item->url = self::MODULE_INSTALLATION_PATH . '/public/index.php';
        $item->children = [];
        $item->acl_req = ['patients', 'demo'];
        $item->global_req = [];

        $menu = $event->getMenu();
        foreach ($menu as $entry) {
            if (($entry->menu_id ?? '') !== 'misimg' || !isset($entry->children) || !is_array($entry->children)) {
                continue;
            }
            $position = count($entry->children);
            foreach ($entry->children as $i => $child) {
                if (($child->label ?? '') === xl('Blank Forms')) {
                    $position = $i + 1;
                    break;
                }
            }
            array_splice($entry->children, $position, 0, [$item]);
            $event->setMenu($menu);
            return $event;
        }
        // No Miscellaneous menu (a custom menu): add it at the top level instead.
        $menu[] = $item;
        $event->setMenu($menu);
        return $event;
    }
}
