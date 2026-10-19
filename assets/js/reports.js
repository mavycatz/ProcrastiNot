/* ProcrastiNot - reports charts. */
(function () {
  'use strict';

  function init() {
    if (!window.TT) return;
    var p = window.TT.palette();
    window.TT.renderChartOrTable('pending-chart', {
      type: 'doughnut',
      label: 'Tasks by status',
      showLegend: true,
      centerLabel: 'tasks'
    }, p.status);
    window.TT.renderChartOrTable('category-chart', {
      type: 'doughnut',
      label: 'Tasks by subject',
      showLegend: true,
      centerLabel: 'tasks'
    });
    window.TT.renderChartOrTable('weekly-report-chart', {
      type: 'bar',
      label: 'Tasks completed',
      tooltipSuffix: 'completed'
    });
    window.TT.renderChartOrTable('focus-chart', {
      type: 'bar',
      label: 'Focus minutes',
      tooltipSuffix: 'min focused'
    });
    window.TT.renderChartOrTable('overdue-chart', {
      type: 'line',
      label: 'Tasks that slipped',
      tooltipSuffix: 'slipped'
    });
    window.TT.renderChartOrTable('xp-chart', {
      type: 'line',
      label: 'XP earned',
      tooltipSuffix: 'XP'
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
