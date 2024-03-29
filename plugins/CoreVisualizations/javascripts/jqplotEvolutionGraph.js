/**
 * Piwik - Web Analytics
 *
 * DataTable UI class for JqplotGraph/Evolution.
 *
 * @link http://www.jqplot.com
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

(function ($, require) {

    var JqplotGraphDataTable = window.JqplotGraphDataTable,
        JqplotGraphDataTablePrototype = JqplotGraphDataTable.prototype;

    window.JqplotEvolutionGraphDataTable = function () {
        dataTable.call(this);
    };

    $.extend(window.JqplotEvolutionGraphDataTable.prototype, JqplotGraphDataTablePrototype, {

        _setJqplotParameters: function (params) {
            JqplotGraphDataTablePrototype._setJqplotParameters.call(this, params);
            
            var defaultParams = {
                axes: {
                    xaxis: {
                        pad: 1.0,
                        renderer: $.jqplot.CategoryAxisRenderer,
                        tickOptions: {
                            showGridline: false
                        }
                    }
                },
                piwikTicks: {
                    showTicks: true,
                    showGrid: true,
                    showHighlight: true,
                    tickColor: this.tickColor
                },
            };

            if (this.props.show_line_graph) {
                defaultParams.seriesDefaults = {
                    lineWidth: 1,
                    markerOptions: {
                        style: "filledCircle",
                        size: 6,
                        shadow: false
                    }
                };
            } else {
                defaultParams.seriesDefaults = {
                    renderer: $.jqplot.BarRenderer,
                    rendererOptions: {
                        shadowOffset: 1,
                        shadowDepth: 2,
                        shadowAlpha: .2,
                        fillToZero: true,
                        barMargin: this.data[0].length > 10 ? 2 : 10
                    }
                };
            }

            var overrideParams = {
                legend: {
                    show: false
                },
                canvasLegend: {
                    show: true
                }
            };
            this.jqplotParams = $.extend(true, {}, defaultParams, this.jqplotParams, overrideParams);
        },

        _bindEvents: function () {
            this.setYTicks();
            JqplotGraphDataTablePrototype._bindEvents.call(this);

            var self = this;
            var lastTick = false;

            $('#' + this.targetDivId)
                .on('jqplotMouseLeave', function (e, s, i, d) {
                    $(this).css('cursor', 'default');
                    JqplotGraphDataTablePrototype._destroyDataPointTooltip.call(this, $(this));
                })
                .on('jqplotClick', function (e, s, i, d) {
                    if (lastTick !== false && typeof self.jqplotParams.axes.xaxis.onclick != 'undefined'
                        && typeof self.jqplotParams.axes.xaxis.onclick[lastTick] == 'string') {
                        var url = self.jqplotParams.axes.xaxis.onclick[lastTick];
                        piwikHelper.redirectToUrl(url);
                    }
                })
                .on('jqplotPiwikTickOver', function (e, tick) {
                    lastTick = tick;
                    var label;
                    if (typeof self.jqplotParams.axes.xaxis.labels != 'undefined') {
                        label = self.jqplotParams.axes.xaxis.labels[tick];
                    } else {
                        label = self.jqplotParams.axes.xaxis.ticks[tick];
                    }
        
                    var text = [];
                    for (var d = 0; d < self.data.length; d++) {
                        var value = self.formatY(self.data[d][tick], d);
                        var series = self.jqplotParams.series[d].label;
                        text.push('<strong>' + value + '</strong> ' + series);
                    }
                    $(this).tooltip({
                        track:   true,
                        items:   'div',
                        content: '<h3>'+label+'</h3>'+text.join('<br />'),
                        show: false,
                        hide: false
                    }).trigger('mouseover');
                    if (typeof self.jqplotParams.axes.xaxis.onclick != 'undefined'
                        && typeof self.jqplotParams.axes.xaxis.onclick[lastTick] == 'string') {
                        $(this).css('cursor', 'pointer');
                    }
                });
        },

        _destroyDataPointTooltip: function () {
            // do nothing, tooltips are destroyed in the jqplotMouseLeave event
        }
    });

})(jQuery, require);
