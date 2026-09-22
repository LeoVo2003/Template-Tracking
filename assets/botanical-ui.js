(function () {
  'use strict';

  function setExpanded(button, expanded) {
    button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
  }

  var sidebarToggle = document.querySelector('[data-mac-sidebar-toggle]');
  var app = document.querySelector('.mac-tracker-app');
  if (sidebarToggle && app) {
    sidebarToggle.addEventListener('click', function () {
      var open = !app.classList.contains('is-sidebar-open');
      app.classList.toggle('is-sidebar-open', open);
      setExpanded(sidebarToggle, open);
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && app.classList.contains('is-sidebar-open')) {
        app.classList.remove('is-sidebar-open');
        setExpanded(sidebarToggle, false);
        sidebarToggle.focus();
      }
    });
  }

  var menuTriggers = [].slice.call(document.querySelectorAll('[data-mac-menu-trigger]'));
  function closeMenus(except) {
    menuTriggers.forEach(function (trigger) {
      if (trigger === except) return;
      var menu = trigger.parentNode.querySelector('[data-mac-menu]');
      if (menu) menu.hidden = true;
      setExpanded(trigger, false);
    });
  }
  menuTriggers.forEach(function (trigger) {
    trigger.addEventListener('click', function (event) {
      event.stopPropagation();
      var menu = trigger.parentNode.querySelector('[data-mac-menu]');
      if (!menu) return;
      var opening = menu.hidden;
      closeMenus(trigger);
      menu.hidden = !opening;
      setExpanded(trigger, opening);
      if (opening) {
        var first = menu.querySelector('a,button');
        if (first) first.focus();
      }
    });
  });
  document.addEventListener('click', function () { closeMenus(); });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeMenus();
  });

  function setupTabs(buttonSelector, panelSelector, nameFromButton, nameFromPanel, activateExtra) {
    var buttons = [].slice.call(document.querySelectorAll(buttonSelector));
    var panels = [].slice.call(document.querySelectorAll(panelSelector));
    if (!buttons.length || !panels.length) return;

    function activate(name, focus) {
      buttons.forEach(function (button) {
        var active = nameFromButton(button) === name;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-selected', active ? 'true' : 'false');
        button.tabIndex = active ? 0 : -1;
        if (active && focus) button.focus();
      });
      panels.forEach(function (panel) {
        panel.hidden = nameFromPanel(panel) !== name;
      });
      if (activateExtra) activateExtra(name);
    }

    buttons.forEach(function (button, index) {
      button.addEventListener('click', function () { activate(nameFromButton(button), false); });
      button.addEventListener('keydown', function (event) {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
        event.preventDefault();
        var next = event.key === 'ArrowRight' ? (index + 1) % buttons.length : (index - 1 + buttons.length) % buttons.length;
        activate(nameFromButton(buttons[next]), true);
      });
    });

    var selected = buttons.find(function (button) { return button.classList.contains('is-active'); }) || buttons[0];
    activate(nameFromButton(selected), false);
  }

  var aiButtons = [].slice.call(document.querySelectorAll('[data-ai-tab]'));
  var aiPanels = [].slice.call(document.querySelectorAll('[data-ai-panel]'));
  if (aiButtons.length && aiPanels.length) {
    function activateAi(name, focus) {
      aiButtons.forEach(function (button) {
        var active = button.dataset.aiTab === name;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-selected', active ? 'true' : 'false');
        button.tabIndex = active ? 0 : -1;
        if (active && focus) button.focus();
      });
      aiPanels.forEach(function (panel) {
        var panelName = panel.dataset.aiPanel;
        panel.hidden = 'visual' === panelName ? ['processing', 'review', 'locked'].indexOf(name) < 0 : panelName !== name;
      });
      var selected = aiButtons.find(function (button) { return button.dataset.aiTab === name; });
      if (selected && selected.dataset.aiVisualTab) {
        var engineTab = document.querySelector('[data-visual-tab="' + selected.dataset.aiVisualTab + '"]');
        if (engineTab) engineTab.click();
      }
      if (window.history && window.history.replaceState) window.history.replaceState(null, '', '#' + name);
    }
    aiButtons.forEach(function (button, index) {
      button.addEventListener('click', function () { activateAi(button.dataset.aiTab, false); });
      button.addEventListener('keydown', function (event) {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
        event.preventDefault();
        var next = event.key === 'ArrowRight' ? (index + 1) % aiButtons.length : (index - 1 + aiButtons.length) % aiButtons.length;
        activateAi(aiButtons[next].dataset.aiTab, true);
      });
    });
    var requestedAi = window.location.hash.replace('#', '');
    activateAi(aiButtons.some(function (button) { return button.dataset.aiTab === requestedAi; }) ? requestedAi : 'action', false);
  }

  var settingsButtons = [].slice.call(document.querySelectorAll('[data-settings-tab]'));
  var settingsPanels = [].slice.call(document.querySelectorAll('[data-settings-panel]'));
  if (settingsButtons.length && settingsPanels.length) {
    function activateSettings(name, focus) {
      settingsButtons.forEach(function (button) {
        var active = button.dataset.settingsTab === name;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-selected', active ? 'true' : 'false');
        button.tabIndex = active ? 0 : -1;
        if (active && focus) button.focus();
      });
      settingsPanels.forEach(function (panel) { panel.hidden = panel.dataset.settingsPanel !== name; });
      if (window.history && window.history.replaceState) window.history.replaceState(null, '', '#' + (name === 'data' ? 'data-management' : name));
    }
    settingsButtons.forEach(function (button, index) {
      button.addEventListener('click', function () { activateSettings(button.dataset.settingsTab, false); });
      button.addEventListener('keydown', function (event) {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
        event.preventDefault();
        var next = event.key === 'ArrowRight' ? (index + 1) % settingsButtons.length : (index - 1 + settingsButtons.length) % settingsButtons.length;
        activateSettings(settingsButtons[next].dataset.settingsTab, true);
      });
    });
    var requestedSettings = window.location.hash === '#data-management' ? 'data' : (window.location.hash === '#mac-tracker-github-dispatch' ? 'connections' : window.location.hash.replace('#', ''));
    activateSettings(settingsButtons.some(function (button) { return button.dataset.settingsTab === requestedSettings; }) ? requestedSettings : 'import', false);
  }

  var skippedSearch = document.querySelector('[data-skipped-search]');
  if (skippedSearch) {
    skippedSearch.addEventListener('input', function () {
      var query = skippedSearch.value.trim().toLowerCase();
      [].slice.call(document.querySelectorAll('[data-skipped-row]')).forEach(function (row) {
        row.hidden = query && row.textContent.toLowerCase().indexOf(query) < 0;
      });
    });
  }
}());
