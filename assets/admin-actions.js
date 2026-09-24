(function () {
  'use strict';

  function ajaxNotice(anchor, message, error, marker) {
    var old = document.querySelector('[' + marker + ']');
    if (old) old.remove();
    var notice = document.createElement('div');
    notice.setAttribute(marker, '1');
    notice.className = 'mac-tracker-notice mac-tracker-notice--' + (error ? 'error' : 'success');
    notice.setAttribute('role', 'status');
    notice.textContent = message;
    anchor.parentNode.insertBefore(notice, anchor);
  }

  function bindVisualActions(scope) {
    var form = (scope || document).querySelector('.mac-tracker-visual-work');
    if (!form || 'undefined' === typeof macTrackerVisual) return;
    if (form.dataset.macVisualBound) return;
    form.dataset.macVisualBound = '1';
    var cards = Array.from(form.querySelectorAll('[data-visual-card]'));
    var selectAll = form.querySelector('[data-visual-select-all]');
    var timer = null;
    var busy = false;

    function cardFor(id) {
      return cards.find(function (card) { return String(card.dataset.visualCard) === String(id); }) || null;
    }
    function boxes() { return cards.map(function (card) { return card.querySelector('[data-visual-select]'); }).filter(Boolean); }
    function selectedIds() { return boxes().filter(function (box) { return box.checked; }).map(function (box) { return box.value; }); }
    function adjustTabCount(section, amount) {
      var tab = document.querySelector('[data-mac-fragment-tabs] [data-mac-fragment-target="ai-panel"][data-mac-fragment-section="' + section + '"]');
      var count = tab?.querySelector('span');
      if (!count) return;
      count.textContent = String(Math.max(0, Number(count.textContent.replace(/[^0-9]/g, '')) + amount));
    }
    function removeApprovedCards(ids) {
      ids.forEach(function (id) { cardFor(id)?.remove(); });
      cards = cards.filter(function (card) { return card.isConnected; });
      if (selectAll) selectAll.checked = false;
      syncSelection();
    }
    function syncSelection() {
      if (!selectAll) return;
      var all = boxes();
      var selected = all.filter(function (box) { return box.checked; });
      var localOnly = selected.length > 0 && selected.every(function (box) {
        var card = box.closest('[data-visual-card]');
        return card?.dataset.visualLocalRetry === '1' && card.dataset.visualErrorCode === 'HTTP_403';
      });
      selectAll.checked = all.length > 0 && selected.length === all.length;
      selectAll.indeterminate = selected.length > 0 && selected.length < all.length;
      form.querySelectorAll('[data-visual-bulk]').forEach(function (button) {
        var base = button.dataset.visualLabel || button.textContent.replace(/ (mục chọn|trang này)$/i, '');
        button.dataset.visualLabel = base;
        button.textContent = base + (selected.length ? ' mục chọn' : ' trang này');
      });
      var capture = form.querySelector('[data-visual-capture], button[value="recapture_selected"]');
      var localCapture = form.querySelector('[data-visual-local-capture], button[value="local_retry_selected"]');
      if (capture && localCapture) {
        capture.hidden = localOnly;
        localCapture.hidden = !localOnly;
      }
    }
    selectAll?.addEventListener('change', function () { boxes().forEach(function (box) { box.checked = selectAll.checked; }); syncSelection(); });
    form.addEventListener('change', function (event) { if (event.target.matches('[data-visual-select]')) syncSelection(); });

    function setStatus(card, data) {
      var state = data.pipeline_status || 'idle';
      var status = card.querySelector('.mac-tracker-visual-status');
      if (!status) return;
      var provider = data.provider ? data.provider + (data.model ? ' · ' + data.model : '') : 'AI';
      var labels = {
        idle: ['neutral', 'Idle · not started'], capture_queued: ['waiting', 'Capture queued'],
        capturing: ['running', 'Capturing homepage'], captured: ['waiting', 'Screenshot saved · awaiting analysis'],
        analysis_queued: ['waiting', 'Queued for AI analysis'], analyzing: ['running', 'Analyzing with ' + provider],
        classified: data.human_locked ? ['locked', 'Locked · human approved'] : ['success', 'AI complete · awaiting approval'],
        needs_review: ['review', 'Needs review'], retry_wait: ['waiting', 'Retry scheduled'],
        blocked: ['failed', 'Blocked · ' + (data.last_error_message || 'manual action required')],
        failed: ['failed', 'Failed · ' + (data.last_error_message || 'retry available')]
      };
      var entry = labels[state] || labels.idle;
      status.className = 'mac-tracker-visual-status mac-tracker-visual-status--' + entry[0];
      status.textContent = entry[1];
      if (['capture_queued', 'capturing', 'analysis_queued', 'analyzing'].includes(state)) card.dataset.visualPoll = '1';
      else card.removeAttribute('data-visual-poll');
      var reason = card.querySelector('[data-visual-reason]');
      if (reason && 'analysis_queued' === state) reason.textContent = 'Queued for AI analysis.';
    }
    function update(items) {
      items.forEach(function (data) {
        var card = cardFor(data.id);
        if (!card) return;
        setStatus(card, data);
        if (data.screenshot_url) {
          var link = card.querySelector('.mac-tracker-visual-card__image');
          var image = link?.querySelector('img');
          if (link) link.href = data.screenshot_url;
          if (image) image.src = data.screenshot_url;
        }
      });
    }
    function fetchStatuses(ids) {
      if (!ids.length) return Promise.resolve({ success: true });
      var body = new URLSearchParams({ action: 'mac_tracker_visual_status', nonce: macTrackerVisual.statusNonce });
      ids.forEach(function (id) { body.append('snapshot_ids[]', id); });
      return fetch(macTrackerVisual.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
        .then(function (response) { return response.json(); })
        .then(function (payload) { if (payload.success) update(payload.data.statuses || []); return payload; });
    }
    function poll() {
      var ids = cards.filter(function (card) { return '1' === card.dataset.visualPoll; }).map(function (card) { return card.dataset.visualCard; });
      if (!ids.length) { timer = null; return; }
      fetchStatuses(ids).finally(function () { timer = setTimeout(poll, 8000); });
    }
    function startPolling() { if (!timer) timer = setTimeout(poll, 3500); }

    form.addEventListener('submit', function (event) {
      var button = event.submitter;
      var action = button?.value || '';
      if (!action || busy) { event.preventDefault(); return; }
      event.preventDefault();
      var ids = selectedIds();
      var one = action.match(/^(?:reanalyze|recapture|local_retry|unlock_tone|save_tone|approve_one|skip_one)_(?:one_)?(\d+)$/);
      if (one) ids = [one[1]];
      var bulk = ['reanalyze_selected', 'recapture_selected', 'local_retry_selected', 'capture_analyze_selected', 'approve_selected'];
      if (bulk.includes(action) && !ids.length) {
        boxes().forEach(function (box) { box.checked = true; });
        ids = selectedIds();
      }
      if (bulk.includes(action) && !ids.length) { window.alert('Không có card nào trên trang này để chạy.'); return; }

      busy = true;
      var original = button?.textContent || '';
      if (button) { button.disabled = true; button.textContent = 'Working…'; }
      var body = new URLSearchParams({ action: 'mac_tracker_visual_action', nonce: macTrackerVisual.actionNonce, visual_action: action });
      ids.forEach(function (id) { body.append('snapshot_ids[]', id); });
      form.querySelectorAll('select[name^="manual_tone"]').forEach(function (select) { if (select.value) body.append(select.name, select.value); });
      fetch(macTrackerVisual.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
        .then(function (response) { return response.json(); })
        .then(function (payload) {
          if (!payload.success) throw new Error(payload.data?.message || 'Visual action failed.');
          ajaxNotice(form, payload.data.message || 'Action queued.', false, 'data-visual-ajax-notice');
          document.dispatchEvent(new CustomEvent('mac:invalidatefragments'));
          if (/^approve_(?:selected|one_)/.test(action) || /^save_tone_\d+$/.test(action)) {
            var changed = Math.min(ids.length, Number(payload.data?.changed || ids.length));
            removeApprovedCards(ids);
            adjustTabCount('review', -changed);
            adjustTabCount('locked', changed);
            return;
          }
          return fetchStatuses(ids).then(startPolling);
        })
        .catch(function (error) { ajaxNotice(form, error.message || 'Visual action failed.', true, 'data-visual-ajax-notice'); })
        .finally(function () { busy = false; if (button) { button.disabled = false; button.textContent = original; } });
    });

    cards.filter(function (card) { return card.querySelector('[data-visual-active]'); }).forEach(function (card) { card.dataset.visualPoll = '1'; });
    if (cards.some(function (card) { return '1' === card.dataset.visualPoll; })) startPolling();
    syncSelection();
  }

  document.addEventListener('DOMContentLoaded', function () { bindVisualActions(document); });
  document.addEventListener('mac:fragmentloaded', function (event) { bindVisualActions(event.detail?.panel || document); });

  function bindVisualControls(scope) {
    if ('undefined' === typeof macTrackerVisual) return;
    var root = scope || document;
    var runForm = root.querySelector('.mac-tracker-visual-run');
    if (runForm?.dataset.macRunBound) runForm = null;
    if (runForm) runForm.dataset.macRunBound = '1';
    runForm?.addEventListener('submit', function (event) {
      event.preventDefault();
      var button = runForm.querySelector('button');
      if (button) button.disabled = true;
      var body = new URLSearchParams({ action: 'mac_tracker_run_visual_workflow', nonce: macTrackerVisual.runNonce });
      fetch(macTrackerVisual.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
        .then(function (response) { return response.json(); })
        .then(function (payload) {
          if (!payload.success) throw new Error(payload.data?.message || 'GitHub dispatch failed.');
          ajaxNotice(runForm, payload.data.message, false, 'data-run-ajax-notice');
          document.dispatchEvent(new CustomEvent('mac:invalidatefragments'));
        })
        .catch(function (error) { ajaxNotice(runForm, error.message || 'Could not start the workflow.', true, 'data-run-ajax-notice'); })
        .finally(function () { if (button) button.disabled = false; });
    });

    var controls = root.querySelector('[data-visual-controls]');
    var saveStatus = controls?.querySelector('[data-visual-controls-status]');
    if (!controls || !saveStatus) return;
    if (controls.dataset.macControlsBound) return;
    controls.dataset.macControlsBound = '1';
    var timer;
    function saveControls() {
      saveStatus.textContent = 'Saving…';
      var body = new URLSearchParams({ action: 'mac_tracker_save_visual_controls', nonce: macTrackerVisual.controlsNonce });
      new FormData(controls).forEach(function (value, key) { if (!['_wpnonce', 'action'].includes(key)) body.append(key, value); });
      fetch(macTrackerVisual.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
        .then(function (response) { return response.json(); })
        .then(function (payload) { if (!payload.success) throw new Error(); saveStatus.textContent = 'Saved'; document.dispatchEvent(new CustomEvent('mac:invalidatefragments')); })
        .catch(function () { saveStatus.textContent = 'Save failed'; });
    }
    controls.addEventListener('change', function () { clearTimeout(timer); timer = setTimeout(saveControls, 350); });
    controls.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(saveControls, 500); });
  }

  document.addEventListener('DOMContentLoaded', function () { bindVisualControls(document); });
  document.addEventListener('mac:fragmentloaded', function (event) { bindVisualControls(event.detail?.panel || document); });

  function bindColorActions(scope) {
    if ('undefined' === typeof macTrackerColors) return;
    var root = scope || document;
    function send(form, action, nonce) {
      var body = new URLSearchParams({ action: action, nonce: nonce });
      new FormData(form).forEach(function (value, key) { if (!['_wpnonce', 'action'].includes(key)) body.append(key, value); });
      return fetch(macTrackerColors.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body }).then(function (response) { return response.json(); });
    }
    var extractAll = root.querySelector('.mac-tracker-color-extract-all');
    if (extractAll?.dataset.macColorBound) extractAll = null;
    if (extractAll) { extractAll.dataset.macColorBound = '1'; extractAll.addEventListener('submit', function (event) {
      event.preventDefault(); var form = event.currentTarget; var button = form.querySelector('button'); button.disabled = true;
      send(form, 'mac_tracker_extract_all_colors', macTrackerColors.extractAllNonce).then(function (payload) { if (!payload.success) throw new Error(payload.data.message); ajaxNotice(form, payload.data.message, false, 'data-color-ajax-notice'); }).catch(function (error) { ajaxNotice(form, error.message, true, 'data-color-ajax-notice'); button.disabled = false; });
    }); }
    root.querySelectorAll('.mac-tracker-color-extract').forEach(function (form) {
      if (form.dataset.macColorBound) return; form.dataset.macColorBound = '1';
      form.addEventListener('submit', function (event) { event.preventDefault(); var button = form.querySelector('button'); button.disabled = true; send(form, 'mac_tracker_extract_colors', macTrackerColors.extractNonce).then(function (payload) { if (!payload.success) throw new Error(payload.data.message); ajaxNotice(form, payload.data.message, false, 'data-color-ajax-notice'); button.textContent = 'Extracted'; }).catch(function (error) { ajaxNotice(form, error.message, true, 'data-color-ajax-notice'); button.disabled = false; }); });
    });
    root.querySelectorAll('.mac-tracker-color-approve').forEach(function (form) {
      if (form.dataset.macColorBound) return; form.dataset.macColorBound = '1';
      form.addEventListener('submit', function (event) { event.preventDefault(); var button = form.querySelector('button'); button.disabled = true; send(form, 'mac_tracker_approve_colors', macTrackerColors.approveNonce).then(function (payload) { if (!payload.success) throw new Error(payload.data.message); ajaxNotice(form, payload.data.message, false, 'data-color-ajax-notice'); form.closest('[data-color-card]')?.classList.add('is-approved'); document.dispatchEvent(new CustomEvent('mac:invalidatefragments')); }).catch(function (error) { ajaxNotice(form, error.message, true, 'data-color-ajax-notice'); button.disabled = false; }); });
    });
  }

  document.addEventListener('DOMContentLoaded', function () { bindColorActions(document); });
  document.addEventListener('mac:fragmentloaded', function (event) { bindColorActions(event.detail?.panel || document); });
}());
