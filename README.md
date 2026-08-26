# MAC Project Tracker

WordPress admin plugin for synchronizing immutable project snapshots from WPM, tracking one template-color source per snapshot, reviewing 4-6 proposed colors, and showing only approved palettes.

## Current base

- PHP 7.4-compatible WordPress plugin.
- Separate project, color-record, and sync-log tables.
- Full-project WPM response support with nested task selection.
- Configurable single WPM REST list endpoint with pagination and `updated_after`.
- Bearer/JWT or API-key authentication using the fixed `Tracking-Template-Header` header.
- For IDs 3006-3706, creates one pinned domain snapshot when `domain_url` exists; legacy WPM payloads using `domain` are supported as a fallback.
- For every project ID 3006 or newer, each `[Website] Action Design` task observed with `status=done` creates its own pinned snapshot row.
- IDs 3707 or newer do not receive a domain row and remain hidden until an Action Design task is done.
- Existing snapshots survive later project/task cancellation, deletion, archive, or status changes.
- Existing snapshots only refresh zipcode, package, and name; Website, Layout, Assignee, and Palette remain pinned.
- Action snapshots use the exact task's `web_demo_url` and `color_template`.
- Extracts URLs embedded anywhere in `web_layout`; every `templates.macusaone.com` hostname variant displays as a compact link such as `demo-f03 - home 02`.
- API credentials encrypted with the WordPress authentication salts.
- Manual sync plus hourly WP-Cron fallback for real API mode only. Mock data never runs automatically.
- One color record per local snapshot row; approved records are immutable.
- Plain HEX sources immediately become pending when they contain 4-6 colors.
- Drive folder/file, prnt.sc, direct image, and mixed URL sources are classified and left in `waiting` state.
- Gemini image-understanding client accepts only a local image already downloaded by the plugin; it never receives Drive credentials.
- WordPress admin dashboard, color review, and settings screens.
- Projects screen shows all matching rows by default, supports optional database pagination (50, 100, or 200 rows), and keeps filters/sorting across pages.
- Project filters cover search, assignee, palette state, website/layout availability, and Website Action Design task availability.
- Project sorting is performed by clicking the ID, Project, Website, Layout, Assignee, or Palette table header.

## Installation

1. Zip the `mac-project-tracker` directory.
2. In WordPress Admin open **Plugins → Add New Plugin → Upload Plugin**.
3. Upload the ZIP and activate **MAC Project Tracker**.
4. Open **MAC Tracker → Dashboard**.
5. Select **Real WPM REST API**, enter the endpoint and credential, then save.
6. Run **Full sync** once to build the snapshot rows.

Upgrading from 0.1.x to 0.2.0 resets the display-only test Project/Palette rows once, while retaining Settings and sync logs. Activation performs no external request and imports no demo data. Deactivation removes the cron event and any sync lock, while retaining the snapshot tables.

## GitHub releases and automatic WordPress updates

The public source repository is `https://github.com/LeoVo2003/Template-Tracking`.

The plugin includes a PHP 7.4-compatible native updater. It checks the latest public GitHub Release, downloads only a release asset named `mac-project-tracker-vX.Y.Z.zip`, and marks that release for WordPress automatic background update. WordPress still requires working WP-Cron and direct filesystem write access for unattended installation.

Release a version from the local `main` branch:

```bash
git push origin main
git tag v0.6.17
git push origin v0.6.17
```

The tag must match both version values in `mac-project-tracker.php`. GitHub Actions then creates an installable ZIP whose only top-level directory is `mac-project-tracker/` and attaches it to the release. JSON/CSV inputs, local output, credentials, and internal planning files are excluded from Git.

Version `0.6.17` must be uploaded manually once to bootstrap the updater on sites still running `0.6.16`. Future tagged releases can update automatically.

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

1. Add Google Workspace OAuth callback and encrypted refresh-token storage.
2. Parse Drive file/folder IDs and download the one image to private local storage.
3. Feed the local image to Imagick/OpenCV/PaddleOCR and Gemini.
4. Map candidates and save 4-6 final HEX values as `pending`.
5. Test the real WPM endpoint and freeze the response contract.
