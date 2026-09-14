/* Build a reviewable hybrid list: Action Design done takes priority over CSV. */
const fs = require('fs');
const path = require('path');

const [csvPath, wpmPath, pinPath, legacyPath, outputPath] = process.argv.slice(2);
if (!csvPath || !wpmPath || !pinPath || !legacyPath || !outputPath) {
	throw new Error('Usage: node tools/build-qa-hybrid.js <qa.csv> <wpm-full.json> <historic-pin.csv> <wpm-catalog.json> <output.csv>');
}

function csvRows(text) {
  const rows = []; let row = [], cell = '', quoted = false;
  for (let i = 0; i < text.length; i += 1) {
    const char = text[i];
    if (quoted) {
      if (char === '"' && text[i + 1] === '"') { cell += '"'; i += 1; }
      else if (char === '"') quoted = false;
      else cell += char;
    } else if (char === '"') quoted = true;
    else if (char === ',') { row.push(cell); cell = ''; }
    else if (char === '\n') { row.push(cell.replace(/\r$/, '')); rows.push(row); row = []; cell = ''; }
    else cell += char;
  }
  if (cell || row.length) { row.push(cell); rows.push(row); }
  return rows;
}
function norm(value = '') {
  return String(value).toUpperCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')
    .replace(/[^A-Z0-9]+/g, ' ').trim().replace(/\s+/g, ' ');
}
function tokens(value) {
  return new Set(norm(value).split(' ').filter((x) => x && !/^(SE1|SEM|PROX[0-9]+|F00|S00|PE1|SCM|CM|DAY)$/.test(x)));
}
function similarity(a, b) {
  const left = tokens(a); const right = tokens(b);
  const union = new Set([...left, ...right]);
  let common = 0; left.forEach((x) => { if (right.has(x)) common += 1; });
  return union.size ? common / union.size : 0;
}
function zip(value) { const found = norm(value).match(/^([0-9]{5})/); return found ? found[1] : ''; }
function escape(value) {
  const text = value == null ? '' : String(value);
  return /[",\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}
function dateTime(value) {
  if (!value) return { date: '', time: '' };
  const dt = new Date(value);
  if (Number.isNaN(dt.getTime())) return { date: '', time: '' };
  const parts = new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Bangkok', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false }).formatToParts(dt);
  const get = (type) => parts.find((part) => part.type === type).value;
  return { date: `${get('day')}/${get('month')}/${get('year')}`, time: `${get('hour')}:${get('minute')}` };
}
function csvDueDate(value) {
  const match = String(value || '').trim().match(/^(\d{1,2}[/-]\d{1,2}(?:[/-]\d{2,4})?)\s+(\d{1,2}:\d{2})$/);
  return match ? { date: match[1], time: match[2] } : { date: String(value || '').trim(), time: '' };
}
function actionRows(project) {
  return (project.tasks || []).filter((task) => task.task_type && task.task_type.name === '[Website] Action Design'
    && ['done', 'complete', 'completed'].includes(String(task.status).toLowerCase()));
}

const sourceRows = csvRows(fs.readFileSync(csvPath, 'utf8'));
const headers = sourceRows.shift();
const source = sourceRows.filter((row) => row.some((value) => value.trim() !== '')).map((row) => Object.fromEntries(headers.map((header, i) => [header || `H${i + 1}`, row[i] || ''])));
const projects = JSON.parse(fs.readFileSync(wpmPath, 'utf8'));
const exact = new Map(projects.map((project) => [norm(project.full_name), project]));
const legacyProjects = JSON.parse(fs.readFileSync(legacyPath, 'utf8'));
// Confirmed historical corrections that are absent from the supplied WPM exports.
const manualProjectIds = new Map([
  [norm('60803SE1 PROX3 LAMIA NAILS'), 2956],
  [norm('30346SE1 PROX3 NAIL TALK & TAN'), 3777],
]);
const pinRows = csvRows(fs.readFileSync(pinPath, 'utf8'));
const pinHeaders = pinRows.shift();
const historicPins = new Map(pinRows.map((row) => Object.fromEntries(pinHeaders.map((header, i) => [header || `H${i + 1}`, row[i] || '']))).map((row) => [norm(row.Projects), row]));

function matchProject(rawName) {
  if (exact.has(norm(rawName))) return { project: exact.get(norm(rawName)), confidence: 'exact' };
  const wantedZip = zip(rawName);
  const candidates = projects.filter((project) => !wantedZip || zip(project.full_name) === wantedZip);
  let best = null;
  for (const project of candidates) {
    const score = similarity(rawName, project.full_name);
    if (!best || score > best.score) best = { project, score };
  }
  return best && best.score >= 0.72 ? { project: best.project, confidence: 'fuzzy' } : { project: null, confidence: 'unmapped' };
}
function legacyProject(rawName) {
  const wantedZip = zip(rawName);
  const candidates = legacyProjects.filter((project) => !wantedZip || zip(project.full_name) === wantedZip);
  let best = null;
  for (const project of candidates) {
    const score = norm(rawName) === norm(project.full_name) ? 1 : similarity(rawName, project.full_name);
    if (!best || score > best.score) best = { project, score };
  }
	// Legacy catalog is used only as a CSV fallback. ZIP is already constrained,
	// so accept a conservative partial name match when package/name punctuation changed.
	if (!best) return null;
	const sharedTokens = [...tokens(rawName)].filter((token) => tokens(best.project.full_name).has(token)).length;
	return best.score >= 0.50 || (candidates.length === 1 && sharedTokens >= 3) ? best.project : null;
}

const output = [];
let exactCount = 0; let fuzzyCount = 0; let unmappedCount = 0; let actionCount = 0; let pinCount = 0;
for (const row of source) {
  const match = matchProject(row.Projects);
  if (match.confidence === 'exact') exactCount += 1;
  if (match.confidence === 'fuzzy') fuzzyCount += 1;
  if (!match.project) {
    const historicPin = historicPins.get(norm(row.Projects));
    const legacy = historicPin ? null : legacyProject(row.Projects);
		const manualProjectId = manualProjectIds.get(norm(row.Projects)) || 0;
    unmappedCount += 1;
    const due = csvDueDate(row['Due Date']);
		output.push({ record_kind: 'csv_pin', match_confidence: historicPin ? 'historic_pin' : (legacy ? 'legacy_wpm' : (manualProjectId ? 'manual' : 'unmapped')), project_id: historicPin ? historicPin.project_id : (legacy ? legacy.id : manualProjectId), action_task_id: '', website: row.Website, projects: row.Projects, layout_web: row['Layout web'], member: row.Member, date: due.date, time: due.time, source_row: source.indexOf(row) + 2 });
    pinCount += 1;
    continue;
  }
  const actions = actionRows(match.project);
  if (!actions.length) {
    const due = csvDueDate(row['Due Date']);
    output.push({ record_kind: 'csv_pin', match_confidence: match.confidence, project_id: match.project.id, action_task_id: '', website: row.Website, projects: row.Projects, layout_web: row['Layout web'], member: row.Member, date: due.date, time: due.time, source_row: source.indexOf(row) + 2 });
    pinCount += 1;
    continue;
  }
  for (const task of actions) {
    const extra = task.extra_data || {}; const due = dateTime(task.end_date || task.due_date || task.due_at);
    output.push({ record_kind: 'action_design', match_confidence: match.confidence, project_id: match.project.id, action_task_id: task.id, website: extra.web_demo_url || match.project.domain_url || row.Website, projects: match.project.full_name || row.Projects, layout_web: extra.web_layout || match.project.web_layout || row['Layout web'], member: (task.assignee && task.assignee.name) || (match.project.assignee && match.project.assignee.name) || row.Member, date: due.date, time: due.time, source_row: source.indexOf(row) + 2 });
    actionCount += 1;
  }
}
const columns = ['record_kind', 'match_confidence', 'project_id', 'action_task_id', 'website', 'projects', 'layout_web', 'member', 'date', 'time', 'source_row'];
fs.mkdirSync(path.dirname(outputPath), { recursive: true });
fs.writeFileSync(outputPath, `${columns.join(',')}\n${output.map((row) => columns.map((column) => escape(row[column])).join(',')).join('\n')}\n`, 'utf8');
const pinOutputPath = outputPath.replace(/\.csv$/i, '-pin-import.csv');
const pinColumns = ['Website', 'Projects', 'Layout web', 'Member', 'project_id', 'date', 'time'];
const importPins = output.filter((row) => row.record_kind === 'csv_pin' && row.project_id).map((row) => ({ Website: row.website, Projects: row.projects, 'Layout web': row.layout_web, Member: row.member, project_id: row.project_id, date: row.date, time: row.time }));
fs.writeFileSync(pinOutputPath, `${pinColumns.join(',')}\n${importPins.map((row) => pinColumns.map((column) => escape(row[column])).join(',')).join('\n')}\n`, 'utf8');
console.log(JSON.stringify({ source_rows: source.length, output_rows: output.length, exact_matches: exactCount, fuzzy_matches: fuzzyCount, unmapped: unmappedCount, action_design_rows: actionCount, csv_pin_rows: pinCount, importable_pin_rows: importPins.length, pin_import_path: pinOutputPath }, null, 2));
