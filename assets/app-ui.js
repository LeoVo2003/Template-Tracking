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
    var projectRange = event.target.closest('.mac-tracker-filters select[name="range"]');
    if (projectRange) {
      var rangeControl = projectRange.closest('.mac-tracker-filter-range');
      var customRange = rangeControl?.querySelector('[data-mac-project-custom-range]');
      if (customRange) {
        customRange.hidden = projectRange.value !== 'custom';
        if (!customRange.hidden) customRange.querySelector('input')?.focus();
      }
      return;
    }
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

  function bindDashboardRange(scope) {
    var form = (scope || document).querySelector('[data-mac-dashboard-range]');
    if (!form || form.dataset.macDashboardBound || !window.macTrackerApp?.dashboardNonce) return;
    form.dataset.macDashboardBound = '1';
    var select = form.querySelector('select[name="range"]');
    var custom = form.querySelector('details');
    var busy = false;

    function setStatus(message, error) {
      var status = form.querySelector('[data-mac-dashboard-range-status]');
      if (!status) {
        status = document.createElement('p');
        status.dataset.macDashboardRangeStatus = '1';
        status.setAttribute('role', 'status');
        form.appendChild(status);
      }
      status.className = error ? 'is-error' : '';
      status.textContent = message;
    }

    function load(range) {
      if (busy) return;
      var from = form.querySelector('[name="custom_from"]')?.value || '';
      var to = form.querySelector('[name="custom_to"]')?.value || '';
      if ('custom' === range && (!from || !to)) { setStatus('Choose both dates for a custom range.', true); return; }
      var layout = form.closest('.mac-tracker-dashboard-layout');
      if (!layout) return;
      busy = true;
      layout.classList.add('is-loading');
      layout.setAttribute('aria-busy', 'true');
      setStatus('Loading range…', false);
      fetch(macTrackerApp.ajaxUrl, {
        method: 'POST', credentials: 'same-origin',
        body: new URLSearchParams({ action: 'mac_tracker_load_dashboard', nonce: macTrackerApp.dashboardNonce, range: range, custom_from: from, custom_to: to })
      })
        .then(function (response) { return response.json(); })
        .then(function (payload) {
          if (!payload.success || !payload.data?.html) throw new Error(payload.data?.message || 'Could not load this range.');
          var template = document.createElement('template');
          template.innerHTML = payload.data.html.trim();
          var next = template.content.firstElementChild;
          if (!next) throw new Error('The Dashboard response was empty.');
          layout.replaceWith(next);
          bindDashboardRange(next);
        })
        .catch(function (error) {
          layout.classList.remove('is-loading');
          layout.removeAttribute('aria-busy');
          setStatus(error.message || 'Could not load this range.', true);
        })
        .finally(function () { busy = false; });
    }

    select?.addEventListener('change', function () {
      if (custom) custom.open = false;
      load(select.value || 'all');
    });
    custom?.addEventListener('toggle', function () { form.classList.toggle('is-custom-open', custom.open); });
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      load('custom');
    });
  }

  function bindAdvancedControls() {
    function closeAll(except) {
      document.querySelectorAll('.mac-tracker-advanced-controls[open]').forEach(function (details) {
        if (details !== except) details.open = false;
      });
    }
    document.addEventListener('click', function (event) {
      closeAll(event.target.closest('.mac-tracker-advanced-controls'));
    });
    document.addEventListener('keydown', function (event) {
      if ('Escape' === event.key) closeAll(null);
    });
  }

  /**
   * Branded replacement for alert()/confirm(). Resolves true on confirm, false on cancel/Esc/backdrop.
   * options: { icon, title, message, versions:[from,to], confirm, cancel (null = no cancel), busy }
   * With busy:true no buttons are shown and the returned promise's .close() dismisses it.
   */
  function macDialog(options) {
    var opts = options || {};
    var previous = document.activeElement;
    var overlay = document.createElement('div');
    overlay.className = 'mac-tracker-dialog' + (opts.busy ? ' is-busy' : '');
    var card = document.createElement('div');
    card.className = 'mac-tracker-dialog__card';
    card.setAttribute('role', opts.busy ? 'alertdialog' : 'dialog');
    card.setAttribute('aria-modal', 'true');
    card.setAttribute('aria-labelledby', 'mac-tracker-dialog-title');

    var icon = document.createElement('span');
    icon.className = 'mac-tracker-dialog__icon dashicons dashicons-' + (opts.busy ? 'update' : (opts.icon || 'info-outline'));
    icon.setAttribute('aria-hidden', 'true');
    var title = document.createElement('h2');
    title.id = 'mac-tracker-dialog-title';
    title.textContent = opts.title || '';
    card.appendChild(icon);
    card.appendChild(title);
    if (opts.versions) {
      var versions = document.createElement('p');
      versions.className = 'mac-tracker-dialog__versions';
      versions.innerHTML = '<span></span><i class="dashicons dashicons-arrow-right-alt" aria-hidden="true"></i><strong></strong>';
      versions.querySelector('span').textContent = 'v' + opts.versions[0];
      versions.querySelector('strong').textContent = 'v' + opts.versions[1];
      card.appendChild(versions);
    }
    if (opts.message) {
      var message = document.createElement('p');
      message.className = 'mac-tracker-dialog__message';
      message.textContent = opts.message;
      card.appendChild(message);
    }
    overlay.appendChild(card);
    document.body.appendChild(overlay);
    document.body.classList.add('mac-tracker-dialog-open');

    var finish;
    var promise = new Promise(function (resolve) { finish = resolve; });
    function close(result) {
      document.removeEventListener('keydown', onKey, true);
      overlay.remove();
      document.body.classList.remove('mac-tracker-dialog-open');
      if (previous && previous.focus && document.contains(previous)) previous.focus();
      finish(!!result);
    }
    function onKey(event) {
      if (opts.busy) { if ('Escape' === event.key) event.preventDefault(); return; }
      if ('Escape' === event.key) { event.preventDefault(); close(false); return; }
      if ('Tab' !== event.key) return;
      var focusable = Array.from(card.querySelectorAll('button'));
      if (!focusable.length) return;
      var first = focusable[0];
      var last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
    document.addEventListener('keydown', onKey, true);

    if (!opts.busy) {
      var actions = document.createElement('div');
      actions.className = 'mac-tracker-dialog__actions';
      if (null !== opts.cancel) {
        var cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'mac-tracker-dialog__button';
        cancel.textContent = opts.cancel || 'Cancel';
        cancel.addEventListener('click', function () { close(false); });
        actions.appendChild(cancel);
      }
      var confirm = document.createElement('button');
      confirm.type = 'button';
      confirm.className = 'mac-tracker-dialog__button is-primary';
      confirm.textContent = opts.confirm || 'OK';
      confirm.addEventListener('click', function () { close(true); });
      actions.appendChild(confirm);
      card.appendChild(actions);
      overlay.addEventListener('mousedown', function (event) { if (event.target === overlay) close(false); });
      confirm.focus();
    }
    promise.close = function () { close(false); };
    return promise;
  }

  function pluginAjax(action, nonce) {
    return fetch(macTrackerApp.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: new URLSearchParams({ action: action, nonce: nonce })
    }).then(function (response) {
      return response.json().catch(function () { return { success: false, data: { message: 'The server answered with an unexpected response (HTTP ' + response.status + ').' } }; });
    }).then(function (payload) {
      if (!payload.success) throw new Error((payload.data && payload.data.message) || 'The request did not finish.');
      return payload.data || {};
    });
  }

  function installPluginUpdate(button) {
    var label = button.innerHTML;
    button.disabled = true;
    button.textContent = 'Updating…';
    var busy = macDialog({ busy: true, title: 'Updating MAC Project Tracker', message: 'Installing the new version. Please keep this tab open.' });
    return pluginAjax('mac_tracker_install_update', macTrackerApp.updateNonce).then(function () {
      button.textContent = 'Reloading…';
      var card = document.querySelector('.mac-tracker-dialog__card h2');
      if (card) card.textContent = 'Update installed';
      var note = document.querySelector('.mac-tracker-dialog__message');
      if (note) note.textContent = 'Reloading the dashboard…';
      // Right after files are swapped the next request can briefly hit a half-loaded
      // plugin (WordPress critical error). Probe a clean URL until it is healthy.
      var clean = new URL(window.location.href);
      clean.searchParams.delete('mac_tracker_update_check');
      clean.searchParams.delete('mac_tracker_latest');
      var attempts = 0;
      (function probe() {
        attempts += 1;
        fetch(clean.href, { credentials: 'same-origin', cache: 'no-store' }).then(function (page) {
          return page.text().then(function (html) { return page.ok && html.indexOf('critical error') === -1; });
        }).catch(function () { return false; }).then(function (healthy) {
          if (healthy || attempts >= 6) { window.location.href = clean.href; return; }
          window.setTimeout(probe, 1500);
        });
      }());
    }).catch(function (error) {
      busy.close();
      button.disabled = false;
      button.innerHTML = label;
      return macDialog({ icon: 'warning', title: 'The update did not finish', message: error.message || 'Please try again from the Plugins page.', cancel: null, confirm: 'Close' });
    });
  }

  function bindPluginUpdate() {
    document.addEventListener('click', function (event) {
      var install = event.target.closest('[data-mac-install-update]');
      if (install && !install.disabled && window.macTrackerApp?.updateNonce) {
        event.preventDefault();
        installPluginUpdate(install);
        return;
      }
      var check = event.target.closest('[data-mac-check-update]');
      if (!check || check.disabled || !window.macTrackerApp?.checkNonce) return;
      event.preventDefault();
      var label = check.innerHTML;
      check.disabled = true;
      check.textContent = 'Checking…';
      pluginAjax('mac_tracker_check_update', macTrackerApp.checkNonce).then(function (info) {
        check.disabled = false;
        check.innerHTML = label;
        if (!info.available) {
          return macDialog({ icon: 'yes-alt', title: 'You are up to date', message: 'MAC Project Tracker v' + info.current + ' is the latest version.', cancel: null, confirm: 'Close' });
        }
        return macDialog({
          icon: 'update',
          title: 'Update available',
          versions: [info.current, info.version],
          message: 'The plugin installs in place and this page reloads when it finishes.',
          confirm: 'Update now',
          cancel: 'Not now'
        }).then(function (yes) {
          if (!yes) return;
          check.removeAttribute('data-mac-check-update');
          check.setAttribute('data-mac-install-update', '');
          return installPluginUpdate(check);
        });
      }).catch(function (error) {
        check.disabled = false;
        check.innerHTML = label;
        return macDialog({ icon: 'warning', title: 'Could not check for updates', message: error.message || 'Please try again shortly.', cancel: null, confirm: 'Close' });
      });
    });
  }

  function bindProjectSearch() {
    var form = document.querySelector('[data-mac-project-filters-form]');
    if (!form || form.dataset.macSearchBound || !window.macTrackerApp?.projectRowsNonce) return;
    form.dataset.macSearchBound = '1';
    var timer = 0;
    var requestId = 0;

    function filtersFromForm() {
      var filters = {};
      new FormData(form).forEach(function (value, key) { filters[key] = value; });
      filters.per_page = 'all';
      return filters;
    }

    function paint(filters, data) {
      var shell = document.querySelector('[data-mac-project-shell]');
      var body = shell?.querySelector('[data-mac-project-rows]');
      var empty = shell?.querySelector('[data-mac-project-empty]');
      var table = shell?.querySelector('[data-mac-project-table]');
      var total = Number(data.total || 0);
      if (body) body.innerHTML = data.html || '';
      if (empty) empty.hidden = total > 0;
      if (table) table.hidden = total < 1;
      var totalNode = document.querySelector('[data-mac-project-total]');
      var plural = document.querySelector('[data-mac-project-plural]');
      if (totalNode) totalNode.textContent = total.toLocaleString();
      if (plural) plural.textContent = total === 1 ? '' : 's';
      var summary = shell?.querySelector('.mac-tracker-table-footer > span');
      if (summary) summary.textContent = total ? ('Showing 1–' + Math.min(Number(data.shown || total), total) + ' of ' + total) : 'Showing 0 of 0';
      var pager = shell?.querySelector('.mac-tracker-pagination');
      if (pager) pager.hidden = true;
      var lazy = shell?.querySelector('[data-mac-project-lazy-load]');
      if (lazy) {
        lazy.hidden = !data.has_more;
        lazy.setAttribute('data-mac-project-page', '1');
        lazy.setAttribute('data-mac-project-total', String(total));
        lazy.setAttribute('data-mac-project-filters', JSON.stringify(filters));
        delete lazy.dataset.macProjectBound;
        var note = lazy.querySelector('p');
        if (note) note.textContent = 'Showing the first ' + (data.shown || 0) + ' of ' + total + ' records.';
        hydrateProjectRows(shell);
      }
      var params = new URLSearchParams();
      Object.keys(filters).forEach(function (key) {
        if ('per_page' === key || '' === String(filters[key] || '')) return;
        params.set(key, filters[key]);
      });
      var action = form.getAttribute('action') || window.location.pathname;
      var query = params.toString();
      history.replaceState(null, '', query ? action.split('?')[0] + '?' + query : action.split('?')[0]);
    }

    function run() {
      var filters = filtersFromForm();
      var ticket = ++requestId;
      var shell = document.querySelector('[data-mac-project-shell]');
      if (shell) shell.setAttribute('aria-busy', 'true');
      fetch(macTrackerApp.ajaxUrl, {
        method: 'POST', credentials: 'same-origin',
        body: new URLSearchParams({ action: 'mac_tracker_load_project_rows', nonce: macTrackerApp.projectRowsNonce, filters: JSON.stringify(filters), paged: '1' })
      })
        .then(function (response) { return response.json(); })
        .then(function (payload) {
          if (ticket !== requestId) return;
          if (!payload.success) throw new Error(payload.data?.message || 'Could not search projects.');
          paint(filters, payload.data || {});
        })
        .catch(function () {})
        .finally(function () {
          if (ticket === requestId && shell) shell.removeAttribute('aria-busy');
        });
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      window.clearTimeout(timer);
      run();
    });
    form.querySelector('input[name="search"]')?.addEventListener('input', function () {
      window.clearTimeout(timer);
      timer = window.setTimeout(run, 180);
    });
    form.querySelector('.mac-tracker-filter-actions a')?.addEventListener('click', function (event) {
      event.preventDefault();
      form.querySelectorAll('input, select').forEach(function (field) {
        if ('orderby' === field.name) field.value = 'date';
        else if ('order' === field.name) field.value = 'desc';
        else if ('range' === field.name) field.value = 'all';
        else if ('SELECT' === field.tagName) field.value = '';
        else if ('hidden' !== field.type) field.value = '';
      });
      window.clearTimeout(timer);
      run();
    });
    document.querySelector('.mac-tracker-global-search')?.addEventListener('submit', function (event) {
      event.preventDefault();
      var field = form.querySelector('input[name="search"]');
      if (field) field.value = this.querySelector('input')?.value || '';
      window.clearTimeout(timer);
      run();
    });
  }

  if (window.macTrackerApp?.theme) setTheme(macTrackerApp.theme);
  setupLocalTabs();
  hydrateLazyCards(document);
  hydrateProjectRows(document);
  bindProjectSearch();
  bindPluginUpdate();
  bindAdvancedControls();

  bindDashboardRange(document);
}());
