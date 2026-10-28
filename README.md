# Lazi Voyage — Website và CMS

Repo chứa toàn bộ website HTML tĩnh và CMS PHP tại `/admin/`. Hướng dẫn bàn giao cho quản trị viên triển khai trực tiếp `lazivoyage.com`: **[DEPLOY-PRODUCTION.md](DEPLOY-PRODUCTION.md)**. Chi tiết các module, dữ liệu và tài khoản: **[CMS.md](CMS.md)**.

Website công khai không cần build. Để đăng nhập, lưu bài, tạo admin và xuất bản từ CMS, hosting cần PHP 8.1+ và SQLite. Netlify chỉ dùng xem giao diện CMS tại `/admin/?preview=1`.

Các file chính nằm ngay ngoài cùng:

| File / thư mục | Công dụng |
| --- | --- |
| `index.html` | Trang chủ |
| `style.css` | Giao diện desktop, tablet và mobile |
| `script.js` | Carousel, tìm kiếm, lọc và các thao tác giao diện |
| `assets/` | Hình ảnh và font |
| `data/` | Dữ liệu tìm kiếm và Sử Việt |
| `admin/` | Giao diện quản trị tiếng Việt |
| `cms/` | API PHP, quản lý tài khoản và bộ xuất bản |
| Các thư mục trang con | Mỗi trang có `index.html` riêng |
| `sitemap.xml`, `rss.xml`, `robots.txt` | Các file hỗ trợ SEO |
| `DEPLOY-PRODUCTION.md` | Hướng dẫn cài website và CMS trên domain chính |

## Upload lên GitHub

Repo đang dùng là `reonica/lazivoyage`, nhánh `main`. Commit/push thay đổi mã ứng dụng lên nhánh này. Không đưa `cms/config.local.php`, database, mật khẩu hoặc mã thiết lập lên GitHub; `.gitignore` đã loại các file dữ liệu riêng.

Trên Netlify, publish directory là `.` và không đặt build command. Website công khai và giao diện CMS có thể xem trên Netlify; backend PHP cần Vietnix. Đưa website và CMS vào cùng Document Root để các đường dẫn `/admin/` và `/cms/` hoạt động.

## Cập nhật bản test trên Vietnix bằng một lệnh

Sau khi bản mới nhất của nhánh `main` đã chạy tốt trên Netlify, mở Terminal tại repo và chạy:

```bash
bash deploy-vietnix.sh
```

Lệnh trên chỉ dùng trước khi kích hoạt CMS. Khi CMS đã lưu/xuất bản nội dung, dùng `bash deploy-cms-vietnix.sh` để chỉ cập nhật mã quản trị; script upload toàn site sẽ tự dừng để bảo vệ nội dung trên hosting. Hai script này chỉ nhắm subdomain test, không triển khai domain chính.

Nhập mật khẩu FTP khi được hỏi. Script lấy phiên bản `main` mới nhất từ GitHub vào thư mục tạm rồi upload qua FTPS; không đổi nhánh, không sửa các file đang làm trên Mac và không lưu mật khẩu. Thay đổi chưa commit/push không được triển khai. Nếu có commit mới trên `main` sau lần test Netlify, cần test commit đó trước khi chạy lệnh.

Đích cố định là tài khoản `lazivoyage@sub.lazivoyage.com` trên `host142.vietnix.vn:21`, thư mục FTP `/`. Document Root của `sub.lazivoyage.com` phải tiếp tục trỏ vào thư mục được cấp cho tài khoản FTP này (`sub.lazivoyage.com/lazivoyage`). Script này chỉ cập nhật subdomain test; khi triển khai `lazivoyage.com` cần cấu hình đích riêng.

Yêu cầu `git`, `curl` và `lftp` (`brew install lftp`). Có thể xem trước bằng `bash deploy-vietnix.sh --dry-run`; vẫn cần đăng nhập FTP để đối chiếu danh sách file. Script bỏ qua file Git, cấu hình bí mật, file hướng dẫn và chính script deploy; không xóa file cũ trên hosting. Sau upload, script so sánh HTML trang chủ, CSS và JavaScript đang phục vụ qua HTTPS với phiên bản vừa triển khai.

## Mở thử trên máy

Website cần HTTP để tải dữ liệu tìm kiếm. Nếu có Python 3, chạy trong thư mục này:

```bash
python3 -m http.server 8080
```

Mở `http://127.0.0.1:8080`. Đừng chỉ mở `index.html` bằng `file://` nếu muốn thử đầy đủ tìm kiếm và bộ lọc.

## Cập nhật

Sau khi kích hoạt CMS, quản lý nội dung trong `/admin/`. Bộ xuất bản cập nhật HTML, trang danh sách, `data/search-index.json`, sitemap và RSS; bản nháp được giữ riêng. Nội dung CMS được lưu trên hosting, không tự đồng bộ ngược vào GitHub. Tránh upload HTML cũ từ repo đè lên nội dung đã sửa bằng CMS.

Canonical và sitemap dùng `https://lazivoyage.com`. Khi triển khai domain chính, chạy `php cms/console.php configure-production` và làm theo hướng dẫn bàn giao. Nếu dùng domain khác, cập nhật cấu hình URL trong CMS trước khi xuất bản. DNS/document root vẫn do hosting quản lý.

Biểu mẫu mặc định mở ứng dụng email để người đọc gửi thư đến Lazi. Muốn nhận form trực tiếp cần tích hợp endpoint riêng; CMS hiện tại không cung cấp dịch vụ gửi bản tin hoặc xử lý form liên hệ.
