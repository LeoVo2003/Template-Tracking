(function () {
  'use strict';

  var app = document.querySelector('.mac-tracker-app');
  var sidebarToggle = document.querySelector('[data-mac-sidebar-toggle]');
  var fragmentCache = new Map();
  var fragmentController = null;

  function setExpanded(control, expanded) {
    if (control) control.setAttribute('aria-expanded', expanded ? 'true' : 'false');
  }

  function closeMenus(except) {
    document.querySelectorAll('[data-mac-menu-trigger]').forEach(function (trigger) {
      if (trigger === except) return;
      var menu = trigger.closest('.mac-tracker-row-options')?.querySelector('[data-mac-menu]');
      if (menu) menu.hidden = true;
      setExpanded(trigger, false);
    });
  }

  function setTheme(theme) {
    if (!theme) return;
    document.documentElement.setAttribute('data-mac-theme', theme);
    document.querySelectorAll('[data-mac-theme]').forEach(function (node) { node.setAttribute('data-mac-theme', theme); });
    document.querySelectorAll('[data-mac-theme-option]').forEach(function (card) {
      var selected = card.getAttribute('data-mac-theme-option') === theme;
      card.classList.toggle('is-selected', selected);
      card.setAttribute('aria-checked', selected ? 'true' : 'false');
    });
  }

  function hydrateLazyCards(scope) {
    (scope || document).querySelectorAll('[data-mac-lazy-load]').forEach(function (button) {
      if (button.dataset.macLazyBound) return;
      button.dataset.macLazyBound = '1';
      function appendChunk() {
        var template = button.parentElement?.querySelector('template[data-mac-lazy-cards]');
        if (!template?.content?.children?.length) { button.remove(); return; }
        var fragment = document.createDocumentFragment();
        Array.from(template.content.children).slice(0, 24).forEach(function (node) { fragment.appendChild(node); });
        button.parentNode.insertBefore(fragment, button);
        if (!template.content.children.length) button.remove();
        else button.textContent = 'Load ' + Math.min(24, template.content.children.length) + ' more cards';
      }
      button.addEventListener('click', appendChunk);
      if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
          if (!entries.some(function (entry) { return entry.isIntersecting; })) return;
          observer.disconnect();
          appendChunk();
        }, { rootMargin: '340px 0px' });
        observer.observe(button);
      }
    });
  }

  // "All" means all matching project records, but never all at once.  The
  // table starts with one logical 100-row page and appends later pages only
  // when the user asks or reaches the end of the current table.
  function hydrateProjectRows(scope) {
    (scope || document).querySelectorAll('[data-mac-project-lazy-load]').forEach(function (control) {
      if (control.dataset.macProjectBound || !window.macTrackerApp?.projectRowsNonce) return;
      control.dataset.macProjectBound = '1';
      var button = control.querySelector('[data-mac-project-load-more]');
      var notice = control.querySelector('p');
      var loading = false;
      var observer = null;

      function loadNext() {
        if (loading || !button) return;
        var filters;
        try { filters = JSON.parse(control.getAttribute('data-mac-project-filters') || '{}'); }
        catch (_) { if (notice) notice.textContent = 'Could not read this project filter state. Refresh and try again.'; return; }
        loading = true;
        button.disabled = true;
        button.textContent = 'Loading next 100…';
        var nextPage = Number(control.getAttribute('data-mac-project-page') || '1') + 1;
        fetch(macTrackerApp.ajaxUrl, {
          method: 'POST', credentials: 'same-origin',
          body: new URLSearchParams({ action: 'mac_tracker_load_project_rows', nonce: macTrackerApp.projectRowsNonce, filters: JSON.stringify(filters), paged: String(nextPage) })
        })
          .then(function (response) { return response.json(); })
          .then(function (payload) {
            if (!payload.success) throw new Error(payload.data?.message || 'Could not load more project records.');
            var body = control.closest('.mac-tracker-table-shell')?.querySelector('.mac-tracker-project-table tbody');
            if (body && payload.data.html) body.insertAdjacentHTML('beforeend', payload.data.html);
            control.setAttribute('data-mac-project-page', String(payload.data.paged));
            if (notice) notice.textContent = 'Showing ' + payload.data.shown + ' of ' + payload.data.total + ' records.';
            if (!payload.data.has_more) { control.remove(); if (observer) observer.disconnect(); return; }
            button.disabled = false;
            button.textContent = 'Load next 100';
            loading = false;
          })
          .catch(function (error) {
            if (notice) notice.textContent = error.message || 'Could not load more project records.';
            button.disabled = false;
            button.textContent = 'Try loading next 100';
            loading = false;
          });
      }

      button?.addEventListener('click', loadNext);
      if ('IntersectionObserver' in window && button) {
        observer = new IntersectionObserver(function (entries) {
          if (!entries.some(function (entry) { return entry.isIntersecting; })) return;
          observer.disconnect();
          loadNext();
        }, { rootMargin: '260px 0px' });
        observer.observe(button);
      }
    });
  }

  function applyFragmentTabs(target, section) {
    document.querySelectorAll('[data-mac-fragment-tabs] [data-mac-fragment-target="' + target + '"]').forEach(function (tab) {
      var selected = tab.getAttribute('data-mac-fragment-section') === section;
      tab.classList.toggle('is-active', selected);
      tab.setAttribute('aria-selected', selected ? 'true' : 'false');
    });
  }

  function fragmentError(panel, message, retry) {
    panel.innerHTML = '<div class="mac-tracker-fragment-error" role="alert"><strong>Could not load this view.</strong><p></p><button type="button" class="button">Retry</button></div>';
    panel.querySelector('p').textContent = message || 'Check the connection and try again.';
    panel.querySelector('button').addEventListener('click', retry);
  }

  function loadFragment(trigger, force) {
    if (!window.macTrackerApp) return;
    var target = trigger.getAttribute('data-mac-fragment-target');
    var section = trigger.getAttribute('data-mac-fragment-section') || 'processing';
    var colorStatus = trigger.getAttribute('data-mac-color-status') || '';
    var colorSearch = trigger.getAttribute('data-mac-color-search') || '';
    var pageSize = trigger.getAttribute('data-mac-page-size') || '';
    var fragmentPage = trigger.getAttribute('data-mac-fragment-page') || '';
    var panel = document.querySelector('[data-mac-fragment-panel="' + target + '"]');
    if (!panel) return;
    // A tab is feedback as well as navigation. Reflect the intended destination
    // immediately, while the matching fragment is fetched in the background.
    applyFragmentTabs(target, section);
    var key = [target, section, colorStatus, colorSearch, pageSize, fragmentPage].join(':');
    function render(html) {
      panel.innerHTML = html;
      panel.removeAttribute('aria-busy');
      applyFragmentTabs(target, section);
      hydrateLazyCards(panel);
      document.dispatchEvent(new CustomEvent('mac:fragmentloaded', { detail: { target: target, section: section, panel: panel } }));
    }
    if (!force && fragmentCache.has(key)) { render(fragmentCache.get(key)); return; }
    if (fragmentController) fragmentController.abort();
    fragmentController = new AbortController();
    panel.setAttribute('aria-busy', 'true');
    panel.innerHTML = '<div class="mac-tracker-fragment-skeleton" aria-live="polite"><i></i><i></i><i></i><span>Loading ' + section + '…</span></div>';
    var body = new URLSearchParams({
      action: 'mac_tracker_load_fragment',
      nonce: macTrackerApp.fragmentNonce,
      target: target,
      section: section,
      color_status: colorStatus,
      color_search: colorSearch,
      standalone: macTrackerApp.standalone ? '1' : '0'
    });
    if (pageSize) {
      if ('action' === section) body.set('color_per_page', pageSize);
      else body.set('visual_per_page', pageSize);
    }
    if (fragmentPage) {
      if ('action' === section) body.set('color_page', fragmentPage);
      else body.set('visual_page', fragmentPage);
    }
    fetch(macTrackerApp.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body, signal: fragmentController.signal })
      .then(function (response) { return response.json(); })
      .then(function (payload) {
        if (!payload.success || !payload.data?.html) throw new Error(payload.data?.message || 'The server did not return a usable view.');
        fragmentCache.set(key, payload.data.html);
        render(payload.data.html);
      })
      .catch(function (error) {
        if ('AbortError' === error.name) return;
        panel.removeAttribute('aria-busy');
        fragmentError(panel, error.message, function () { loadFragment(trigger, true); });
      });
  }

  function warmFragment(trigger) {
    if (!window.macTrackerApp || !trigger || !trigger.matches('[data-mac-fragment-tabs] [data-mac-fragment-target]')) return;
    var target = trigger.getAttribute('data-mac-fragment-target'), section = trigger.getAttribute('data-mac-fragment-section') || 'processing';
    var colorStatus = trigger.getAttribute('data-mac-color-status') || '', pageSize = trigger.getAttribute('data-mac-page-size') || '';
    var key = [target, section, colorStatus, '', pageSize, ''].join(':');
    if (fragmentCache.has(key)) return;
    var body = new URLSearchParams({ action: 'mac_tracker_load_fragment', nonce: macTrackerApp.fragmentNonce, target: target, section: section, color_status: colorStatus, standalone: macTrackerApp.standalone ? '1' : '0' });
    if (pageSize) { if ('action' === section) body.set('color_per_page', pageSize); else body.set('visual_per_page', pageSize); }
    fetch(macTrackerApp.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (response) { return response.json(); })
      .then(function (payload) { if (payload.success && payload.data?.html) fragmentCache.set(key, payload.data.html); })
      .catch(function () {});
  }

  function setupLocalTabs() {
    var buttons = Array.from(document.querySelectorAll('[data-settings-tab]'));
    var panels = Array.from(document.querySelectorAll('[data-settings-panel]'));
    if (!buttons.length || !panels.length) return;
    function activate(name, focus) {
      buttons.forEach(function (button) {
        var active = button.dataset.settingsTab === name;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-selected', active ? 'true' : 'false');
        button.tabIndex = active ? 0 : -1;
        if (active && focus) button.focus();
      });
      panels.forEach(function (panel) { panel.hidden = panel.dataset.settingsPanel !== name; });
    }
    buttons.forEach(function (button, index) {
      button.addEventListener('click', function () { activate(button.dataset.settingsTab, false); });
      button.addEventListener('keydown', function (event) {
        if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
        event.preventDefault();
        var next = (index + ('ArrowRight' === event.key ? 1 : -1) + buttons.length) % buttons.length;
        activate(buttons[next].dataset.settingsTab, true);
      });
    });
    activate('appearance', false);
  }

  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-mac-menu-trigger]');
    if (trigger) {
      event.preventDefault(); event.stopPropagation();
      var menu = trigger.closest('.mac-tracker-row-options')?.querySelector('[data-mac-menu]');
      if (!menu) return;
      var open = menu.hidden;
      closeMenus(trigger); menu.hidden = !open; setExpanded(trigger, open);
      if (open) menu.querySelector('a,button')?.focus();
      return;
    }
    var edit = event.target.closest('[data-edit-target]');
    if (edit) {
      event.preventDefault();
      var row = document.getElementById(edit.getAttribute('data-edit-target'));
      if (row) { row.hidden = !row.hidden; if (!row.hidden) row.querySelector('input')?.focus(); }
      closeMenus(); return;
    }
    var fragment = event.target.closest('[data-mac-fragment-target]:not(select)');
    if (fragment) { event.preventDefault(); loadFragment(fragment); return; }
    var theme = event.target.closest('[data-mac-theme-option]');
    if (theme) {
      var themeKey = theme.getAttribute('data-mac-theme-option');
      setTheme(themeKey);
      var label = document.querySelector('[data-mac-theme-status]');
      if (label) label.textContent = 'Previewing ' + theme.querySelector('strong')?.textContent + '. Save appearance to keep it.';
      return;
    }
    var saveTheme = event.target.closest('[data-mac-theme-save]');
    if (saveTheme && window.macTrackerApp) {
      var selected = document.querySelector('[data-mac-theme-option].is-selected')?.getAttribute('data-mac-theme-option') || macTrackerApp.theme;
      saveTheme.disabled = true;
      var status = document.querySelector('[data-mac-theme-status]');
      if (status) status.textContent = 'Saving appearance…';
      fetch(macTrackerApp.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ action: 'mac_tracker_save_ui_theme', nonce: macTrackerApp.themeNonce, theme: selected }) })
        .then(function (response) { return response.json(); })
        .then(function (payload) {
          if (!payload.success) throw new Error(payload.data?.message || 'Appearance could not be saved.');
          macTrackerApp.theme = payload.data.theme;
          setTheme(payload.data.theme);
          if (status) status.textContent = 'Appearance saved.';
        })
        .catch(function (error) { if (status) status.textContent = error.message; })
        .finally(function () { saveTheme.disabled = false; });
      return;
    }
    closeMenus();
  });
  document.addEventListener('pointerover', function (event) { warmFragment(event.target.closest('[data-mac-fragment-target]')); });
  document.addEventListener('focusin', function (event) { warmFragment(event.target.closest('[data-mac-fragment-target]')); });

  document.addEventListener('keydown', function (event) {
    if ('Escape' !== event.key) return;
    closeMenus();
    if (app?.classList.contains('is-sidebar-open')) { app.classList.remove('is-sidebar-open'); setExpanded(sidebarToggle, false); sidebarToggle?.focus(); }
  });

  if (app && sidebarToggle) sidebarToggle.addEventListener('click', function () {
    var open = !app.classList.contains('is-sidebar-open');
    app.classList.toggle('is-sidebar-open', open); setExpanded(sidebarToggle, open);
  });

  document.querySelector('[data-skipped-search]')?.addEventListener('input', function (event) {
    var query = event.target.value.trim().toLowerCase();
    document.querySelectorAll('[data-skipped-row]').forEach(function (row) { row.hidden = Boolean(query && !row.textContent.toLowerCase().includes(query)); });
  });
  document.querySelectorAll('[data-file-input]').forEach(function (input) {
    input.addEventListener('change', function () {
      var label = input.closest('.mac-tracker-upload')?.querySelector('[data-file-name]');
      if (label) label.textContent = input.files?.[0]?.name || 'No file selected';
    });
  });
  document.addEventListener('change', function (event) {
    var size = event.target.closest('[data-mac-fragment-page-size]');
    if (!size) return;
    var colorSearch = size.closest('[data-mac-fragment-panel]')?.querySelector('input[name="color_search"]')?.value || '';
    loadFragment({
      getAttribute: function (name) {
        if ('data-mac-page-size' === name) return size.value;
        if ('data-mac-color-search' === name) return colorSearch;
        return size.getAttribute(name) || '';
      }
    }, true);
  });
  document.addEventListener('mac:invalidatefragments', function () { fragmentCache.clear(); });
  document.addEventListener('mac:loadfragment', function (event) {
    var detail = event.detail || {};
    if (!detail.target || !detail.section) return;
    loadFragment({ getAttribute: function (name) {
      if ('data-mac-fragment-target' === name) return detail.target;
      if ('data-mac-fragment-section' === name) return detail.section;
      return detail[name.replace('data-mac-', '')] || '';
    } }, true);
  });
  if (window.macTrackerApp?.theme) setTheme(macTrackerApp.theme);
  setupLocalTabs();
  hydrateLazyCards(document);
  hydrateProjectRows(document);
}());
