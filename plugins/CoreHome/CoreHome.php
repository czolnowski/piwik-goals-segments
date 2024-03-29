<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik_Plugins
 * @package CoreHome
 */
namespace Piwik\Plugins\CoreHome;

use Piwik\WidgetsList;

/**
 *
 * @package CoreHome
 */
class CoreHome extends \Piwik\Plugin
{
    /**
     * @see Piwik_Plugin::getListHooksRegistered
     */
    public function getListHooksRegistered()
    {
        return array(
            'AssetManager.getCssFiles' => 'getCssFiles',
            'AssetManager.getJsFiles'  => 'getJsFiles',
            'WidgetsList.add'          => 'addWidgets',
        );
    }

    /**
     * Adds the donate form widget.
     */
    public function addWidgets()
    {
        WidgetsList::add('Example Widgets', 'CoreHome_SupportPiwik', 'CoreHome', 'getDonateForm');
        WidgetsList::add('Example Widgets', 'Installation_Welcome', 'CoreHome', 'getPromoVideo');
    }

    public function getCssFiles(&$cssFiles)
    {
        $cssFiles[] = "libs/jquery/themes/base/jquery-ui.css";
        $cssFiles[] = "libs/jquery/stylesheets/jquery.jscrollpane.css";
        $cssFiles[] = "libs/jquery/stylesheets/scroll.less";
        $cssFiles[] = "plugins/Zeitgeist/stylesheets/base.less";
        $cssFiles[] = "plugins/CoreHome/stylesheets/coreHome.less";
        $cssFiles[] = "plugins/CoreHome/stylesheets/menu.less";
        $cssFiles[] = "plugins/CoreHome/stylesheets/dataTable.less";
        $cssFiles[] = "plugins/CoreHome/stylesheets/cloud.less";
        $cssFiles[] = "plugins/CoreHome/stylesheets/jquery.ui.autocomplete.css";
        $cssFiles[] = "plugins/CoreHome/stylesheets/jqplotColors.less";
        $cssFiles[] = "plugins/CoreHome/stylesheets/sparklineColors.less";
        $cssFiles[] = "plugins/CoreHome/stylesheets/promo.less";
        $cssFiles[] = "plugins/CoreHome/stylesheets/color_manager.css";
        $cssFiles[] = "plugins/CoreHome/stylesheets/sparklineColors.less";
    }

    public function getJsFiles(&$jsFiles)
    {
        $jsFiles[] = "libs/jquery/jquery.js";
        $jsFiles[] = "libs/jquery/jquery-ui.js";
        $jsFiles[] = "libs/jquery/jquery.browser.js";
        $jsFiles[] = "libs/jquery/jquery.truncate.js";
        $jsFiles[] = "libs/jquery/jquery.scrollTo.js";
        $jsFiles[] = "libs/jquery/jquery.history.js";
        $jsFiles[] = "libs/jquery/jquery.jscrollpane.js";
        $jsFiles[] = "libs/jquery/jquery.mousewheel.js";
        $jsFiles[] = "libs/jquery/mwheelIntent.js";
        $jsFiles[] = "libs/javascript/sprintf.js";
        $jsFiles[] = "plugins/Zeitgeist/javascripts/piwikHelper.js";
        $jsFiles[] = "plugins/Zeitgeist/javascripts/ajaxHelper.js";
        $jsFiles[] = "plugins/CoreHome/javascripts/require.js";
        $jsFiles[] = "plugins/CoreHome/javascripts/dataTable.js";
        $jsFiles[] = "plugins/CoreHome/javascripts/dataTable_rowactions.js";
        $jsFiles[] = "plugins/CoreHome/javascripts/popover.js";
        $jsFiles[] = "plugins/CoreHome/javascripts/broadcast.js";
        $jsFiles[] = "plugins/CoreHome/javascripts/menu.js";
        $jsFiles[] = "plugins/CoreHome/javascripts/menu_init.js";
        $jsFiles[] = "plugins/CoreHome/javascripts/calendar.js";
        $jsFiles[] = "plugins/CoreHome/javascripts/autocomplete.js";
        $jsFiles[] = "plugins/CoreHome/javascripts/sparkline.js";
        $jsFiles[] = "plugins/CoreHome/javascripts/corehome.js";
        $jsFiles[] = "plugins/CoreHome/javascripts/dataTable_manager.js";
        $jsFiles[] = "plugins/CoreHome/javascripts/donate.js";
        $jsFiles[] = "libs/jqplot/jqplot-custom.min.js";
        $jsFiles[] = "plugins/CoreHome/javascripts/promo.js";
        $jsFiles[] = "plugins/CoreHome/javascripts/color_manager.js";
    }
}