/* ProcrastiNot - calendar page: highlight the selected day cell. */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var selected = document.querySelector('.cal-cell .cal-day[aria-current="date"]');
    if (selected) {
      var cell = selected.closest('.cal-cell');
      if (cell) cell.classList.add('is-selected');
    }
  });
})();
