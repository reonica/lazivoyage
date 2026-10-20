# Cây văn hóa Việt Nam — Bản prototype giao diện

**Trạng thái:** Chỉ để duyệt UI/UX. Không đưa vào điều hướng của website, không sửa \`main\`, không kết nối Google Sheets/API và không tự xuất bản ra website chính.

## Truy cập
Khi chạy repository trên một web server tĩnh, mở \`/cay-van-hoa/\`. Nếu Netlify cấu hình Deploy Previews cho pull request này, dùng URL preview do Netlify cung cấp. Không giả định URL production đã tồn tại.

## Nhận diện được tái sử dụng
- \`../style.css\`, \`../assets/fonts/fonts.css\` và logo gốc trong \`../assets/images/logo.webp\`.
- Màu sắc: \`#096b68\` xanh thương hiệu, \`#fffbde\` kem, \`#fff\`, \`#0a0a0a\`, \`#dedede\`.
- Font: Urbanist (wordmark), Inter (headings), Roboto (body), Lato (controls/navigation).
- CSS mới được giới hạn trong \`culture.css\`, không thay đổi styles toàn site.
- Footer rút gọn chỉ để xem bố cục. Khi đưa vào chính thức sẽ dùng markup footer chung của site.

## Tương tác để duyệt
1. Desktop: chọn chủ đề bên trái, danh sách khái niệm ở giữa, overview bên phải. Hover trên thiết bị hỗ trợ sẽ đổi overview.
2. Mobile: chọn chủ đề qua dropdown; chạm khái niệm để mở bottom sheet; có nút đóng, Escape và backdrop.
3. Tìm kiếm không dấu theo tiêu đề, alias, overview và nhóm văn hóa.
4. Khái niệm có thể thuộc nhiều nhóm. Các mục liên quan cho phép điều hướng chéo.
5. Chỉ hai link bài viết hiện có trong repo được bật: Phố cổ Hội An và Đại Nội Huế. Các khái niệm minh họa còn lại hiển thị "Bài chuyên sâu đang được biên soạn".

## Lưu ý nội dung
- Mọi mô tả/quan hệ hiện có là **dữ liệu minh họa** cần biên tập và kiểm chứng.
- Trang có \`noindex,nofollow\` để tránh index dữ liệu thử nghiệm.
- Một số nội dung ban đầu đã có HTML fallback; JS chỉ là lớp trải nghiệm. Bản production phải tạo HTML hoàn chỉnh từ dữ liệu xuất bản thay vì dựa vào JS để cung cấp nội dung SEO.

## Chưa nằm trong prototype
- Google Sheets / Apps Script / webhook / R2 / Supabase / CDN
- Draft/Publish workflow, review các quan hệ AI gợi ý
- Pipeline JSON versioning và tự tạo HTML, sitemap, canonical, schema
- Unit/integration/accessibility/visual regression testing tự động
- Tích hợp navigation và đường dẫn xuất bản chính thức

## Kiến trúc dữ liệu khi được duyệt
- \`Concepts\`: \`concept_id\`, \`name_vi\`, \`aliases\`, \`overview_vi\`, \`article_url\`, \`status\`, \`updated_at\`.
- \`Relations\`: \`from_id\`, \`type\`, \`to_id\`, \`status\`. Chỉ link published, xác thực ID hai đầu, phân biệt \`belongs_to\` và \`related_to\`.
- \`Categories\`: \`category_id\`, \`title\`, \`description\`, \`sort_order\`.
- \`ConceptCategories\`: \`concept_id\`, \`category_id\`, \`is_primary\`.
- \`Aliases\`: \`alias\`, \`concept_id\`, \`locale\`.
- Google Sheets làm CMS; webhook có xác thực kích hoạt sync job có debounce, retry, kiểm tra dữ liệu, xuất bản JSON có version vào kho riêng/CDN.
- Khi bài viết hoặc nội dung indexable thay đổi: rebuild HTML và cập nhật sitemap để không lệch với JSON.
- Không gọi API AI lúc hover: overview được tạo/kiểm duyệt trước.

## Điều cần duyệt trước khi triển khai thật
- Bố cục desktop 3 cột, mobile 1 cột + bottom sheet.
- Giao diện đơn giản, không đồ thị chuyển động, không banner ảnh lớn.
- Cách người dùng chọn khái niệm: hover preview trên desktop, click/tap để xem chi tiết.
- Quy trình xuất bản \`Draft → Reviewed → Published\` và mức độ cập nhật mong muốn.
