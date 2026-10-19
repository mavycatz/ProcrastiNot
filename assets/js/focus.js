/* ProcrastiNot - "Just 5 Minutes" timer.
 * Counts down from the planned minutes, then keeps counting up so students can
 * continue. The timer only records elapsed time when the student finishes -
 * it never changes the task status. */
(function () {
  'use strict';

  function pad(n) { return (n < 10 ? '0' : '') + n; }

  function format(totalSeconds) {
    var s = Math.abs(totalSeconds);
    var m = Math.floor(s / 60);
    return pad(m) + ':' + pad(s % 60);
  }

  function init() {
    var root = document.querySelector('[data-focus-timer]');
    if (!root) return;

    var display = root.querySelector('[data-timer-display]');
    var status = root.querySelector('[data-timer-status]');
    var startBtn = root.querySelector('[data-timer-start]');
    var startLabel = root.querySelector('[data-timer-start-label]');
    var add5Btn = root.querySelector('[data-timer-add5]');
    var form = root.querySelector('[data-focus-session]');
    var plannedInput = root.querySelector('[data-timer-planned]');
    var secondsInput = root.querySelector('[data-timer-seconds]');

    var plannedSeconds = (parseInt(root.getAttribute('data-planned'), 10) || 5) * 60;
    var elapsed = 0;
    var running = false;
    var ticker = null;

    function render() {
      var remaining = plannedSeconds - elapsed;
      if (remaining >= 0) {
        display.textContent = format(remaining);
        root.classList.remove('is-overtime');
      } else {
        display.textContent = '+' + format(-remaining);
        root.classList.add('is-overtime');
      }
      if (plannedInput) plannedInput.value = String(Math.round(plannedSeconds / 60));
      if (secondsInput) secondsInput.value = String(elapsed);
    }

    function setRunning(next) {
      running = next;
      root.setAttribute('data-running', next ? 'true' : 'false');
      root.classList.toggle('is-running', next);
      if (startLabel) startLabel.textContent = next ? 'Pause' : (elapsed > 0 ? 'Continue' : 'Start');
      if (startBtn) {
        var icon = startBtn.querySelector('[data-lucide]');
        if (icon) icon.setAttribute('data-lucide', next ? 'pause' : 'play');
      }
      if (status) {
        if (next) {
          status.textContent = elapsed === 0
            ? 'Five minutes. That is all it takes to begin.'
            : 'Back to it - keep going.';
        } else if (elapsed > 0) {
          status.textContent = 'Paused. Continue whenever you are ready.';
        }
      }
      if (next && !ticker) {
        ticker = window.setInterval(function () {
          elapsed++;
          if (elapsed === plannedSeconds && status) {
            status.textContent = "Time's up! Keep going or finish whenever you like.";
          }
          render();
        }, 1000);
      } else if (!next && ticker) {
        window.clearInterval(ticker);
        ticker = null;
      }
      if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
      }
    }

    if (startBtn) {
      startBtn.addEventListener('click', function () { setRunning(!running); });
    }
    if (add5Btn) {
      add5Btn.addEventListener('click', function () {
        plannedSeconds += 300;
        render();
        if (status) status.textContent = 'Added five more minutes.';
        setRunning(true);
      });
    }
    if (form) {
      form.addEventListener('submit', function () {
        if (secondsInput) secondsInput.value = String(elapsed);
      });
    }

    render();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
