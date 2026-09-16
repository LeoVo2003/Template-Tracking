document.addEventListener('click', function (event) {
  var trigger = event.target.closest('[data-edit-target]');
  if (!trigger) return;
  var row = document.getElementById(trigger.getAttribute('data-edit-target'));
  if (!row) return;
  row.hidden = !row.hidden;
  if (!row.hidden) { var first = row.querySelector('input'); if (first) first.focus(); }
});

document.addEventListener('DOMContentLoaded', function () {
  var selectAll = document.querySelector('[data-visual-select-all]');
  var selections = Array.prototype.slice.call(document.querySelectorAll('[data-visual-select]'));
  if (selectAll && selections.length) {
    selectAll.addEventListener('change', function () {
      selections.forEach(function (checkbox) { checkbox.checked = selectAll.checked; });
    });
    selections.forEach(function (checkbox) {
      checkbox.addEventListener('change', function () {
        var selected = selections.filter(function (item) { return item.checked; }).length;
        selectAll.checked = selected === selections.length;
        selectAll.indeterminate = selected > 0 && selected < selections.length;
      });
    });
  }
  var visualWork = document.querySelector('.mac-tracker-visual-work');
  if (visualWork) {
    var submitting = false;
    visualWork.addEventListener('submit', function (event) {
      var submitter = event.submitter;
      var action = submitter ? submitter.value : '';
      if ((action === 'reanalyze_selected' || action === 'recapture_selected') && !selections.some(function (checkbox) { return checkbox.checked; })) {
        event.preventDefault();
        window.alert('Select at least one screenshot first.');
        return;
      }
      if (submitting) {
        event.preventDefault();
        return;
      }
      submitting = true;
      visualWork.querySelectorAll('button[type="submit"]').forEach(function (button) {
        button.setAttribute('aria-disabled', 'true');
      });
      if (submitter) submitter.textContent = action.indexOf('recapture') === 0 ? 'Queuing capture…' : 'Queuing…';
    });
  }
  if (document.querySelector('[data-visual-active]')) {
    window.setTimeout(function () { window.location.reload(); }, 15000);
  }
});
