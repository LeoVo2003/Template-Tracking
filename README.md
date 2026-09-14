# MAC Project Tracker

WordPress admin plugin for synchronizing immutable project snapshots from WPM, tracking one template-color source per snapshot, reviewing 4-6 proposed colors, and showing only approved palettes.

## Current base (Phase 2–4)

- PHP 7.4-compatible WordPress plugin with idempotent `dbDelta` migration.
- Immutable project snapshots, color-record table, CSV pin table, and sync logs.
- Full WPM pagination with the fixed `Tracking-Template-Header` header.
- IDs below `3006` are ignored for WPM snapshots; IDs `3006–3706` create one legacy row.
- From ID `3707`, only done `[Website] Action Design` tasks completed on/after `2026-07-01` create rows; multiple valid tasks create multiple snapshots.
- Existing snapshots are never deleted or overwritten; only display labels are refreshed.
- Exact baseline CSV headers are supported and date/time are stored as UTC.
- Missing pins use a rotating detail backfill cursor with a 150-request quota per run.
- `list_snapshots()` reads project + color fields with one bulk query for the future Projects page.

The admin UI, color extraction, OneDrive resolver, and background queue are intentionally later phases.

## Installation

1. Zip the `mac-project-tracker` directory.
2. In WordPress Admin open **Plugins → Add New Plugin → Upload Plugin**.
3. Upload the ZIP and activate **MAC Project Tracker**.
4. Activate the plugin to create the local tables.
5. Until the admin screens are added, call `mac_tracker_import_pin_csv( $path )` and `mac_tracker_run_full_sync( $endpoint, $secret, $filters )` from a trusted WordPress runner. Never put the secret in Git or page HTML.

Version `0.2.0` is the rebuild foundation; activation performs no external request and imports no demo data.

## GitHub releases

The public source repository is `https://github.com/LeoVo2003/Template-Tracking`. Automatic updater and release ZIP workflow are deferred until the rebuilt admin/runtime is complete (Phase 11).

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

1. Add the admin Settings/Dashboard/Projects screens with one bulk local query.
2. Add Action Design → `csv_pin` domain matching and Color Review.
3. Add private OneDrive/SharePoint mapping and image/OCR/Gemini workers.
4. Move Full Sync/backfill to a locked background queue and then add GitHub release automation.
