# Phase 0 audit — MAC Project Tracker

Audit date: 2026-09-14

## Workspace state

- Active runtime code was intentionally removed before rebuild: no active `.php`, `.css`, or `.github/workflows` files remain.
- Previous implementation is recoverable from `outputs/legacy-code-backup-20260914-115455.zip` and Git history.
- Git repository: `main`, remote `https://github.com/LeoVo2003/Template-Tracking.git`.
- Latest committed implementation before rebuild: `v0.6.18` / commit `bd0a5f9`.
- Working tree contains the intentional deletion of the previous runtime plus local documentation/fixture work. Do not reset this tree.

## Preserved inputs

- Current expected tracker baseline: **598 projects total** = **342 CSV-pinned projects** + **256 projects from WPM API**.
- `all-projects-full.json`: 3,766 raw projects, 10,880,736 bytes; this is an old/raw API dump and must not be used as the current dashboard count.
- `project WPM.json`: 2,008 raw projects, 227,438 bytes.
- `data/pinned-baseline.csv`: 342 rows, including `project_id`, `date`, and `time`; these are the exact pinned projects.
- `sample json.json`: 2 projects in the `data` response shape.
- Product decisions are documented in `BUILD-PROMPT.md` and `QWEN-HANDOFF.md`.

The raw JSON, baseline CSV, internal planning notes, and output files are excluded from the public Git repository.

## Locked business constants

```text
Minimum WPM project ID: 3006
New Action Design era: 3707
Action Design completed cutoff: 2026-07-01
Required task type: [Website] Action Design
Valid palette size: 4–6 colors, maximum 6
Display timezone: Bangkok GMT+7
Storage timezone: UTC
```

## Required invariants

- Full Sync reads every WPM API page.
- A snapshot that was created remains visible after WPM cancellation, deletion, archive, or later status changes.
- Approved colors are immutable.
- A `csv_pin` palette comes only from a done Action Design task with an exact normalized domain match.
- OneDrive/SharePoint is resolved locally; the WPM company data is not edited to repair the dead Google Drive link.
- Ambiguous OneDrive candidates require human selection and are then remembered locally.
- Image bytes are stored in private WordPress file storage; database stores mapping, metadata, hash, and status.
- Secrets never enter source, fixture, logs, HTML, or Git.

## Known rebuild targets

1. Database and repository foundation.
2. WPM client and complete pagination.
3. CSV pin import and rotating detail backfill.
4. Domain-matched Action Design color records.
5. OneDrive/SharePoint index, candidate scoring, and human mapping UI.
6. Private image download, OCR/Gemini extraction, review and approval.
7. Admin UI, background sync, GitHub release and staging rollout.

## Performance decision

The Projects page must render cached local snapshots immediately. It must not call WPM during page load or fetch rows one by one. The current baseline is 598 projects, so the default initial query must return the full cached set in one bulk query; pagination remains an optional user choice. WPM sync runs in the background and leaves existing rows visible while it runs.

## Fixture

`fixtures/phase-0/wpm-fixture.json` covers an ID below the minimum, a legacy pin, two Action Design domains in one project, a cancelled project, and multiple done tasks around the cutoff.

`fixtures/phase-0/pin-fixture.csv` covers an existing pin and a pin intentionally missing from the WPM list for backfill tests.

## Live WPM check

- `fixtures/phase-0/pin-fixture.csv` is byte-for-byte identical to `data/pinned-baseline.csv` (342 rows, SHA-256 match).
- A read-only request to `https://wpm.macusaone.com/api/v1/tracking-template/projects` reached Cloudflare but returned `502 Bad Gateway` from the WPM origin on 2026-09-14.
- The response advised retrying after 60 seconds. No WPM data was changed and no credential was stored.
- Full live export is therefore deferred until the WPM origin is healthy and the `Tracking-Template-Header` secret is available through local configuration.

## Phase 0 result

Phase 0 is complete when the next phase can use the fixture without depending on production WPM, OneDrive, Gemini, or WordPress credentials. The Phase 2–4 data layer and the first admin/runtime UI are now present in the working tree.

## Phase 2–4 implementation checkpoint

- `MAC_Tracker_Activator` creates projects, color records, pins, and sync-log tables with idempotent `dbDelta` migration.
- `MAC_Tracker_Repository` stores immutable composite snapshots and exposes one bulk `list_snapshots()` query for the future Projects page.
- `MAC_Tracker_Pin_Import` accepts the exact baseline CSV headers (`Website,Projects,H1,Member,project_id,date,time`) and stores date/time as UTC.
- `MAC_Tracker_WPM_Client` paginates with `per_page=100` and supports `GET /projects/{id}` for pin backfill.
- `MAC_Tracker_Sync_Service` applies the `3006` cutoff, the `3707` era rule, Action Design + done + `2026-07-01` cutoff, immutable snapshots, and a rotating 150-ID backfill cursor.
- No API secret is stored in source, fixtures, logs, or HTML.

The first admin screens now provide Pin import, Settings, Dashboard and cached Projects. Manual sync is queued through WP-Cron; its background queue is protected by a lock.
