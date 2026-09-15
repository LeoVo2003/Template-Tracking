/*
 * Build the one-time authoritative roster from:
 *   1) the historic CSV baseline, and
 *   2) the current WPM Action Design report.
 *
 * WPM Action Design wins when a project exists in both sources. Two known
 * WPM test projects are deliberately excluded. This script is intentionally
 * explicit so the 598-record handover can always be reproduced.
 */
const fs = require('fs');
const path = require('path');

const [baselinePath, reportPath, outDir] = process.argv.slice(2);
if (!baselinePath || !reportPath || !outDir) {
  throw new Error('Usage: node tools/build-authoritative-roster.js <baseline.csv> <action-report.html> <output-directory>');
}

const TEST_PROJECT_IDS = new Set(['3189', '3018']);
const columns = ['record_kind', 'match_confidence', 'project_id', 'action_task_id', 'website', 'projects', 'layout_web', 'member', 'date', 'time', 'source_row'];

function parseCsv(text) {
  const rows = []; let row = []; let cell = ''; let quoted = false;
  for (let index = 0; index < text.length; index += 1) {
    const char = text[index];
    if (quoted) {
      if (char === '"' && text[index + 1] === '"') { cell += '"'; index += 1; }
      else if (char === '"') quoted = false;
      else cell += char;
    } else if (char === '"') quoted = true;
    else if (char === ',') { row.push(cell); cell = ''; }
    else if (char === '\n') { row.push(cell.replace(/\r$/, '')); rows.push(row); row = []; cell = ''; }
    else cell += char;
  }
  if (row.length || cell) { row.push(cell); rows.push(row); }
  const headers = rows.shift();
  return rows.filter((item) => item.some((value) => value.trim())).map((item) => Object.fromEntries(headers.map((header, index) => [header, item[index] || ''])));
}

function parseReport(html) {
  const prefix = 'const DATA = ';
  const start = html.indexOf(prefix);
  if (start < 0) throw new Error('Cannot locate DATA payload in the WPM Action Design report.');
  const jsonStart = start + prefix.length;
  let depth = 0; let quoted = false; let escaped = false;
  for (let index = jsonStart; index < html.length; index += 1) {
    const char = html[index];
    if (quoted) {
      if (escaped) escaped = false;
      else if (char === '\\') escaped = true;
      else if (char === '"') quoted = false;
      continue;
    }
    if (char === '"') quoted = true;
    else if (char === '{') depth += 1;
    else if (char === '}') {
      depth -= 1;
      if (depth === 0) return JSON.parse(html.slice(jsonStart, index + 1));
    }
  }
  throw new Error('WPM Action Design report contains an incomplete DATA payload.');
}

function bangkokDateTime(value) {
  if (!value) return { date: '', time: '' };
  const instant = new Date(value);
  if (Number.isNaN(instant.getTime())) return { date: '', time: '' };
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Asia/Bangkok', day: '2-digit', month: '2-digit', year: 'numeric',
    hour: '2-digit', minute: '2-digit', hour12: false,
  }).formatToParts(instant);
  const pick = (type) => parts.find((part) => part.type === type).value;
  return { date: `${pick('day')}/${pick('month')}/${pick('year')}`, time: `${pick('hour')}:${pick('minute')}` };
}

function csv(value) {
  const text = value == null ? '' : String(value);
  return /[",\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

function write(fileName, rows) {
  const output = `${columns.join(',')}\n${rows.map((row) => columns.map((column) => csv(row[column])).join(',')).join('\n')}\n`;
  fs.writeFileSync(path.join(outDir, fileName), output, 'utf8');
}

const baseline = parseCsv(fs.readFileSync(baselinePath, 'utf8'));
const report = parseReport(fs.readFileSync(reportPath, 'utf8'));

// Historic CSV has two exact duplicate IDs. Keep their first occurrence so a
// project is represented once before applying WPM precedence.
const baselineById = new Map();
for (const row of baseline) {
  const id = String(row.project_id || '').trim();
  if (id && !baselineById.has(id)) baselineById.set(id, row);
}

const actionRows = [];
// The report intentionally keeps its all-project list lightweight. Full task
// metadata used for the tracker lives in action_projects.
for (const project of report.action_projects || []) {
  const projectId = String(project.id || '');
  if (TEST_PROJECT_IDS.has(projectId) || Number(project.action_count || 0) < 1) continue;
  const action = (project.actions || [])[0];
  if (!action) throw new Error(`Action Design details are missing for project ${projectId}.`);
  if (Number(project.action_count) !== 1) throw new Error(`Project ${projectId} has ${project.action_count} Action Designs; review it before creating a roster.`);
  const due = bangkokDateTime(action.end_date || action.due_date || action.due_at || action.completed_at);
  actionRows.push({
    record_kind: 'action_design',
    match_confidence: 'wpm_action_design',
    project_id: projectId,
    action_task_id: action.id || '',
    website: action.web_demo_url || '',
    projects: project.full_name || project.name || '',
    layout_web: action.web_layout || '',
    member: project.assignee || '',
    date: due.date,
    time: due.time,
    source_row: 'WPM Action Design report',
  });
}

const actionIds = new Set(actionRows.map((row) => row.project_id));
const csvOnlyRows = [...baselineById.values()]
  .filter((row) => !actionIds.has(String(row.project_id)))
  .map((row) => ({ ...row, record_kind: 'csv_pin', action_task_id: '' }));
const roster = [...csvOnlyRows, ...actionRows]
  .sort((left, right) => Number(right.project_id) - Number(left.project_id));

fs.mkdirSync(outDir, { recursive: true });
write('authoritative-roster-598.csv', roster);
write('authoritative-wpm-action-design-299.csv', actionRows);
write('authoritative-csv-pin-only-299.csv', csvOnlyRows);

const overlap = [...baselineById.keys()].filter((id) => actionIds.has(id)).length;
console.log(JSON.stringify({
  baseline_source_rows: baseline.length,
  baseline_unique_projects: baselineById.size,
  action_projects_in_report: actionRows.length,
  excluded_test_projects: [...TEST_PROJECT_IDS],
  overlap_wpm_wins: overlap,
  csv_pin_only: csvOnlyRows.length,
  wpm_action_only: actionRows.length - overlap,
  final_visible_projects: roster.length,
}, null, 2));
