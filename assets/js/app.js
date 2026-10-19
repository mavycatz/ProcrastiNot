/* ProcrastiNot - shared behaviour: toasts, account menu, icons, chart fallback. */
(function () {
  'use strict';

  // ----- Lucide icons (optional CDN) -----
  function initIcons() {
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
      window.lucide.createIcons();
    }
  }

  // ----- Toasts from session flash messages -----
  function showToast(type, message) {
    var region = document.getElementById('toast-region');
    if (!region) return;
    var toast = document.createElement('div');
    toast.className = 'toast' + (type === 'error' ? ' toast-error' : '');

    var text = document.createElement('span');
    text.className = 'toast-message';
    text.textContent = message;
    toast.appendChild(text);

    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'toast-close';
    close.setAttribute('aria-label', 'Dismiss notification');
    close.textContent = '×';
    close.addEventListener('click', function () { toast.remove(); });
    toast.appendChild(close);

    region.appendChild(toast);
    window.setTimeout(function () {
      if (toast.parentNode) toast.remove();
    }, 4500);
  }

  function initToasts() {
    var dataEl = document.getElementById('flash-data');
    if (!dataEl) return;
    var flashes;
    try {
      flashes = JSON.parse(dataEl.textContent);
    } catch (err) {
      return;
    }
    (flashes || []).forEach(function (flash) {
      showToast(flash.type || 'success', flash.message || '');
    });
  }

  // ----- Account / "More" menu -----
  function initAccountMenu() {
    var menu = document.getElementById('account-menu');
    var toggles = document.querySelectorAll('[data-account-toggle]');
    if (!menu || !toggles.length) return;
    var lastToggle = null;

    function isOpen() { return !menu.hasAttribute('hidden'); }

    function setExpanded(open) {
      Array.prototype.forEach.call(toggles, function (t) {
        t.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
    }

    function open(toggle) {
      lastToggle = toggle || lastToggle;
      menu.removeAttribute('hidden');
      setExpanded(true);
      var first = menu.querySelector('.account-menu-link');
      if (first) first.focus();
    }

    function close(returnFocus) {
      menu.setAttribute('hidden', '');
      setExpanded(false);
      if (returnFocus && lastToggle) lastToggle.focus();
    }

    Array.prototype.forEach.call(toggles, function (toggle) {
      toggle.addEventListener('click', function (event) {
        event.stopPropagation();
        if (isOpen()) {
          close(true);
        } else {
          open(toggle);
        }
      });
    });

    // Keep clicks inside the menu from bubbling to the document handler.
    menu.addEventListener('click', function (event) { event.stopPropagation(); });

    document.addEventListener('click', function () { if (isOpen()) close(false); });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && isOpen()) close(true);
    });
  }

  // ----- Light / dark theme -----
  var THEME_KEY = 'tt-theme';

  function storedTheme() {
    try { return localStorage.getItem(THEME_KEY); } catch (err) { return null; }
  }

  function applyTheme(theme) {
    var root = document.documentElement;
    if (theme === 'dark' || theme === 'light') {
      root.setAttribute('data-theme', theme);
    } else {
      root.removeAttribute('data-theme');
    }
    var isDark = resolveTheme() === 'dark';
    document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
      btn.setAttribute('aria-label', isDark ? 'Switch to light theme' : 'Switch to dark theme');
      var label = btn.querySelector('[data-theme-label]');
      if (label) label.textContent = isDark ? 'Light theme' : 'Dark theme';
      var iconName = isDark ? 'sun' : 'moon';
      var icon = btn.querySelector('[data-lucide]');
      if (icon) {
        icon.setAttribute('data-lucide', iconName);
      } else {
        var svg = btn.querySelector('svg');
        if (svg) {
          var span = document.createElement('span');
          span.setAttribute('data-lucide', iconName);
          span.setAttribute('aria-hidden', 'true');
          svg.parentNode.replaceChild(span, svg);
        }
      }
    });
    initIcons();
    redrawCharts();
  }

  function resolveTheme() {
    var explicit = document.documentElement.getAttribute('data-theme');
    if (explicit === 'dark' || explicit === 'light') return explicit;
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
      ? 'dark' : 'light';
  }

  function initTheme() {
    applyTheme(storedTheme());
    document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var next = resolveTheme() === 'dark' ? 'light' : 'dark';
        try { localStorage.setItem(THEME_KEY, next); } catch (err) {}
        applyTheme(next);
      });
    });
    if (window.matchMedia) {
      window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
        if (!storedTheme()) applyTheme();
      });
    }
  }

  // Track rendered charts so they can be restyled on theme change.
  var chartRegistry = [];

  function palette() {
    var dark = resolveTheme() === 'dark';
    return {
      dark: dark,
      text: dark ? '#E7E2FA' : '#28243A',
      muted: dark ? '#9A93BC' : '#6B6485',
      grid: dark ? 'rgba(160,145,255,0.16)' : 'rgba(118,85,232,0.10)',
      primary: dark ? '#9D85FF' : '#7655E8',
      lavender: dark ? '#B9A8FF' : '#A98CF0',
      blue: dark ? '#8FC6FF' : '#6FB4F0',
      mint: dark ? '#7DE0B4' : '#56C99A',
      status: dark
        ? ['#B9A8FF', '#8FC6FF', '#7DE0B4']
        : ['#A98CF0', '#6FB4F0', '#56C99A'],
      series: dark
        ? ['#9D85FF', '#8FC6FF', '#7DE0B4', '#F0C066', '#F590AD', '#B9A8FF', '#A9A2CE', '#8A6DF5']
        : ['#7655E8', '#6FB4F0', '#56C99A', '#F0B84A', '#F07EA0', '#A98CF0', '#8C86B8', '#5F3FD6']
    };
  }

  function applyThemeToChart(entry) {
    var p = palette();
    var chart = entry.chart;
    var isRound = chart.config.type === 'doughnut' || chart.config.type === 'pie';
    if (entry.colors) {
      chart.data.datasets[0].backgroundColor = entry.colors;
      if (chart.config.type === 'line' && Array.isArray(entry.colors)) {
        chart.data.datasets[0].borderColor = entry.colors[0];
      }
    } else if (isRound) {
      chart.data.datasets[0].backgroundColor = p.status;
    } else if (chart.config.type === 'line') {
      chart.data.datasets[0].borderColor = p.primary;
      chart.data.datasets[0].backgroundColor = p.dark ? 'rgba(157,133,255,0.18)' : 'rgba(118,85,232,0.14)';
      chart.data.datasets[0].pointBackgroundColor = p.primary;
    } else {
      chart.data.datasets[0].backgroundColor = p.primary;
    }
    if (chart.options.plugins && chart.options.plugins.legend) {
      chart.options.plugins.legend.labels = chart.options.plugins.legend.labels || {};
      chart.options.plugins.legend.labels.color = p.muted;
    }
    if (chart.options.plugins && chart.options.plugins.centerTotal) {
      chart.options.plugins.centerTotal.color = p.text;
      chart.options.plugins.centerTotal.labelColor = p.muted;
    }
    if (chart.options.scales) {
      Object.keys(chart.options.scales).forEach(function (key) {
        var scale = chart.options.scales[key];
        scale.ticks = scale.ticks || {};
        scale.ticks.color = p.muted;
        scale.grid = scale.grid || {};
        scale.grid.color = key === 'x' ? 'transparent' : p.grid;
      });
    }
    chart.update();
  }

  function redrawCharts() {
    if (!window.Chart) return;
    chartRegistry.forEach(applyThemeToChart);
  }

  // Draws the total in the middle of a doughnut chart.
  var centerTotalPlugin = {
    id: 'centerTotal',
    afterDraw: function (chart, args, opts) {
      if (!opts || !opts.display) return;
      var meta = chart.getDatasetMeta(0);
      if (!meta || !meta.data || !meta.data.length) return;
      var x = meta.data[0].x;
      var y = meta.data[0].y;
      var ctx = chart.ctx;
      ctx.save();
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillStyle = opts.color || '#28243A';
      ctx.font = '800 26px "Plus Jakarta Sans", "Segoe UI", sans-serif';
      ctx.fillText(String(opts.value == null ? '' : opts.value), x, y - 8);
      ctx.fillStyle = opts.labelColor || '#6B6485';
      ctx.font = '600 12px "Plus Jakarta Sans", "Segoe UI", sans-serif';
      ctx.fillText(opts.label || '', x, y + 16);
      ctx.restore();
    }
  };

  // ----- Submit loading states -----
  function initLoadingStates() {
    document.addEventListener('submit', function (event) {
      var form = event.target;
      if (!(form instanceof HTMLFormElement)) return;
      // Let the browser run native validation first.
      if (typeof form.checkValidity === 'function' && !form.checkValidity()) return;
      var button = form.querySelector('button[type="submit"]:not([data-no-loading])');
      if (!button) return;
      button.classList.add('is-loading');
      button.setAttribute('aria-disabled', 'true');
      window.setTimeout(function () {
        button.classList.remove('is-loading');
        button.removeAttribute('aria-disabled');
      }, 8000);
    });
  }

  // ----- Chart.js with accessible table fallback -----
  // config: { type, label, showLegend, centerLabel, tooltipSuffix, indexAxis }
  function renderChartOrTable(canvasId, config, colors) {
    var canvas = document.getElementById(canvasId);
    if (!canvas || !canvas.dataset.chart) return;
    var data;
    try {
      data = JSON.parse(canvas.dataset.chart);
    } catch (err) {
      return;
    }
    if (!data || !data.labels || !data.values) return;

    if (window.Chart) {
      var p = palette();
      var isRound = config.type === 'doughnut' || config.type === 'pie';
      if (centerTotalPlugin && window.Chart.registry && !window.Chart.registry.plugins.get('centerTotal')) {
        window.Chart.register(centerTotalPlugin);
      }
      var total = data.values.reduce(function (sum, n) { return sum + Number(n || 0); }, 0);
      var isLine = config.type === 'line';
      var dataset = {
        label: config.label,
        data: data.values,
        backgroundColor: colors || (isRound ? p.status : p.primary),
        borderWidth: 0,
        borderRadius: isRound || isLine ? 0 : 10,
        maxBarThickness: 46,
        hoverOffset: isRound ? 6 : 0
      };
      if (isLine) {
        dataset.borderColor = (colors && colors[0]) || p.primary;
        dataset.backgroundColor = (colors && colors[1]) || (p.dark ? 'rgba(157,133,255,0.18)' : 'rgba(118,85,232,0.14)');
        dataset.tension = 0.35;
        dataset.fill = true;
        dataset.pointRadius = 4;
        dataset.pointHoverRadius = 6;
        dataset.pointBackgroundColor = dataset.borderColor;
        dataset.pointBorderColor = '#fff';
        dataset.pointBorderWidth = 2;
        dataset.borderWidth = 3;
      }
      var options = {
        responsive: true,
        maintainAspectRatio: true,
        resizeDelay: 0,
        layout: { padding: isRound ? 6 : 0 },
        plugins: {
          legend: {
            display: config.showLegend === true,
            position: 'bottom',
            labels: {
              color: p.muted,
              usePointStyle: true,
              pointStyle: 'circle',
              boxWidth: 8,
              boxHeight: 8,
              padding: 14,
              font: { family: 'Plus Jakarta Sans', size: 12, weight: '600' }
            }
          },
          tooltip: {
            backgroundColor: p.dark ? '#2A2347' : '#2A2440',
            padding: 10,
            cornerRadius: 10,
            displayColors: !isRound,
            callbacks: {
              label: function (context) {
                var suffix = config.tooltipSuffix || '';
                var value = context.parsed;
                if (isRound) value = context.parsed;
                return ' ' + context.label + ': ' + value + (suffix ? ' ' + suffix : '');
              }
            }
          },
          centerTotal: {
            display: isRound && config.centerLabel !== undefined,
            value: total,
            label: config.centerLabel || '',
            color: p.text,
            labelColor: p.muted
          }
        }
      };
      if (!isRound) {
        options.scales = {
          x: {
            ticks: { color: p.muted, font: { family: 'Plus Jakarta Sans', size: 12, weight: '600' } },
            grid: { display: false }
          },
          y: {
            beginAtZero: true,
            ticks: { precision: 0, color: p.muted, font: { family: 'Plus Jakarta Sans', size: 12 } },
            grid: { color: p.grid }
          }
        };
      }
      var chart = new window.Chart(canvas.getContext('2d'), {
        type: config.type,
        data: { labels: data.labels, datasets: [dataset] },
        options: options
      });
      chartRegistry.push({ chart: chart, colors: colors || null });
      return;
    }

    // Fallback: replace the canvas with an accessible data table.
    var table = document.createElement('table');
    table.className = 'chart-table';
    var caption = document.createElement('caption');
    caption.className = 'visually-hidden';
    caption.textContent = config.label;
    table.appendChild(caption);
    var thead = document.createElement('thead');
    thead.innerHTML = '<tr><th scope="col">Item</th><th scope="col">Value</th></tr>';
    table.appendChild(thead);
    var tbody = document.createElement('tbody');
    data.labels.forEach(function (label, index) {
      var row = document.createElement('tr');
      var th = document.createElement('th');
      th.scope = 'row';
      th.textContent = label;
      var td = document.createElement('td');
      td.textContent = data.values[index];
      row.appendChild(th);
      row.appendChild(td);
      tbody.appendChild(row);
    });
    table.appendChild(tbody);
    canvas.parentNode.replaceChild(table, canvas);
  }

  // ----- Inline disclosures (e.g. Comeback Mode "Adjust deadline") -----
  function initDisclosures() {
    var toggles = document.querySelectorAll('[data-comeback-toggle]');
    Array.prototype.forEach.call(toggles, function (btn) {
      var targetId = btn.getAttribute('aria-controls');
      var target = targetId ? document.getElementById(targetId) : null;
      if (!target) return;
      btn.addEventListener('click', function () {
        var open = target.hasAttribute('hidden');
        if (open) {
          target.removeAttribute('hidden');
        } else {
          target.setAttribute('hidden', '');
        }
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
    });
  }

  window.TT = { showToast: showToast, renderChartOrTable: renderChartOrTable, palette: palette };

  document.addEventListener('DOMContentLoaded', function () {
    initTheme();
    initIcons();
    initToasts();
    initAccountMenu();
    initLoadingStates();
    initDisclosures();
  });
})();
