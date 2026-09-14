# MAC Project Tracker — handoff cho Qwen

> Cập nhật: 2026-09-09. Đây là tài liệu chốt nghiệp vụ và hướng triển khai. Ưu tiên tài liệu này khi nó khác các note cũ.

## 1. Mục tiêu hệ thống

Đây là WordPress admin plugin để lấy project từ WPM (CRM), chọn đúng snapshot cần theo dõi, và quản lý template color palette.

Nguồn chính:

- WPM REST API chứa toàn bộ project và tasks.
- CSV baseline chứa các tiệm/project đã pin thủ công.
- `color_template` có thể là HEX text, ảnh trực tiếp, Google Drive folder/file, hoặc prnt.sc.

Dashboard/Projects chỉ cần tập trung vào: **Project, Website, Layout, Assignee, Palette**. Không cần hiển thị Package, Status, Source ở bảng chính.

Không có role nghiệp vụ phức tạp. User WordPress có quyền admin thì review/approve palette.

## 2. Codebase hiện tại

- Plugin version hiện tại: `0.6.17`.
- PHP target: **7.4**. Không dùng cú pháp PHP 8+.
- WordPress tối thiểu: 6.5.
- Main file: `mac-project-tracker.php`.
- Runtime chính:
  - `includes/class-mac-tracker-sync-service.php`: Full Sync và business logic.
  - `includes/class-mac-tracker-repository.php`: SQL/tables/query.
  - `includes/class-mac-tracker-admin.php`: Admin UI/forms.
  - `includes/class-mac-tracker-wpm-client.php`: WPM REST client.
  - `includes/class-mac-tracker-color-service.php`: parse/capture color source.
  - `includes/class-mac-tracker-github-updater.php`: updater GitHub Release.
- CSS: `assets/admin.css`.

GitHub public repo: `https://github.com/LeoVo2003/Template-Tracking`.

Lưu ý: local working tree hiện có thay đổi chưa commit ở `class-mac-tracker-normalize.php` và `class-mac-tracker-time.php`. Không ghi đè hoặc reset các thay đổi đó nếu chưa đọc nội dung.

## 3. WPM API contract

Endpoint list:

```bash
curl --location --request GET 'https://wpm.macusaone.com/api/v1/tracking-template/projects' \
  --header 'Tracking-Template-Header: <WPM_SECRET>' \
  --header 'Content-Type: application/json' \
  --data '{
    "page": 1,
    "per_page": 100,
    "task_type": "[Website] Action Design"
  }'
```

API phải chạy hết tất cả page, không chỉ page 1. Response chấp nhận `data`, `projects`, hoặc JSON array raw; pagination có thể dùng `total_pages` / `total_page` tùy WPM trả về.

Project thường có:

```json
{
  "id": 3707,
  "name": "Example Nail Spa",
  "zipcode": "77084",
  "package": "SE1 PROX3",
  "account_manager": { "name": "AM Name" },
  "assignee": { "name": "Assignee Name" },
  "domain_url": "https://example.com",
  "web_layout": "https://templates.macusaone.com/demo-f03/home-02/",
  "tasks": [
    {
      "id": 12345,
      "status": "done",
      "completed_at": "2026-07-15T10:00:00Z",
      "task_type": { "name": "[Website] Action Design" },
      "extra_data": {
        "web_demo_url": "https://example.com",
        "web_layout": "https://templates.macusaone.com/demo-f03/home-02/",
        "color_template": "#123456 #abcdef #ffffff #222222"
      }
    }
  ]
}
```

Không hard-code token/API key. Plugin lưu secret encrypt trong WordPress options.

## 4. Snapshot rules đã chốt

Constants hiện tại:

```php
MAC_TRACKER_MIN_WPM_PROJECT_ID = 3006;
MAC_TRACKER_ACTION_TASK_ERA_ID = 3707;
MAC_TRACKER_AD_COMPLETED_CUTOFF = '2026-07-01';
```

### 4.1 Project snapshot

- ID `< 3006`: bỏ qua hoàn toàn.
- ID `3006–3706`: có thể tạo project/domain snapshot theo rule legacy nếu có domain.
- ID `>= 3707`: chỉ hiện khi có task `[Website] Action Design` đã `done` và `completed_at >= 2026-07-01`.
- Task đã từng đạt `done` thì snapshot phải được giữ/pin vĩnh viễn; project/task sau đó cancelled, deleted hoặc đổi status cũng **không xóa snapshot đã có**.
- Không quan tâm cancelled để loại snapshot cũ.
- Một project có thể có nhiều Action Design task ⇒ có thể có nhiều Action Design snapshot, key unique là `(wpm_project_id, record_kind, wpm_action_task_id)`.

### 4.2 CSV pin baseline

- File baseline là `data/pinned-baseline.csv` (không đưa lên public Git).
- Mỗi row map tới `wpm_project_id`; plugin tạo `record_kind = csv_pin`.
- `csv_pin` là tiệm cần theo dõi, không được mất dù project không còn xuất hiện ở list API.
- Fields Date/Time trong CSV là done date/time, timezone Bangkok GMT+7; nếu cần đổi về UTC dùng helper `MAC_Tracker_Time`.
- Project label hiển thị format: `{zipcode}{package không khoảng trắng}{name}`. Ví dụ `95678SE1 PROX3 Diamond Nails`.

## 5. UI Projects đã chốt

URL: `wp-admin/admin.php?page=mac-project-tracker-projects`.

- Hiển thị full project mặc định, không kiểu initial 8 row rồi lazy-load 10 row.
- Có thể chọn pagination 50 / 100 / 200 / All.
- Có filter và sort.
- Cột chính theo thứ tự: **ID | Project | Website | Layout | Assignee | Palette**.
- ID phải hiển thị WPM ID và link sang WPM project edit.
- Project row có thể có dòng nhỏ `Domain` hoặc `Task #1234` để phân biệt snapshot.
- Assignee phải dễ nhìn, không gộp/hide vô cột khác.
- `Layout`: nếu source có URL template `templates.macusaone.com/demo-f03/home-02`, hiển thị compact text/link `demo-f03 - home 02` nhưng click mở URL thật.
- Nếu `web_layout` là text/URL khác template host: trích URL nằm trong text nếu có và show link đó; nếu không có URL thì show text sanitized.
- Nhiều layout source không được bị truncate sai kiểu `https://templates...`; ưu tiên compact label đúng rule trên.

Performance rule bắt buộc:

- Projects page phải lấy snapshot từ local DB/cache ngay khi mở; không gọi WPM API trong page load.
- Không load từng record bằng AJAX hoặc kiểu 8 dòng rồi thêm 10 dòng.
- Dùng một bulk query có palette summary để trả initial rows; với baseline hiện tại phải hiển thị sẵn 598 project.
- Sync WPM chạy background/WP-Cron; trong lúc sync, rows cũ vẫn hiện và chỉ thêm trạng thái `Syncing`/last sync.
- Nếu filter/sort cần AJAX, mỗi lần chỉ được gọi một bulk request trả cả tập kết quả, không N+1.

## 6. Color Palette business rules

### 6.1 Source và trạng thái

- Lấy tối đa 6 màu; target hợp lệ là 4–6 màu.
- Nếu source có HEX text rõ ràng: parse HEX ngay. Đủ 4–6 màu ⇒ record `pending` để người duyệt.
- Nếu source là ảnh / Drive / prnt.sc / direct image / link lẫn text: tạo record `waiting`.
- Màu từ ảnh cần workflow sau: download ảnh private → OCR/extract pixel candidates/Gemini → map kết quả thành 4–6 HEX → `pending`.
- Không cho Gemini truy cập trực tiếp Drive folder. Backend tự download file rồi gửi bytes/local file cho Gemini.
- Khi approve: palette được cố định và hiển thị dashboard. Sync sau không được ghi đè palette approved.
- Không cần tạo record mới khi file Drive thay đổi sau lần approve. Chỉ approve một lần là giữ nguyên.

### 6.2 Rule bắt buộc cho `csv_pin` Color Review

Vấn đề hiện tại:

- Sync có thử lấy palette Action Design cho `csv_pin`.
- Nhưng query Color Review (`list_color_records`/`count_colors`) đang lọc `p.sync_source = 'real'`.
- `csv_pin` có `sync_source = 'pin'`, nên Color Record của pin có thể tồn tại nhưng không hiện để review.

**Không sửa bằng cách đơn giản bỏ toàn bộ filter `sync_source = real`.** Cần match đúng Action Design theo domain để không approve nhầm màu của task khác.

### 6.3 Logic cần triển khai: csv_pin → đúng Action Design → Color Review

```text
csv_pin (wpm_project_id + website/domain)
  → lấy WPM project cùng ID
  → tìm task có task_type.name đúng bằng "[Website] Action Design"
  → chỉ task status done
  → so domain của task.extra_data.web_demo_url với domain của csv_pin
  → chọn đúng 1 task phù hợp
  → dùng task.extra_data.color_template làm source palette
  → capture/update Color Record gắn với local csv_pin snapshot
  → record xuất hiện trên Color Review
  → admin approve
  → palette pinned vĩnh viễn cho csv_pin/dashboard
```

Rule chọn task:

1. Normalize host của `csv_pin.website_url` và `task.extra_data.web_demo_url`: lower-case, bỏ protocol/www/path/trailing slash.
2. Chỉ accept **exact host match**.
3. Nếu nhiều done tasks match cùng domain: ưu tiên `completed_at` gần nhất với `csv_pin.task_completed_at` (Date + Time baseline).
4. Nếu CSV pin không có completed time: dùng task done mới nhất (hoặc chọn một rule deterministic và document rõ).
5. Nếu không match task theo domain: **không fallback** tới `base.template_color_raw` / project palette chung. Giữ `waiting` hoặc trạng thái `unmatched` kèm message để review, vì fallback có thể lấy nhầm palette.
6. Nếu Color Record đã `approved`: không thay source, không thay colors, không tạo duplicate.
7. Một `csv_pin` chỉ có một Color Record; khi chưa approve thì có thể cập nhật source/matching task nếu sync tìm được match tốt hơn.

UI Color Review cho pin:

- Include record `csv_pin` trong query Color Review, không chỉ `sync_source=real`.
- Dòng metadata: `Task #<task_id> · Action Design · <domain>`.
- Không hiển thị task Action Design trùng domain thành một record review riêng song song với csv_pin; tránh hai palette cho một tiệm.
- Record không match domain: show trạng thái rõ `Waiting for matching Action Design task`.

Acceptance criteria:

- Một CSV pin có domain `a.com`, và cùng WPM project có Action Design `a.com`/`b.com`: review chỉ lấy `a.com`.
- `b.com` không thể ảnh hưởng palette `a.com`.
- Approve palette pin xong, full sync nhiều lần vẫn không đổi palette.
- Dashboard hiển thị palette approved của `csv_pin`.
- Không sinh duplicate Color Review records cho một csv_pin.

## 7. Backfill thiếu project từ API list

### Ý nghĩa

WPM list API có thể không trả project cancelled/deleted. Nhưng CSV pin vẫn cần lấy thông tin task/layout/color từ project đó. Plugin gọi detail endpoint từng project:

```text
GET /api/v1/tracking-template/projects/{wpm_project_id}
```

Code hiện có limit `150` request detail mỗi Full Sync.

Điều này **không giới hạn số project hiển thị**. Nó chỉ giới hạn số project pin bị thiếu trong API list được gọi lại từng ID để tránh timeout/rate limit.

### Bug hiện tại

IDs được sort tăng dần; nếu thiếu hơn 150 pin, mỗi sync bắt lại 150 ID đầu. Các ID sau có thể không bao giờ được backfill.

### Hướng sửa bắt buộc

Giữ limit per run để an toàn, nhưng thêm rotating cursor:

1. Lấy danh sách pin thiếu from `list_pinned_project_ids()` sau khi full-list sync.
2. Lưu option, ví dụ `mac_tracker_pin_backfill_cursor`, là WPM ID xử lý cuối cùng.
3. Mỗi run, bắt đầu từ ID lớn hơn cursor, xử lý tối đa 100–150 IDs (success + miss đều tính quota).
4. Đến cuối list thì wrap về đầu list.
5. Sau run lưu cursor theo ID cuối đã attempted.
6. Nếu không có missing pin thì clear cursor hoặc giữ harmless.
7. Log phải nói rõ attempted / success / miss / next cursor.

Acceptance criteria:

- Có 342 pin thiếu list API và quota 150 ⇒ sau tối đa 3 Full Sync, tất cả đều được attempted ít nhất một lần.
- Một ID 404/miss không làm loop kẹt ở ID đó.
- Không vượt quota request detail/run.

## 8. Migration Google Drive → Microsoft 365 / OneDrive

### 8.1 Quyết định kiến trúc

Google Drive cũ đã chết và các URL `drive.google.com` cũ không thể tự chuyển thành OneDrive URL chỉ bằng cách đổi domain. File đã được download rồi upload lại nên item ID/link của Microsoft là hoàn toàn mới.

**Source of truth mới:** field `task.extra_data.color_template` của task `[Website] Action Design` trên WPM phải chứa link OneDrive/SharePoint mới tới đúng file ảnh hoặc folder ảnh.

Không để plugin tự đoán file mới từ Google Drive URL cũ. Mapping phải dựa vào WPM project ID / task ID / domain hoặc manifest migration.

Khuyến nghị lưu file công ty trong **SharePoint Document Library / shared team folder** của Microsoft 365 thay vì OneDrive cá nhân của một nhân viên. OneDrive cá nhân vẫn dùng được, nhưng khi nhân viên đổi/disabled account thì link và quyền có thể lại hỏng.

### 8.2 Hai cách để plugin tải ảnh

#### Option A — link public read-only (nhanh nhất để triển khai)

1. Upload ảnh vào Microsoft 365 shared folder.
2. Tạo sharing link OneDrive loại `view`, scope `anonymous` (Anyone with the link, view only) nếu M365 admin cho phép.
3. Ghi `link.webUrl` đó vào `color_template` trong đúng Action Design task WPM.
4. Plugin detect hostname OneDrive/SharePoint, follow redirect, download bytes ảnh private về server để OCR/Gemini.

Ưu điểm: WPM chỉ cần trả link, WordPress không cần Microsoft credential.

Nhược điểm: ai có URL có thể xem ảnh. Dùng khi ảnh palette không nhạy cảm và M365 tenant cho phép anonymous sharing.

#### Option B — private organization link + Microsoft Graph (khuyến nghị lâu dài)

1. WPM vẫn trả `webUrl` OneDrive/SharePoint trong `color_template`.
2. Tạo Azure App Registration cho tracker.
3. Cấp quyền đọc tối thiểu cho đúng SharePoint site/library/folder (ưu tiên `Sites.Selected`; không dùng full `Files.ReadWrite.All` nếu không cần).
4. Admin Microsoft grant consent và grant app vào site/folder nguồn.
5. WordPress lưu encrypted tenant ID, client ID, client secret/certificate; không lưu trong Git.
6. Plugin resolve sharing URL → Microsoft Graph `driveItem`, rồi download file bằng Graph `/drives/{drive-id}/items/{item-id}/content`.

Ưu điểm: file vẫn private trong company; link bị lộ cũng không cho người ngoài tải.

Nhược điểm: cần Azure/M365 admin setup một lần và phải build Graph OAuth/client-credential integration.

Microsoft Graph hỗ trợ `createLink` với scope `anonymous`, `organization`, hoặc `users`; `organization` chỉ dùng được khi người mở link đăng nhập cùng tenant. Graph cũng hỗ trợ download nội dung của `driveItem`. Xem [Microsoft createLink](https://learn.microsoft.com/en-us/graph/api/driveitem-createlink?view=graph-rest-1.0) và [download driveItem content](https://learn.microsoft.com/en-us/graph/api/driveitem-get-content?view=graph-rest-1.0).

### 8.3 Quy trình migration file đã upload lại

1. Chuẩn hóa folder/file name theo một key bất biến, nên dùng WPM project ID. Ví dụ:

```text
Template Colors/
  3707/
    12345-color-palette.jpg
```

`3707` là WPM project ID, `12345` là Action Design task ID nếu có.

2. Sau khi upload, tạo manifest CSV/JSON:

```csv
wpm_project_id,action_task_id,domain,onedrive_web_url
3707,12345,example.com,https://1drv.ms/...
```

3. Viết một migration/import job đọc manifest và gọi WPM API để update **đúng task**:

```json
{
  "extra_data": {
    "color_template": "https://1drv.ms/..."
  }
}
```

4. Không overwrite task nếu manifest domain/task ID không match; log thành `needs_manual_mapping`.
5. Chạy Full Sync. csv_pin domain-match flow (§6.3) sẽ lấy `color_template` OneDrive từ đúng Action Design task, tạo record `waiting`/`pending`, rồi đưa vào Color Review.

Nếu naming/folder mới đã có project ID, có thể viết script Microsoft Graph quét folder → lấy `driveItem.id` + `webUrl` → sinh manifest tự động. Nếu chỉ có file name ngẫu nhiên và link Google cũ đã chết, cần người map lần đầu bằng tay.

### 8.4 Parser/source type cần thêm

`MAC_Tracker_Color_Service` phải nhận diện thêm các host/URL sau thành source type `onedrive` hoặc `sharepoint`:

- `1drv.ms`
- `onedrive.live.com`
- `*.sharepoint.com`
- `*.my.sharepoint.com`

Record lưu raw OneDrive URL, `source_type`, và metadata (nếu resolve được: `drive_id`, `item_id`, filename, MIME type). Không lưu signed direct-download URL dài hạn vì URL đó có thể hết hạn; mỗi lần cần download thì resolve lại từ `webUrl`/Graph.

### 8.5 Acceptance criteria

- Link Google Drive cũ không làm sync fail; nó được giữ `waiting` với lý do `legacy Google Drive link unavailable`.
- Một Action Design task trả OneDrive link mới ⇒ plugin tạo/reuse color record đúng cho csv_pin cùng domain.
- Với Option A, plugin tải được ảnh qua anonymous view link/redirect mà không lộ image bytes trên dashboard public.
- Với Option B, ảnh private chỉ tải được qua Microsoft Graph token; secret được mã hóa trong WordPress options.
- Approved palette không đổi khi OneDrive file/link sau đó thay đổi.

## 9. GitHub release / WordPress auto-update

Đã làm xong và đang chạy:

- Branch: `main`.
- Current release: `v0.6.17`.
- GitHub Actions: `.github/workflows/release.yml`.
- Push tag `vX.Y.Z` có version khớp header và `MAC_TRACKER_VERSION` ⇒ Actions build ZIP + tạo GitHub Release.
- Release ZIP asset format bắt buộc: `mac-project-tracker-vX.Y.Z.zip`.
- ZIP phải có đúng root folder: `mac-project-tracker/`.
- Plugin native updater fetch GitHub latest release và WordPress có thể auto-update.

Khi có runtime change:

1. Bump cả plugin header `Version:` và constant `MAC_TRACKER_VERSION`.
2. Commit + push main.
3. Create/push tag trùng version, ví dụ `v0.6.18`.

```bash
git push origin main
git tag v0.6.18
git push origin v0.6.18
```

Không commit/push các file sau:

- WPM raw JSON, sample raw project JSON, CSV baseline, output packages.
- API keys, tokens, `.env`, `.pem`, `.key`.
- Internal planning files unless owner chủ động muốn public.

## 10. Non-goals / không được làm sai

- Không xóa snapshots chỉ vì project/task bị cancelled/deleted/archived.
- Không lấy random project palette làm fallback cho `csv_pin` khi domain không match Action Design.
- Không ghi đè palette approved.
- Không expose WPM/Gemini/Google credentials trong source, logs, Git, admin HTML, hoặc release ZIP.
- Không dùng PHP 8 syntax.
- Không reset database hoặc xoá pinned data để migrate, trừ khi owner xác nhận rõ.
- Không auto-zip local `dist` trừ khi cần test release; workflow GitHub là đường release chính.

## 11. Việc ưu tiên cho Qwen

1. Confirm chọn Option A hay Option B của Microsoft 365 (§8.2). Nếu chưa đủ Azure admin access, triển khai Option A trước nhưng giữ interface để nâng cấp Option B.
2. Implement domain-matched Action Design palette flow cho `csv_pin` (§6.3).
3. Sửa Color Review query/count/UI để record `csv_pin` hợp lệ xuất hiện đúng một lần (§6.3).
4. Implement rotating backfill cursor (§7).
5. Add OneDrive/SharePoint source parser and downloader abstraction (§8.4).
6. Bump version lên `0.6.18`, PHP parse/lint, test bằng mock WPM payload có 2 Action Design domains khác nhau, OneDrive source, và >150 missing pins.
7. Commit, push `main`, tag `v0.6.18`; chờ GitHub release success rồi upload/auto-update test trước trên `leovo.id.vn`, sau đó mới dùng ở `quan.macmarketing.us`.
