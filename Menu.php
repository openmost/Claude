<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Claude;

use Piwik\Menu\MenuAdmin;
use Piwik\Menu\MenuTop;
use Piwik\Piwik;
use Piwik\Plugins\Claude\Settings\SystemSettingsForm;
use Piwik\Plugins\UsersManager\UserPreferences;
use Piwik\Request;

/**
 * This class allows you to add, remove or rename menu items.
 * To configure a menu (such as Admin Menu, Top Menu, User Menu...) simply call the corresponding methods as
 * described in the API-Reference http://developer.piwik.org/api-reference/Piwik/Menu/MenuAbstract
 */
class Menu extends \Piwik\Plugin\Menu
{

    public function configureTopMenu(MenuTop $menu)
    {
        $menu->addItem('Claude', null, $this->urlForDefaultAction(), $orderId = 30);
    }

    public function configureAdminMenu(MenuAdmin $menu)
    {
        if (Piwik::hasUserSuperUserAccess()) {
            // next to AI Providers
            $menu->addSystemItem('Claude_SystemSettingsMenu', $this->urlForAction(SystemSettingsForm::ACTION), 39);
        }

        $defaultIdSite = (int) (new UserPreferences())->getDefaultWebsiteId();
        $idSite = Request::fromRequest()->getIntegerParameter('idSite', $defaultIdSite);

        if ($idSite > 0 && Piwik::isUserHasAdminAccess($idSite)) {
            $menu->addMeasurableItem('Claude_SiteSettingsMenu', $this->urlForAction('manage', ['idSite' => $idSite]), 45);
        }
    }
}
