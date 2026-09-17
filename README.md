# MAC Project Tracker

WordPress admin plugin for synchronizing immutable project snapshots from WPM, tracking one template-color source per snapshot, reviewing 4-6 proposed colors, and showing only approved palettes.

## Current base (UI + sync foundation)

- PHP 7.4-compatible WordPress plugin with idempotent `dbDelta` migration.
- Immutable project snapshots, color-record table, CSV pin table, and sync logs.
- Full WPM pagination with the fixed `Tracking-Template-Header` header.
- CSV pins are the verified historical fallback. When WPM has a done `[Website] Action Design` for the same project, its task snapshot is shown instead of the duplicate CSV row. Domain is not a snapshot type.
- Every observed done `[Website] Action Design` task creates its own row; multiple valid tasks create multiple snapshots.
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

Version `0.7.7` uses the Action Design due date for task Date/Time, keeps CSV Date/Time for historical fallback rows, and adds a direct **Check updates** action on WordPress Plugins. Activation performs no external request and imports no demo data.

## GitHub releases and auto-update

The public source repository is `https://github.com/LeoVo2003/Template-Tracking`. Pushing a tag such as `v0.7.0` builds a `mac-project-tracker-v0.7.0.zip` asset and creates a GitHub Release. The plugin checks the newest public release through the native WordPress update system.

Installations on `0.2.0` have no updater; installations on older `0.7.x` may not run the original Update URI hook. Both must be upgraded manually once to `0.7.5`. After that bootstrap, future releases show the normal WordPress **Update now** button.

## Visual Tone automation

The repository workflow **Capture visual tone** runs in GitHub Actions, not on the WordPress host or a local computer. It captures a homepage as a full-page JPEG and uploads it to the site's Media Library. Phase 3 stores deterministic, area-weighted UI evidence from that same render: large structural surfaces (sections, header/footer, panels and buttons) carry the signal while photos/media, body text and tiny icons are excluded or down-weighted. The evidence includes direct color coverage, light/dark/cream ratios, saturation, luminance, warm/cool tendency, contrast, dominant structural colors and a candidate tone. AI can use this candidate as a review signal, but it is not treated as ground truth.

Before the first run, save an **Automation shared secret** in MAC Tracker → Settings and add these GitHub repository Secrets:

- `MAC_TRACKER_SITE_URL` — the site root, for example `https://quan.macmarketing.us`
- `MAC_TRACKER_AUTOMATION_SECRET` — exactly the same shared secret saved in the plugin
- `GROQ_API_KEY` — free Groq access for the primary Qwen vision check
- `GEMINI_API_KEY` — free Gemini access used only as an independent quality judge
- `CLOUDFLARE_ACCOUNT_ID`
- `CLOUDFLARE_API_TOKEN` — free Workers AI fallback for Qwen availability

The workflow is hard-locked to `FREE_ONLY=true`: it tries Groq Qwen, then the Cloudflare Qwen fallback, then Gemini as the independent judge. A `429` or quota error advances only to the next configured free provider. If every free provider is exhausted or unavailable, the card moves to `retry_wait` (or `needs_review` when an answer is ambiguous); it never selects a paid model automatically.

The workflow has a twice-hourly schedule, but every scheduled run first reads the private Visual Tone config from WordPress. It exits without claiming a single item unless the Visual Tone mode is **Auto**. A manual **Run batch now** dispatch works in either mode. Retries use bounded backoff (5 minutes, 30 minutes, then 6 hours); protected/error pages such as Cloudflare challenges are marked blocked instead of being sent to AI.

## Visual Tone benchmark gate

`data/visual-tone-gold.json` is the manual gold set for the Visual Tone benchmark. It intentionally starts with only the screenshots already reviewed by a person; a null label is not counted. Review at least 30 varied entries before running **Benchmark visual tone** in GitHub Actions. The workflow captures and classifies each reviewed site twice, uploads a report artifact, and passes only when capture success is at least 95%, no blocked/error page is classified, repeatability is at least 95%, and gold-label accuracy is at least 90%. Until that report passes, keep Visual Tone in **Manual** mode.

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
		  "due_date": "2026-07-15T10:00:00Z",
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
