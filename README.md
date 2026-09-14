# MAC Project Tracker

WordPress admin plugin for synchronizing immutable project snapshots from WPM, tracking one template-color source per snapshot, reviewing 4-6 proposed colors, and showing only approved palettes.

## Current base (UI + sync foundation)

- PHP 7.4-compatible WordPress plugin with idempotent `dbDelta` migration.
- Immutable project snapshots, color-record table, CSV pin table, and sync logs.
- Full WPM pagination with the fixed `Tracking-Template-Header` header.
- IDs below `3006` are ignored for WPM snapshots; IDs `3006–3706` create one legacy row.
- From ID `3707`, each observed done `[Website] Action Design` task creates its own row; multiple valid tasks create multiple snapshots.
- Existing snapshots are never deleted or overwritten; only display labels are refreshed.
- Exact baseline CSV headers are supported and date/time are stored as UTC.
- Missing pins use a rotating detail backfill cursor with a 150-request quota per run.
- Dashboard, Projects, Pin import, and Settings are available in WordPress admin.
- Projects reads snapshots in one local bulk query. It never calls WPM or loads rows incrementally.
- Sync Now queues a background WP-Cron job protected by a lock; hourly sync is enabled after WPM connection is saved.

Color extraction/review, OneDrive resolver, and image workers remain later phases.

## Installation

1. Zip the `mac-project-tracker` directory.
2. In WordPress Admin open **Plugins → Add New Plugin → Upload Plugin**.
3. Upload the ZIP and activate **MAC Project Tracker**.
4. Open **MAC Tracker → Settings**, save the HTTPS WPM endpoint and `Tracking-Template-Header` value.
5. Open **Pin import** to upload the baseline CSV, then click **Sync now** from Dashboard or Projects.

Version `0.7.1` validates the exact WPM list route, adds a read-only connection test, and refreshes the admin as a local-cache operations ledger. Activation performs no external request and imports no demo data.

## GitHub releases and auto-update

The public source repository is `https://github.com/LeoVo2003/Template-Tracking`. Pushing a tag such as `v0.7.0` builds a `mac-project-tracker-v0.7.0.zip` asset and creates a GitHub Release. The plugin checks the newest public release through the native WordPress update system.

The site currently on `0.2.0` must be upgraded manually once to `0.7.0`, because `0.2.0` has no updater. After that bootstrap upgrade, future releases (including `0.7.1`) show the normal WordPress **Update now** button.

## Expected WPM response

The plugin accepts a response with a `data` or `projects` array, or a raw JSON list. The preferred shape is:

```json
{
  "data": [
    {
      "id": 12845,
      "name": "Tam NailArt & Spa",
      "zipcode": "75605",
      "package": "SEM PROX4",
      "account_manager": { "id": 12, "name": "Luna Nguyen" },
      "assignee": { "id": 27, "name": "Allen" },
	  "tasks": [
		{
		  "id": 6469,
		  "status": "done",
		  "task_type": { "id": 77, "name": "[Website] Action Design" },
		  "extra_data": {
			"color_template": "https://drive.google.com/drive/folders/xxx",
			"web_demo_url": "https://tamstudio2022.com/"
		  }
		}
	  ],
      "status": "Doing",
      "notes": null,
      "domain_url": "tamstudio2022.com",
      "web_layout": "https://templates.example.com/demo-s05/home/",
	  "template_color_url": null,
      "content_url": null,
      "menu_web_url": null,
      "updated_at": "2026-08-04T03:20:00Z",
      "is_archived": false
    }
  ],
  "pagination": {
    "page_index": 1,
    "page_size": 100,
    "total": 1,
    "total_page": 1
  }
}
```

## Next implementation step

1. Add Action Design → `csv_pin` domain matching and Color Review.
2. Add private OneDrive/SharePoint mapping and image/OCR/Gemini workers.
3. Add GitHub release automation and test staging → production rollout.
