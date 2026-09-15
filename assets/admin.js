document.addEventListener('click', function (event) {
  var trigger = event.target.closest('[data-edit-target]');
  if (!trigger) return;
  var row = document.getElementById(trigger.getAttribute('data-edit-target'));
  if (!row) return;
  row.hidden = !row.hidden;
  if (!row.hidden) { var first = row.querySelector('input'); if (first) first.focus(); }
});
