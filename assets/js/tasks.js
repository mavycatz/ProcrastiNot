/* ProcrastiNot - task page behaviour: new-task toggle + delete confirmation. */
(function () {
  'use strict';

  function initCollapse() {
    var toggle = document.querySelector('.collapse-toggle');
    var body = document.getElementById('new-task-body');
    if (!toggle || !body) return;
    toggle.addEventListener('click', function () {
      var open = toggle.getAttribute('aria-expanded') === 'true';
      toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
      body.hidden = open;
      if (!open) {
        var first = body.querySelector('input, select, textarea');
        if (first) first.focus();
      }
    });
  }

  function initDeleteDialog() {
    var dialog = document.getElementById('delete-dialog');
    if (!dialog || typeof dialog.showModal !== 'function') {
      // No <dialog> support: fall back to native confirm prompts.
      document.querySelectorAll('form[data-confirm-delete]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
          if (!window.confirm(form.dataset.confirmDelete)) {
            event.preventDefault();
          }
        });
      });
      return;
    }

    var idInput = document.getElementById('delete-dialog-id');
    var message = document.getElementById('delete-dialog-message');
    var cancel = dialog.querySelector('[data-delete-cancel]');
    var lastTrigger = null;

    document.querySelectorAll('form[data-confirm-delete]').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        lastTrigger = form.querySelector('button[type="submit"]');
        var id = form.querySelector('input[name="id"]');
        idInput.value = id ? id.value : '';
        message.innerHTML = form.dataset.confirmDelete;
        dialog.showModal();
      });
    });

    if (cancel) {
      cancel.addEventListener('click', function () { dialog.close(); });
    }
    dialog.addEventListener('close', function () {
      if (lastTrigger && document.contains(lastTrigger)) lastTrigger.focus();
    });
  }

  function openNewTask() {
    var toggle = document.querySelector('.collapse-toggle');
    var body = document.getElementById('new-task-body');
    if (!toggle || !body) return;
    toggle.setAttribute('aria-expanded', 'true');
    body.hidden = false;
    var card = body.closest('.card') || body;
    if (typeof card.scrollIntoView === 'function') {
      card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    var first = body.querySelector('input, select, textarea');
    if (first) first.focus();
  }

  function initFab() {
    document.querySelectorAll('[data-open-new-task]').forEach(function (button) {
      button.addEventListener('click', openNewTask);
    });
  }

  // Open the create form directly when arriving with ?action=new.
  function initDeepLink() {
    try {
      var params = new URLSearchParams(window.location.search);
      if (params.get('action') === 'new') openNewTask();
    } catch (err) {}
  }

  document.addEventListener('DOMContentLoaded', function () {
    initCollapse();
    initDeleteDialog();
    initFab();
    initDeepLink();
  });
})();
