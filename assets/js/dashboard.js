/* ProcrastiNot - dashboard charts and the compact stats toggle. */
(function () {
  'use strict';

  function initStatToggle() {
    var toggle = document.querySelector('[data-stat-toggle]');
    var grid = document.getElementById('stat-grid');
    if (!toggle || !grid) return;
    var label = toggle.querySelector('[data-stat-toggle-label]');

    toggle.addEventListener('click', function () {
      var expanded = grid.classList.toggle('is-expanded');
      toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      if (label) label.textContent = expanded ? 'Show less' : 'Show all';
    });
  }

  function init() {
    initStatToggle();
    if (!window.TT) return;
    var p = window.TT.palette();

    window.TT.renderChartOrTable('week-chart', {
      type: 'bar',
      label: 'Completed this week',
      tooltipSuffix: 'completed'
    });

    window.TT.renderChartOrTable('status-chart', {
      type: 'doughnut',
      label: 'Task status',
      showLegend: true,
      centerLabel: 'tasks'
    }, p.status);

    window.TT.renderChartOrTable('trend-chart', {
      type: 'line',
      label: 'Completed per week',
      tooltipSuffix: 'completed',
      showLegend: false
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
