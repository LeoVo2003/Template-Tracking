(function () {
  'use strict';

  var app = document.querySelector('.mac-tracker-app');
  var sidebarToggle = document.querySelector('[data-mac-sidebar-toggle]');

  function setExpanded(control, expanded) {
    if (control) control.setAttribute('aria-expanded', expanded ? 'true' : 'false');
  }

  if (app && sidebarToggle) {
    sidebarToggle.addEventListener('click', function () {
      var open = !app.classList.contains('is-sidebar-open');
      app.classList.toggle('is-sidebar-open', open);
      setExpanded(sidebarToggle, open);
    });
  }

  function closeMenus(except) {
    document.querySelectorAll('[data-mac-menu-trigger]').forEach(function (trigger) {
      if (trigger === except) return;
      var menu = trigger.closest('.mac-tracker-row-options')?.querySelector('[data-mac-menu]');
      if (menu) menu.hidden = true;
      setExpanded(trigger, false);
    });
  }

  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-mac-menu-trigger]');
    if (trigger) {
      event.preventDefault();
      event.stopPropagation();
      var menu = trigger.closest('.mac-tracker-row-options')?.querySelector('[data-mac-menu]');
      if (!menu) return;
      var open = menu.hidden;
      closeMenus(trigger);
      menu.hidden = !open;
      setExpanded(trigger, open);
      if (open) menu.querySelector('a,button')?.focus();
      return;
    }

    var edit = event.target.closest('[data-edit-target]');
    if (edit) {
      event.preventDefault();
      var row = document.getElementById(edit.getAttribute('data-edit-target'));
      if (row) {
        row.hidden = !row.hidden;
        if (!row.hidden) row.querySelector('input')?.focus();
      }
      closeMenus();
      return;
    }
    closeMenus();
  });

  document.addEventListener('keydown', function (event) {
    if ('Escape' !== event.key) return;
    closeMenus();
    if (app?.classList.contains('is-sidebar-open')) {
      app.classList.remove('is-sidebar-open');
      setExpanded(sidebarToggle, false);
      sidebarToggle?.focus();
    }
  });

  function setupLocalTabs(buttonSelector, panelSelector, buttonKey, panelKey, fallback) {
    var buttons = Array.from(document.querySelectorAll(buttonSelector));
    var panels = Array.from(document.querySelectorAll(panelSelector));
    if (!buttons.length || !panels.length) return;

    function activate(name, focus) {
      buttons.forEach(function (button) {
        var active = button.dataset[buttonKey] === name;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-selected', active ? 'true' : 'false');
        button.tabIndex = active ? 0 : -1;
        if (active && focus) button.focus();
      });
      panels.forEach(function (panel) { panel.hidden = panel.dataset[panelKey] !== name; });
      if (history.replaceState) history.replaceState(null, '', '#' + ('data' === name ? 'data-management' : name));
    }

    buttons.forEach(function (button, index) {
      button.addEventListener('click', function () { activate(button.dataset[buttonKey], false); });
      button.addEventListener('keydown', function (event) {
        if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
        event.preventDefault();
        var step = 'ArrowRight' === event.key ? 1 : -1;
        var next = (index + step + buttons.length) % buttons.length;
        activate(buttons[next].dataset[buttonKey], true);
      });
    });

    var requested = location.hash.replace('#', '');
    if ('data-management' === requested) requested = 'data';
    activate(buttons.some(function (button) { return button.dataset[buttonKey] === requested; }) ? requested : fallback, false);
  }

  setupLocalTabs('[data-settings-tab]', '[data-settings-panel]', 'settingsTab', 'settingsPanel', 'import');

  var skippedSearch = document.querySelector('[data-skipped-search]');
  skippedSearch?.addEventListener('input', function () {
    var query = skippedSearch.value.trim().toLowerCase();
    document.querySelectorAll('[data-skipped-row]').forEach(function (row) {
      row.hidden = Boolean(query && !row.textContent.toLowerCase().includes(query));
    });
  });

  document.querySelectorAll('[data-file-input]').forEach(function (input) {
    input.addEventListener('change', function () {
      var label = input.closest('.mac-tracker-upload')?.querySelector('[data-file-name]');
      if (label) label.textContent = input.files?.[0]?.name || 'No file selected';
    });
  });
}());
