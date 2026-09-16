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
  if (document.querySelector('[data-visual-active]')) {
    window.setTimeout(function () { window.location.reload(); }, 15000);
  }
});
