# Prompt build lại — MAC Project Tracker

> File này là **PROMPT** để một AI agent build  plugin 
> Nó chỉ mô tả **sản phẩm + luồng**, KHÔNG chứa code, schema SQL hay logic cài đặt chi tiết.
> Agent đọc xong phải **tự hỏi lại** những điểm chưa rõ trước khi code.

---

## Quy trình rebuild bắt buộc

Đây là kế hoạch xây lại theo phase. Mỗi phase phải hoàn thành và có acceptance test trước khi chuyển sang phase tiếp theo. Không xóa database production, không reset snapshot production, và không đụng secret. Database local/test có thể reset sau khi backup.

### Phase 0 — Freeze và audit

- Đọc `QWEN-HANDOFF.md`, các spec/note và toàn bộ runtime hiện tại.
- Ghi lại version, tables, constants, API fields, cron, số pin/snapshot và known issues.
- Baseline hiện tại cần kiểm tra là **598 project tổng**, gồm **342 project từ CSV pin** và **256 project từ WPM API**. Không dùng số 3.766 trong raw JSON cũ làm số dashboard.
- Tạo fixture JSON nhỏ cho project cũ, project mới, nhiều Action Design task, task từng done rồi cancelled, và pin bị thiếu khỏi API list.
- Tạo branch rebuild riêng; không reset thay đổi chưa đọc.

**Acceptance:** audit và fixture có thể chạy độc lập; không có secret trong source/fixture/log.

### Phase 1 — Contract và normalization

- Chốt mapping WPM project/task → local fields.
- Hỗ trợ response raw array, `data`, `projects`; chạy đủ pagination (`total_pages` hoặc `total_page`).
- Normalize ID, name, zipcode, package, assignee, domain, layout, task status và completed time.
- Normalize domain bằng lowercase, bỏ protocol, `www.`, path và trailing slash.

**Acceptance:** fixture được normalize thành một cấu trúc nội bộ ổn định, không phụ thuộc tên field thay thế.

### Phase 2 — Database và immutable snapshot

- Thiết kế bảng projects, color records, pins, sync logs và mapping file.
- Snapshot giữ vĩnh viễn sau cancelled/deleted/archived/status change.
- Unique Action Design snapshot bằng `(wpm_project_id, record_kind, wpm_action_task_id)`.
- `csv_pin` là snapshot riêng; approved palette không bị sync ghi đè.
- Lưu UTC, hiển thị Bangkok GMT+7.
- Thiết kế query/list để trang Projects đọc snapshot đã lưu bằng một bulk query, không có N+1 query theo từng record.

**Acceptance:** migrate/idempotent, không duplicate; test status change vẫn giữ snapshot và palette approved.

### Phase 3 — WPM Full Sync

- Đồng bộ tất cả page, không dừng ở page 1.
- Chỉ xử lý project ID từ `3006` trở đi.
- Era mới từ `3707`: chỉ tạo snapshot khi task type đúng `[Website] Action Design`, status done/completed/complete, completed date `>= 2026-07-01`.
- Một project có nhiều task hợp lệ thì tạo snapshot theo từng task.
- Log page/count/error/duration.
- Full Sync không được chạy đồng bộ trong request mở trang Projects; phải chạy manual background/WP-Cron và ghi kết quả vào local DB.

**Acceptance:** fixture 2.100+ project đọc đủ; nhiều task tạo đúng nhiều snapshot; task đổi status không xóa snapshot.

### Phase 4 — CSV pin và rotating backfill

- Import CSV baseline gồm project ID, Website, Layout, Assignee, Date, Time.
- Giữ pin dù WPM list/detail không còn trả project.
- Detail endpoint: `GET /api/v1/tracking-template/projects/{id}`.
- Giới hạn 100–150 detail request mỗi run để tránh timeout.
- Lưu cursor trong DB; mỗi run bắt đầu sau cursor, wrap về đầu, success/miss đều tính quota.

**Acceptance:** 342 pin thiếu list được attempted hết sau tối đa 3 run với quota 150; 404 không làm cursor kẹt.

### Phase 5 — Color text pipeline

- Parse mã HEX từ text; chỉ `pending` khi có 4–6 màu hợp lệ.
- Ảnh/Drive/OneDrive/SharePoint/prnt.sc/direct image/text lẫn ảnh → `waiting` cho tới worker xử lý.
- Tối đa 6 màu; mỗi snapshot một Color Record; approved immutable.

**Acceptance:** parse được `#123456` và `123456`, dedupe màu, không tạo duplicate khi re-sync.

### Phase 6 — Match `csv_pin` với Action Design theo domain

- Cùng WPM project ID → tìm task `[Website] Action Design` đã done.
- Exact host match giữa `csv_pin.website_url` và `task.extra_data.web_demo_url` sau normalize.
- Nhiều match: chọn completed time gần Date/Time CSV; không có time thì dùng rule deterministic.
- Không match thì `waiting/unmatched`, không fallback màu project chung.
- Color Review phải include `csv_pin`, hiển thị một lần với `Task #ID · Action Design · domain`.

**Acceptance:** task domain `a.com` không thể cung cấp palette cho pin domain `b.com`; approve xong re-sync không đổi màu.

### Phase 7 — OneDrive/SharePoint resolver

WPM vẫn giữ Google Drive URL cũ; không sửa dữ liệu tổng công ty. IT đã upload lại folder/ảnh, nên hệ thống tracker tự tìm và lưu mapping local.

- Scan vùng OneDrive/SharePoint được cấp quyền, lấy tên/path/parent/item ID/web URL.
- Match ưu tiên WPM project ID, task ID, domain, zipcode, tên tiệm và folder cha.
- Một candidate rõ ràng thì tự lưu mapping; nhiều candidate thì đưa admin chọn một lần; không đoán.
- Detect `1drv.ms`, `onedrive.live.com`, `*.sharepoint.com`, `*.my.sharepoint.com`.
- Link Google cũ chết → `waiting` với lý do, không làm fail toàn bộ sync.
- Khuyến nghị SharePoint team library; private access dùng Microsoft Graph OAuth/app permission. Anonymous view link chỉ dùng khi policy công ty cho phép.
- Không lưu signed download URL dài hạn; mỗi lần tải resolve lại. Không đưa credential cho Gemini.

**Acceptance:** OneDrive image/folder được resolve/download đúng; mapping dùng lại ở lần sync sau; private image không public.

### Phase 8 — Image/OCR/Gemini worker

- Download ảnh về storage private tạm.
- Kiểm MIME/size; OCR HEX text; trích swatch bằng Imagick/GD/OpenCV.
- Có thể gửi ảnh local cho Gemini để nhận diện vùng màu/tên màu.
- Kết hợp candidate, dedupe, giới hạn 6; đủ 4–6 → `pending`, confidence thấp → `waiting/manual_review`.
- Xóa file tạm sau xử lý; retry/error log.

**Acceptance:** ảnh có HEX ưu tiên HEX; ảnh chỉ có swatch tạo candidate; lỗi Gemini không mất record.

### Phase 9 — Admin UI

- Trang Projects mở lên phải render dữ liệu snapshot hiện có ngay từ local DB trong một lần tải. Không gọi WPM API trong page load, không chờ sync mới được xem dữ liệu.
- Không dùng kiểu AJAX từng record, load 8 dòng rồi thêm 10 dòng, hoặc gọi một request cho mỗi palette/layout. Nếu cần AJAX cho filter thì mỗi thao tác chỉ dùng một bulk request trả về cả tập rows.
- Khi sync đang chạy, giữ nguyên rows cũ và hiển thị badge `Syncing`/last sync; sau khi sync xong người dùng có thể refresh hoặc nhận một lần cập nhật bulk.
- Với baseline hiện tại 598 project, mặc định trả đủ 598 rows trong initial response/local query; pagination chỉ là lựa chọn khi người dùng bật.
- Dashboard: counts, sync, log, trạng thái last/next run.
- Projects mặc định hiển thị full rows một lần; tùy chọn All/50/100/200, filter và sort.
- Cột: `ID | Project | Website | Layout | Assignee | Palette`.
- ID link WPM; Layout template hiển thị `demo-f03 - home 02` nhưng mở URL thật.
- Color Review có pending/waiting/approved/unmatched; approve một lần và khóa.

**Acceptance:** không có load 8 dòng rồi thêm 10; csv_pin review đúng một lần; approved palette hiện dashboard.

### Phase 10 — Cron, recovery và security

- Manual sync chạy background; WP-Cron hourly fallback; lock chống chạy đồng thời.
- Retry hữu hạn, lỗi từng project không rollback toàn bộ sync.
- Encrypt WPM/Gemini/Microsoft secrets bằng WP salts; không log/header HTML/Git.
- PHP 7.4, WordPress >=6.5.

**Acceptance:** hai sync đồng thời không duplicate/lock vĩnh viễn; secret scan sạch.

### Phase 11 — GitHub release và rollout

- Repo: `https://github.com/LeoVo2003/Template-Tracking`, branch `main`.
- Bump header + `MAC_TRACKER_VERSION` cùng nhau.
- Tag `vX.Y.Z` phải khớp version; GitHub Actions build ZIP có đúng một root `mac-project-tracker/`.
- Không commit raw JSON/CSV/output/token/.env/.pem/.key.
- Test local fixture → `leovo.id.vn` → `quan.macmarketing.us`.

**Acceptance:** release ZIP cài được, updater nhận version mới, staging chạy Full Sync/Color Review ổn định trước production.

### Definition of Done

Chỉ kết thúc khi toàn bộ phase đạt acceptance: pagination đầy đủ, snapshot/palette immutable, pin backfill không bỏ sót, domain match chính xác, OneDrive resolver hoạt động, ảnh tạo pending đúng, UI đủ dữ liệu, cron có recovery, và GitHub auto-update đã test trên staging.

---

## 1. Vai trò của bạn

Bạn là lập trình viên WordPress plugin. Nhiệm vụ: **xây plugin admin từ đầu**  có tên
"MAC Project Tracker" theo mô tả bên dưới.

Việc đầu tiên KHÔNG phải là code. Việc đầu tiên là:
1. Đọc hết prompt này.
2. Liệt kê những điểm chưa rõ / còn thiếu thông tin.
3. **Hỏi lại** trước khi bắt đầu.

---

## 2. Sản phẩm là gì

Một plugin **chỉ chạy trong trang admin WordPress**, dùng nội bộ cho team MAC. Mục đích:

- Đồng bộ project từ hệ thống **WPM (CRM)** về database local của WordPress.
- Kết hợp với danh sách **"pin"** (các tiệm/project đã chốt thủ công) import từ **file CSV**.
- Theo dõi và **duyệt bảng màu (color palette)** cho từng project.
- Hiển thị một bảng theo dõi gọn, và chỉ dùng palette **đã được duyệt**.

Không có phân quyền nghiệp vụ phức tạp: user WordPress có quyền admin thì được duyệt.

---

## 3. Nguồn dữ liệu

- **WPM REST API**: trả danh sách project kèm tasks, có phân trang. Plugin cần cấu hình
  URL endpoint + secret, xác thực bằng một header cố định. Secret phải được mã hóa khi lưu.
- **CSV pin baseline**: danh sách project đã chốt, mỗi dòng có `project_id` map sang WPM,
  kèm Website / Layout / Assignee / ngày giờ hoàn thành.
- **Nguồn màu (`color_template`)** nằm trong task: có thể là text chứa mã HEX, ảnh trực tiếp,
  link Google Drive (file/folder), hoặc link prnt.sc.

---

## 4. Khái niệm cốt lõi

- **Snapshot**: mỗi dòng trong bảng theo dõi là một "ảnh chụp" **bất biến** tại thời điểm tạo.
  Các lần sync sau chỉ được cập nhật vài field nhãn (tên / zip / package); phần còn lại giữ nguyên.
- Một project WPM **có thể có nhiều snapshot** (ví dụ mỗi task hoàn thành tạo một dòng).
- **Pin**: project đã chốt từ CSV. Pin **không được mất**, kể cả khi API WPM không còn trả về
  project đó (đã bị hủy/xóa).

---

## 5. Các màn hình admin cần có

- **Dashboard**: các thẻ số liệu + nút Sync + log hoạt động sync.
- **Projects**: bảng theo dõi chính, có filter, sort, phân trang.
- **Pin import**: upload CSV pin + công cụ xóa/chặn vĩnh viễn một project khỏi local.
- **Color Review**: màn duyệt palette (chờ xử lý → chờ duyệt → đã duyệt/khóa).
- **Settings**: cấu hình WPM API (URL, kiểu xác thực, secret) + khóa/model Gemini.

---

## 6. Luồng chính (chỉ mô tả, không code)

- **Full Sync**: đọc **hết tất cả trang** API, tạo mới / cập nhật snapshot theo quy tắc nghiệp vụ.
- **Pin backfill**: pin nào không có trong danh sách API thì gọi endpoint chi tiết theo từng ID.
  Có **giới hạn số request mỗi lần chạy**, và phải **xoay vòng** để qua nhiều lần sync mọi pin
  thiếu đều được xử lý, không pin nào bị bỏ sót mãi.
- **Color**: bắt nguồn màu → phân loại nguồn →
  - Nếu là text HEX rõ ràng và đủ số màu hợp lệ → đưa vào trạng thái **chờ duyệt**.
  - Nếu là ảnh / Drive / prnt.sc / link lẫn text → trạng thái **chờ xử lý ảnh**
    (sau này mới tải ảnh + trích xuất màu).
  - Duyệt **một lần** rồi **khóa** palette, sync sau không ghi đè.

---

## 7. Quy tắc nghiệp vụ phải giữ (chưa rõ thì HỎI LẠI)

- Chỉ quan tâm project từ một **mốc ID** trở đi. Project ở "era mới" chỉ hiện khi có task loại
  `[Website] Action Design` đã **done** và hoàn thành **sau một mốc ngày**.
- Snapshot **đã tạo thì giữ vĩnh viễn**; không xóa/ẩn khi project/task sau đó bị hủy, xóa,
  archive hay đổi trạng thái.
- Palette **đã duyệt thì không được ghi đè**.
- **Không hard-code** secret/token trong code, log hay HTML; phải mã hóa trước khi lưu.
- **Giờ hiển thị theo Bangkok (GMT+7)**, nhưng **lưu trữ theo UTC**.
- Với project đã pin: phải chọn **đúng task** (ví dụ khớp theo domain) để lấy màu, không lấy
  bừa màu của task/project khác.

---

## 8. Ràng buộc kỹ thuật

- **PHP 7.4** — không dùng cú pháp PHP 8+.
- **WordPress tối thiểu 6.5**.
- Có cơ chế **auto-update qua GitHub Release** + GitHub Actions tự build file ZIP cài đặt
  (ZIP có một thư mục gốc duy nhất trùng tên plugin).

---

## 9. Cách bạn (agent) làm việc

- **Không bịa** thông tin: tên trường API, mốc ID, mốc ngày, số màu hợp lệ, header xác thực,
  quy tắc chọn task theo domain... nếu prompt chưa nêu rõ → **hỏi lại**.
- Đề xuất **cấu trúc file + schema database** trước, chờ xác nhận rồi mới viết code.
- Luôn tôn trọng các quy tắc "không được làm sai" ở **mục 7**.
- Giữ code tương thích PHP 7.4 và không lộ credential.

---

## 10. Nguồn tham khảo cũ (khi cần chi tiết hơn)

Prompt này là bản tổng hợp rút gọn. Nếu cần chi tiết nghiệp vụ đầy đủ, tham khảo thêm các file
trong repo (có thể cũ hơn, khi mâu thuẫn thì ưu tiên hỏi lại):

- `SPEC-TAI-TAO-0.6.0.md` — spec tái tạo chi tiết nhất.
- `QWEN-HANDOFF.md` — nghiệp vụ + việc ưu tiên đã chốt.
- `TONG-HOP.md` — tổng hợp yêu cầu + mapping dữ liệu.
- `hướng đi mới.md` — logic snapshot + UI.
- `map-wpm-id-vao-csv.md` — quy trình map project_id vào CSV.
- `README.md` — mô tả plugin + hướng dẫn cài đặt/release.
