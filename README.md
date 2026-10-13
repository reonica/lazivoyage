# Lazi Voyage — HTML thuần

Các file chính nằm ngay ngoài cùng:

| File / thư mục | Công dụng |
| --- | --- |
| `index.html` | Trang chủ |
| `style.css` | Giao diện desktop, tablet và mobile |
| `script.js` | Carousel, tìm kiếm, lọc và các thao tác giao diện |
| `assets/` | Hình ảnh và font |
| `data/` | Dữ liệu tìm kiếm, Sử Việt, từ điển |
| Các thư mục trang con | Mỗi trang có `index.html` riêng |
| `sitemap.xml`, `rss.xml`, `robots.txt` | Các file hỗ trợ SEO |
| `.github/workflows/pages.yml` | Tự triển khai website từ GitHub |

## Upload lên GitHub

1. Đưa toàn bộ nội dung của thư mục này vào **gốc repository**, trên nhánh `main`. Giữ cả thư mục `.github` và file `.nojekyll`.
2. Vào **Settings → Pages → Source → GitHub Actions**.
3. Vào **Actions → Deploy static Lazi Voyage → Run workflow** để triển khai lần đầu. Sau đó mỗi lần push vào `main` sẽ triển khai lại.
4. Mở URL tại Settings → Pages. Các link và tài nguyên dùng đường dẫn tương đối; tìm kiếm tự nhận vị trí website từ `script.js`, nên dùng được cả ở domain gốc và `/ten-repo/`.

Bạn cũng có thể upload toàn bộ nội dung thư mục này vào document root của static hosting. Không cần Node.js, npm hay chạy build cho gói HTML này. Trên Netlify, deploy thư mục này với publish directory là `.` và không đặt build command.

## Mở thử trên máy

Website cần HTTP để tải dữ liệu tìm kiếm. Nếu có Python 3, chạy trong thư mục này:

```bash
python3 -m http.server 8080
```

Mở `http://127.0.0.1:8080`. Đừng chỉ mở `index.html` bằng `file://` nếu muốn thử đầy đủ tìm kiếm và bộ lọc.

## Cập nhật

Đây là bản HTML đã xuất của toàn bộ 211 trang. Bạn có thể sửa trực tiếp HTML/CSS/JS. Khi thêm bài hoặc đổi URL, cần cập nhật cả archive, `data/search-index.json`, sitemap và RSS. Với việc cập nhật thường xuyên, dùng bộ source `lazi-voyage-github.zip` đã gửi trước: nội dung ở `src`, build ra `dist` tự cập nhật các file liên quan.

Canonical và sitemap của gói này dùng `https://lazivoyage.com`, dành cho việc vận hành domain đó. Nếu dùng domain khác, cần thay domain trong các file HTML/XML và robots. Cấu hình domain/DNS trong nền tảng hosting.

Biểu mẫu mặc định mở ứng dụng email để người đọc gửi thư đến Lazi. Muốn nhận form trực tiếp, dùng cấu hình Netlify/endpoint trong bộ source; gói HTML này không tự tạo dịch vụ xử lý form hoặc gửi chiến dịch bản tin.
