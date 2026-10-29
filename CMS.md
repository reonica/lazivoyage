# Lazi Voyage Content Studio

CMS nằm ở `/admin/`, backend PHP ở `/cms/`. Website công khai vẫn phục vụ HTML tĩnh. Bản test dùng `https://sub.lazivoyage.com/admin/`. Để quản trị viên hosting triển khai trực tiếp domain chính `https://lazivoyage.com/admin/`, làm theo **[DEPLOY-PRODUCTION.md](DEPLOY-PRODUCTION.md)**.

## Các mục quản trị

- Bài viết: biên tập trực quan/HTML, bản nháp, ảnh đại diện, chuyên mục/tag, ngày đăng, SEO/AIO và lịch sử phiên bản.
- Chuyên mục/tag: tên, slug, mô tả và SEO.
- Trang/landing page: ảnh bìa, văn bản, ảnh, danh sách bài, FAQ, nút liên hệ và liên hệ qua email. Có thể sắp xếp/ẩn khối và chọn ẩn header/footer.
- Thư viện ảnh: đồng bộ ảnh có sẵn trong `assets/images/` và `uploads/cms/`, upload JPEG/PNG/WebP/GIF, alt, chú thích và nguồn ảnh. Không nhận SVG hoặc mã thực thi. Đồng bộ giữ nguyên đường dẫn/file ảnh; chạy lại không tạo trùng và không ghi đè metadata đã sửa.
- Header/footer: logo, menu, thông tin liên hệ, liên kết và bản quyền.
- Mã chèn: head, đầu/cuối body, phạm vi URL, bật/tắt. Chỉ admin được sửa. Mã chèn không chạy trong trang quản trị hoặc iframe xem trước.
- SEO/AIO: title/description/canonical/noindex, ảnh chia sẻ, thông tin tổ chức, xác minh Google/Bing, redirects, tóm tắt, nguồn, FAQ và ngày kiểm chứng. Kiểm tra nội dung là gợi ý biên tập, không phải điểm xếp hạng.
- Tài khoản: admin tạo/sửa/khóa admin hoặc editor. Không có đăng ký công khai và không có mật khẩu mặc định. Hệ thống giữ ít nhất một admin hoạt động.
- Xuất bản/cấu hình: nhập nội dung cũ, cấu hình domain/môi trường và áp dụng thay đổi toàn website.

Google không yêu cầu schema hoặc tập tin đặc biệt để xuất hiện trong AI Overviews/AI Mode. Nội dung cần đáp ứng nền tảng SEO và chính sách tìm kiếm; CMS không bảo đảm thứ hạng hoặc việc được AI trích dẫn. Tham khảo: https://developers.google.com/search/docs/appearance/ai-features

## Yêu cầu Vietnix

- PHP 8.1 trở lên, extensions `pdo_sqlite`, `dom`, `session`, khả năng đọc ảnh qua `getimagesize`.
- HTTPS, Apache/LiteSpeed hỗ trợ `.htaccess`, `mod_headers` và `mod_rewrite` cho headers/redirects.
- PHP có quyền ghi vào document root để xuất bản HTML và `uploads/cms/` để lưu ảnh.
- Kho dữ liệu riêng **bên ngoài mọi document root trên hosting**, mặc định là thư mục `.lazivoyage-cms-<hash>` cạnh thư mục website. CMS từ chối lưu database trong thư mục website hiện tại và đặt thêm `.htaccess` chặn truy cập trong kho riêng. Nếu subdomain nằm trong `public_html` của domain khác, hãy cấu hình `CMS_DATA_DIR` ra ngoài cả `public_html`.

Nếu thư mục cha không ghi được, tạo thư mục riêng ngoài website và cấu hình `CMS_DATA_DIR` trong môi trường PHP hoặc trường `dataDir` trong `cms/config.local.php`. Không dùng `chmod 777`. File cấu hình này chỉ tồn tại trên hosting và bị loại khỏi Git/deploy.

## Cài đặt lần đầu

1. Test giao diện trên Netlify ở `/admin/?preview=1`. Đây là chế độ chỉ đọc; đăng nhập, lưu bài và tạo tài khoản cần PHP trên Vietnix.
2. Khi bản code đã được đưa lên GitHub, chạy `bash deploy-vietnix.sh` từ repo trên Mac để upload lần đầu. Script dùng main trên GitHub, không dùng các thay đổi chưa push. Script sẽ từ chối upload toàn site nếu đã kích hoạt CMS.
3. Mở cPanel Terminal/SSH, chuyển vào đúng Document Root của subdomain rồi chạy:

```bash
php cms/console.php doctor
php cms/console.php setup-token
```

Lệnh thứ hai tạo cấu hình bí mật tại hosting và hiển thị mã thiết lập một lần cho người đang dùng Terminal. Không gửi mã qua chat, không đưa file cấu hình lên GitHub.

4. Mở `https://sub.lazivoyage.com/admin/`, nhập mã thiết lập rồi tự chọn tên, email và mật khẩu ít nhất 12 ký tự để tạo admin đầu tiên.
5. Vào **Xuất bản & cấu hình → Nhập nội dung website hiện tại**. Bước nhập giữ nguyên các trang công khai, nhận diện bài blog và các trang biên tập được. Trang công cụ Sử Việt được giữ nguyên.
6. Sửa một bài, lưu nháp, xem trước rồi xuất bản bài đó để kiểm tra hoạt động trên subdomain. Giữ môi trường **test**, canonical domain chính là `https://lazivoyage.com`, đích xuất bản là `https://sub.lazivoyage.com`.

`doctor` báo rõ PHP, SQLite, DOM, kho riêng và quyền ghi. Dừng kích hoạt nếu kiểm tra lỗi; bật extension hoặc sửa quyền qua cPanel trước.

## Lưu và xuất bản

**Lưu nháp** chỉ lưu dữ liệu riêng; HTML công khai chưa thay đổi. **Xuất bản bài/trang** cập nhật nội dung đang chọn. **Áp dụng cấu hình website** cập nhật header/footer, SEO, mã chèn và các trang danh sách dựa trên nội dung đã xuất bản; không tự đưa toàn bộ bản nháp lên website.

CMS giữ bản chụp nội dung đã xuất bản tách khỏi bản đang sửa, cập nhật tìm kiếm/sitemap/RSS, và sao lưu các file trước khi ghi. Khôi phục một phiên bản nội dung tạo lại bản nháp; cần xuất bản lại nếu muốn áp dụng ra ngoài.

Sau khi kích hoạt, dữ liệu nội dung/tài khoản nằm trong kho riêng trên hosting, không tự đồng bộ ngược vào GitHub. GitHub quản lý mã ứng dụng; CMS quản lý nội dung. Cần sao lưu cả hai.

## Cập nhật code sau khi đã dùng CMS

```bash
bash deploy-cms-vietnix.sh --dry-run
bash deploy-cms-vietnix.sh
```

Script này lấy đúng `main` từ GitHub rồi chỉ upload `admin/` và `cms/`, giữ nguyên nội dung HTML đã xuất bản, ảnh upload, khóa thiết lập, database và dấu cài đặt. Không xóa file trên hosting.

Không dùng upload/mirror toàn repo để cập nhật site đã chạy CMS. Dấu công khai `cms/deployment-state.json` chỉ có `installed: true`, giúp script upload cũ dừng trước khi ghi đè. Không xóa dấu này để vượt kiểm tra. Khi cần thay CSS/JS công khai hoặc nâng cấp template, triển khai riêng các file mã tương ứng và kiểm tra xuất bản lại; script cập nhật CMS không thay các file đó.

## Sao lưu và chuyển sang domain chính

```bash
php cms/console.php backup
```

Sao lưu SQLite được ghi vào kho riêng. Để khôi phục đầy đủ cần giữ toàn bộ kho riêng (database, phiên bản, nguồn gốc HTML và bản chụp đã xuất bản), thư mục `uploads/cms/`, cùng bộ HTML công khai. Database backup riêng không thay thế bản sao đầy đủ. Dùng cPanel Backup để sao lưu thư mục tương ứng; tránh copy database SQLite đang mở mà bỏ quên WAL.

Khi chuyển sang `lazivoyage.com`, triển khai bộ code/HTML đã kiểm tra, chuyển ảnh và kho dữ liệu riêng, sửa `dataDir` nếu đường dẫn hosting đổi. Sau đó cập nhật đích xuất bản sang domain chính, đổi môi trường thành **production**, kiểm tra đăng nhập và xuất bản. Header `noindex` ở `.htaccess` gốc chỉ áp dụng cho hostname `sub.lazivoyage.com`; cấu hình môi trường test trong HTML cũng phải đổi trước khi phát hành chính thức.

## Kiểm thử dành cho lập trình viên

```bash
php tests/cms-backend.php
php tests/cms-publisher.php --real-content
node --check admin/admin.js
bash -n deploy-vietnix.sh deploy-cms-vietnix.sh
```

Các kiểm thử PHP dùng kho riêng/thư mục tạm, không sửa nội dung đang phục vụ. Kiểm thử trình duyệt và backend thực hiện trên một bản copy riêng.

## Cập nhật thư viện ảnh cho website đã nhập bài/trang

Nếu đã nhập bài/trang nhưng thư viện ảnh vẫn trống, cập nhật đồng thời 5 file mã hiện có sau từ GitHub: `admin/index.html`, `admin/admin.js`, `cms/api.php`, `cms/lib/Store.php`, `cms/lib/Publisher.php`. Chỉ thay mã CMS, giữ nguyên `cms/config.local.php`, kho dữ liệu riêng, `cms/deployment-state.json`, ảnh và HTML công khai.

Tải lại `/admin/`, vào **Thư viện ảnh → Đồng bộ ảnh có sẵn**. Chỉ quản trị viên được thực hiện đồng bộ. Chức năng nhập từ website hiện tại cũng tự nhận ảnh kể từ bản cập nhật này. Không cần xuất bản lại bài/trang sau khi đồng bộ ảnh.
