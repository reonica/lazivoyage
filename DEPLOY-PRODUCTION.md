# Bàn giao triển khai lazivoyage.com

Repository: https://github.com/reonica/lazivoyage — nhánh `main`.

Mục tiêu:

- Website: https://lazivoyage.com/
- CMS: https://lazivoyage.com/admin/
- Backend: `/cms/api.php` trên cùng domain.

Repo chứa toàn bộ HTML/CSS/JavaScript, hình ảnh/font hiện tại và mã nguồn PHP của CMS. Website công khai không cần build, Node.js hoặc npm. Netlify chỉ xem được giao diện quản trị; chức năng CMS chạy trên hosting PHP.

## 1. Chuẩn bị hosting

- Backup website và database cũ trước khi thay bản đang phục vụ.
- Trỏ `lazivoyage.com` vào đúng Document Root chứa bộ source mới, bật HTTPS. Chuyển `www` về domain canonical đã chọn.
- Chọn PHP 8.1 trở lên cho cả web và CLI, bật `pdo_sqlite`, `dom`, `session`, khả năng đọc ảnh `getimagesize`.
- Apache/LiteSpeed phải đọc `.htaccess`; cần `mod_headers` và `mod_rewrite` để xử lý headers và chuyển hướng.
- PHP cần quyền ghi vào Document Root để xuất bản HTML và `uploads/cms/` để lưu ảnh. Dùng đúng owner/group, không cấp `777`.
- Kho riêng phải nằm ngoài **mọi** Document Root. Nếu website nằm trong `public_html`, cấu hình `CMS_DATA_DIR` đến thư mục ngoài `public_html`; không đặt SQLite trong vùng công khai của bất kỳ domain nào.

## 2. Đưa đầy đủ source lên server

Tải nhánh `main` từ GitHub hoặc dùng Git checkout. Đưa **nội dung gốc repo** vào Document Root: `index.html`, `style.css`, `script.js`, `assets/`, các thư mục trang con, `admin/`, `cms/`, `data/`, sitemap/RSS/robots và `.htaccess`.

Không để thêm một lớp `lazivoyage-main/` trong Document Root. Không đưa `.git/`, `tests/`, file hướng dẫn và script deploy lên vùng công khai. Giữ `.htaccess` tại gốc, `admin/`, `cms/` và `cms/lib/`.

Nếu domain trước đây chạy WordPress, kiểm tra và gỡ các rewrite WordPress đã hết áp dụng để URL không bị đưa về bộ xử lý cũ. Giữ lại các quy tắc hosting/SSL cần thiết, ghép với `.htaccess` của repo. Không xóa dữ liệu cũ trước khi có backup và kiểm tra bản mới.

**Không chạy `deploy-vietnix.sh` hoặc `deploy-cms-vietnix.sh` để triển khai domain chính:** hai script đó cố định đích `sub.lazivoyage.com`.

## 3. Cấu hình production và tạo admin đầu tiên

Trong cPanel Terminal/SSH, `cd` vào Document Root mới, rồi chạy:

```bash
php cms/console.php doctor
php cms/console.php configure-production
php cms/console.php setup-token
```

- `doctor` cần báo PHP/SQLite/DOM/kho riêng và quyền ghi hợp lệ.
- `configure-production` đặt canonical và đích xuất bản thành `https://lazivoyage.com`, môi trường `production`. Lệnh này không thay HTML công khai.
- `setup-token` tạo `cms/config.local.php` bí mật và hiển thị mã thiết lập. Không đưa file hoặc mã này lên GitHub. Nếu cấu hình kho riêng bằng file, dùng trường `dataDir` trước khi chạy các lệnh.

Chủ website mở https://lazivoyage.com/admin/, nhập mã thiết lập và tự chọn tên/email/mật khẩu để tạo quản trị viên đầu tiên. Các tài khoản bổ sung được tạo trong **Tài khoản quản trị** sau khi đăng nhập. Không có tài khoản/mật khẩu mặc định.

## 4. Nhập nội dung và kiểm tra phát hành

1. Vào **Xuất bản & cấu hình**, xác nhận cả hai URL là `https://lazivoyage.com` và môi trường **production**.
2. Chọn nhập nội dung website hiện tại. Bộ source hiện có 59 bài blog và 6 trang biên tập được; các trang công cụ vẫn được phục vụ cùng website.
3. Kiểm tra bài, ảnh, chuyên mục/tag, header/footer, mã xác minh Google/Bing và SEO trước khi áp dụng cấu hình.
4. Chọn **Áp dụng cấu hình website** để cập nhật HTML/archives/tìm kiếm/sitemap/RSS theo nội dung đã xuất bản. Các bản nháp chưa chọn xuất bản không tự lên website.
5. Kiểm tra trang chủ, một bài cũ, tìm kiếm, công cụ Sử Việt, menu mobile, `/admin/` và upload ảnh. Xóa cache hosting/CDN nếu vẫn thấy bản cũ.
6. Xác nhận trang công khai không còn `noindex` hoặc header `X-Robots-Tag: noindex` của môi trường test, trừ trang được chủ động loại khỏi tìm kiếm. `/admin/` và `/cms/` luôn phải noindex.

Canonical/sitemap/RSS phải dùng `lazivoyage.com`, không dùng subdomain hoặc Netlify. File `.htaccess` gốc chỉ gắn header noindex theo hostname `sub.lazivoyage.com`; kiểm tra cấu hình server/CDN bên ngoài repo để bảo đảm không còn noindex toàn domain chính.

## 5. Sau khi vận hành

CMS lưu dữ liệu/tài khoản ở kho riêng trên server, không tự đẩy nội dung ngược về GitHub. GitHub là nguồn mã ứng dụng, database CMS là nguồn nội dung.

Khi cập nhật code, triển khai riêng các file mã đã thay đổi. Không mirror toàn bộ HTML từ GitHub đè lên website đã được CMS chỉnh sửa. Giữ nguyên `cms/config.local.php`, `cms/deployment-state.json`, kho riêng và `uploads/cms/`. Khi thay template/CSS/JS, kiểm tra lại thao tác xuất bản.

Sao lưu đầy đủ kho riêng, ảnh upload và HTML công khai. Có thể tạo bản sao SQLite nhất quán bằng:

```bash
php cms/console.php backup
```

Xem thêm `CMS.md` về các module và cách cập nhật mã CMS cho môi trường test.
