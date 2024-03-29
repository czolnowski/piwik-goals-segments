<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik_Plugins
 * @package CoreVisualizations
 */

namespace Piwik\Plugins\CoreVisualizations;

require_once PIWIK_INCLUDE_PATH . '/plugins/CoreVisualizations/JqplotDataGenerator.php';
require_once PIWIK_INCLUDE_PATH . '/plugins/CoreVisualizations/Visualizations/Cloud.php';
require_once PIWIK_INCLUDE_PATH . '/plugins/CoreVisualizations/Visualizations/HtmlTable.php';
require_once PIWIK_INCLUDE_PATH . '/plugins/CoreVisualizations/Visualizations/JqplotGraph.php';

/**
 * This plugin contains all core visualizations, such as the normal HTML table and
 * jqPlot graphs.
 */
class CoreVisualizations extends \Piwik\Plugin
{
    /**
     * @see Piwik_Plugin::getListHooksRegistered
     */
    public function getListHooksRegistered()
    {
        return array(
            'AssetManager.getCssFiles'            => 'getCssFiles',
            'AssetManager.getJsFiles'             => 'getJsFiles',
            'DataTableVisualization.getAvailable' => 'getAvailableDataTableVisualizations',
        );
    }

    public function getAvailableDataTableVisualizations(&$visualizations)
    {
        $visualizations[] = 'Piwik\\Plugins\\CoreVisualizations\\Visualizations\\HtmlTable';
        $visualizations[] = 'Piwik\\Plugins\\CoreVisualizations\\Visualizations\\HtmlTable\\AllColumns';
        $visualizations[] = 'Piwik\\Plugins\\CoreVisualizations\\Visualizations\\HtmlTable\\Goals';
        $visualizations[] = 'Piwik\\Plugins\\CoreVisualizations\\Visualizations\\Cloud';
        $visualizations[] = 'Piwik\\Plugins\\CoreVisualizations\\Visualizations\\JqplotGraph\\Pie';
        $visualizations[] = 'Piwik\\Plugins\\CoreVisualizations\\Visualizations\\JqplotGraph\\Bar';
        $visualizations[] = 'Piwik\\Plugins\\CoreVisualizations\\Visualizations\\JqplotGraph\\Evolution';
    }

    public function getCssFiles(&$cssFiles)
    {
        $cssFiles[] = "plugins/CoreVisualizations/stylesheets/dataTableVisualizations.less";
        $cssFiles[] = "plugins/CoreVisualizations/stylesheets/jqplot.css";
    }

    public function getJsFiles(&$jsFiles)
    {
        $jsFiles[] = "plugins/CoreVisualizations/javascripts/seriesPicker.js";
        $jsFiles[] = "plugins/CoreVisualizations/javascripts/jqplot.js";
        $jsFiles[] = "plugins/CoreVisualizations/javascripts/jqplotBarGraph.js";
        $jsFiles[] = "plugins/CoreVisualizations/javascripts/jqplotPieGraph.js";
        $jsFiles[] = "plugins/CoreVisualizations/javascripts/jqplotEvolutionGraph.js";
    }
}