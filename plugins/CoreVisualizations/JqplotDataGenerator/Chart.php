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
namespace Piwik\Plugins\CoreVisualizations\JqplotDataGenerator;

use Piwik\Piwik;
use Piwik\Common;

/**
 * Generates the data in the Open Flash Chart format, from the given data.
 */
class Chart
{
    // the data kept here conforms to the jqplot data layout
    // @see http://www.jqplot.com/docs/files/jqPlotOptions-txt.html
    protected $series = array();
    protected $data = array();
    protected $axes = array();

    // temporary
    public $dataTable;
    public $properties;

    public function setAxisXLabels($xLabels)
    {
        $xSteps = $this->properties['visualization_properties']->x_axis_step_size;
        $showAllTicks = $this->properties['visualization_properties']->show_all_ticks;

        $this->axes['xaxis']['labels'] = array_values($xLabels);

        $ticks = array_values($xLabels);
        if (!$showAllTicks) {
            // unset labels so there are $xSteps number of blank ticks between labels
            foreach ($ticks as $i => &$label) {
                if ($i % $xSteps != 0) {
                    $label = ' ';
                }
            }
        }
        $this->axes['xaxis']['ticks'] = $ticks;
    }

    public function setAxisXOnClick(&$onClick)
    {
        $this->axes['xaxis']['onclick'] = & $onClick;
    }

    public function setAxisYValues(&$values)
    {
        foreach ($values as $label => &$data) {
            $this->series[] = array(
                // unsanitize here is safe since data gets outputted as JSON, not HTML
                // NOTE: this is a quick fix for a double-encode issue. if this file is refactored,
                // this fix can probably be removed (or at least made more understandable).
                'label'         => Common::unsanitizeInputValue($label),
                'internalLabel' => $label
            );

            array_walk($data, create_function('&$v', '$v = (float)$v;'));
            $this->data[] = & $data;
        }
    }

    public function setAxisYUnits($yUnits)
    {
        $yUnits = array_values(array_map('strval', $yUnits));

        // generate axis IDs for each unique y unit
        $axesIds = array();
        foreach ($yUnits as $idx => $unit) {
            if (!isset($axesIds[$unit])) {
                // handle axes ids: first y[]axis, then y[2]axis, y[3]axis...
                $nextAxisId = empty($axesIds) ? '' : count($axesIds) + 1;

                $axesIds[$unit] = 'y' . $nextAxisId . 'axis';
            }
        }

        // generate jqplot axes config
        foreach ($axesIds as $unit => $axisId) {
            $this->axes[$axisId]['tickOptions']['formatString'] = '%s' . $unit;
        }

        // map each series to appropriate yaxis
        foreach ($yUnits as $idx => $unit) {
            $this->series[$idx]['yaxis'] = $axesIds[$unit];
        }
    }

    public function setAxisYLabels($labels)
    {
        foreach ($this->series as &$series) {
            $label = $series['internalLabel'];
            if (isset($labels[$label])) {
                $series['label'] = $labels[$label];
            }
        }
    }

    public function render()
    {
        Piwik::overrideCacheControlHeaders();

        // See http://www.jqplot.com/docs/files/jqPlotOptions-txt.html
        $data = array(
            'params'       => array(
                'axes'   => &$this->axes,
                'series' => &$this->series
            ),
            'data'         => &$this->data
        );

        return Common::json_encode($data);
    }
}