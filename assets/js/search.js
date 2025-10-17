// search.js

// 1. Danh sách URL (dữ liệu)
const urlList = [
  { name: "Cuộc Sống Dân Tộc Trên Cung Hà Giang Loop", url: "https://lazivoyage.blogspot.com/2025/10/cuoc-song-dan-toc-tren-cung-ha-giang.html" },
  { name: "Cuộc Sống Tại Hà Giang Của Gia Đình Vua Mèo", url: "https://lazivoyage.blogspot.com/2025/10/cuoc-song-tai-ha-giang-cua-gia-dinh-vua-meo.html" },
  { name: "Văn Hoá Chè Việt Nam", url: "https://lazivoyage.blogspot.com/2025/10/van-hoa-che-viet-nam.html" },
  { name: "Làm Gì Trong Ngày Chầu Lượt Giáo Xứ?", url: "https://lazivoyage.blogspot.com/2025/10/lam-gi-trong-ngay-chau-luot-giao-xu.html" },
  { name: "Chơi Gì Gần Hà Nội? 1 Ngày Ở Ecopark Cùng Gia Đình", url: "https://lazivoyage.blogspot.com/2025/09/choi-gi-gan-ha-noi-1-ngay-o-ecopark.html" },
  { name: "Sống Cùng Người Lô Lô", url: "https://lazivoyage.blogspot.com/2025/09/song-cung-nguoi-lo-lo.html" },
  { name: "Sống Cùng Người Tày - Tìm Hiểu Văn Hoá Tày", url: "https://lazivoyage.blogspot.com/2025/09/song-cung-nguoi-tay-tim-hieu-van-hoa-tay.html" },
  { name: "Lịch Họp Chợ Phiên Ở Miền Bắc", url: "https://lazivoyage.blogspot.com/2025/09/lich-hop-cho-phien-o-mien-bac.html" },
  { name: "Lịch Mùa Lúa Chín Trong Năm Ở Miền Bắc", url: "https://lazivoyage.blogspot.com/2025/09/lich-mua-lua-chin-trong-nam-o-mien-bac.html" },
  { name: "Ý Nghĩa Bộ Bát Bảo Chấp Kích Trong Văn Hoá Việt", url: "https://lazivoyage.blogspot.com/2025/08/y-nghia-bo-bat-bao-chap-kich-trong-van-hoa-viet.html" },
  { name: "Bản Đồ Du Lịch Việt Nam Sau Sáp Nhập", url: "https://lazivoyage.blogspot.com/2025/08/ban-do-du-lich-viet-nam-sau-sap-nhap.html" },
  { name: "Ý Nghĩa Của Trống Đồng Trong Văn Hoá Việt", url: "https://lazivoyage.blogspot.com/2025/08/y-nghia-cua-trong-dong-trong-van-hoa-viet.html" },
  { name: "Phân Biệt Hệ Tư Tưởng, Tôn Giáo, Tín Ngưỡng Và Mê Tín Dị Đoan", url: "https://lazivoyage.blogspot.com/2025/06/phan-biet-he-tu-tuong-ton-giao-tin-nguong-va-me-tin-di-doan.html" },
  { name: "Vịnh Hạ Long, Bái Tử Long Hay Lan Hạ?", url: "https://lazivoyage.blogspot.com/2025/06/vinh-ha-long-bai-tu-long-hay-lan-ha.html" },
  { name: "Cuộc Sống Ở Vịnh Hạ Long", url: "https://lazivoyage.blogspot.com/2025/06/cuoc-song-o-vinh-ha-long.html" },
  { name: "Sự Hình Thành Núi Đá Vôi Ở Việt Nam", url: "https://lazivoyage.blogspot.com/2025/06/su-hinh-thanh-nui-da-voi-o-viet-nam.html" },
  { name: "Lịch Sử Hình Thành Và Phát Triển Đạo Công Giáo Tại Việt Nam", url: "https://lazivoyage.blogspot.com/2025/06/lich-su-hinh-thanh-va-phat-trien-dao-cong-giao-tai-viet-nam.html" },
  { name: "Sức Sống Văn Hoá Việt Qua Thế Hệ Trẻ", url: "https://lazivoyage.blogspot.com/2025/05/suc-song-van-hoa-viet-qua-he-tre.html" },
  { name: "Hình Tượng Rắn Trong Thờ Mẫu Tại Việt Nam", url: "https://lazivoyage.blogspot.com/2025/05/hinh-tuong-ran-trong-tho-mau-tai-viet-nam.html" },
  { name: "Tìm hiểu cuộc sống miền núi Đông Bắc Việt Nam", url: "https://lazivoyage.wordpress.com/2025/05/23/tim-hieu-cuoc-song-mien-nui-dong-bac-viet-nam/" },
  { name: "Cuộc Sống Miền Tây Như Thế Nào?", url: "https://lazivoyage.wordpress.com/2025/05/22/cuoc-song-mien-tay-nhu-the-nao/" },
  { name: "Đặc Điểm Phật Giáo Nam Tông Của Người Khmer Nam Bộ &#8211; Lazi Voyage", url: "https://lazivoyage.wordpress.com/2025/05/19/dac-diem-phat-giao-nam-tong-cua-nguoi-khmer-nam-bo" },
  { name: "Chi phí sinh đẻ tại các bệnh viện ở Hà Nội", url: "https://lazivoyage.wordpress.com/2024/09/09/chi-phi-sinh-de-tai-cac-benh-vien-o-ha-noi" },
  { name: "Quá Trình Phát Triển Của Thai Nhi Theo Từng Tuần", url: "https://lazivoyage.wordpress.com/2024/08/25/qua-trinh-phat-trien-cua-thai-nhi-theo-tung-tuan" },
  { name: "Danh sách đồ sơ sinh cần chuẩn bị cho gia đình sinh con đầu lòng", url: "https://lazivoyage.wordpress.com/2024/08/18/danh-sach-do-so-sinh-can-chuan-bi-cho-gia-dinh-sinh-con-dau-long" },
  { name: "Long Ly Quy Phụng & Tứ Linh Trong Văn Hóa Việt", url: "https://lazivoyage.wordpress.com/2024/08/11/long-ly-quy-phung-tu-linh-trong-van-hoa-viet" },
  { name: "Phân biệt Phật giáo, Đạo giáo và Nho giáo", url: "https://lazivoyage.wordpress.com/2024/08/04/phan-biet-phat-giao-dao-giao-va-nho-giao" },
  { name: "Hầu đồng và Đạo mẫu trong văn hoá tâm linh việt", url: "https://lazivoyage.wordpress.com/2024/07/29/hau-dong-va-dao-mau-trong-van-hoa-tam-linh-viet" },
  { name: "Kiến trúc Nội công ngoại quốc của chùa Việt", url: "https://lazivoyage.wordpress.com/2024/06/30/kien-truc-noi-cong-ngoai-quoc-cua-chua-viet" },
  { name: "Gợi ý cách chọn tên cho bé trai độc đáo và ý nghĩa &#8211; Lazi Voyage", url: "https://lazivoyage.wordpress.com/2024/06/22/goi-y-cach-chon-ten-cho-be-trai-doc-dao-va-y-nghia" },
  { name: "Cách chọn xe đạp gấp tốt và bền nhất", url: "https://lazivoyage.wordpress.com/2024/03/18/cach-chon-xe-dap-gap-tot-va-ben-nhat" },
  { name: "Phân biệt Chùa, Đình, Đền, Phủ, Miếu, Quán trong văn hóa Việt", url: "https://lazivoyage.wordpress.com/2024/03/09/phan-biet-chua-dinh-den-phu-mieu-quan-trong-van-hoa-viet" },
  { name: "Tham quan Phủ Chủ Tịch - Địa điểm nên đi khi du lịch Hà Nội", url: "https://lazivoyage.wordpress.com/2024/02/23/tham-quan-phu-chu-tich-dia-diem-nen-di-khi-du-lich-ha-noi" },
  { name: "Chùa Trấn Quốc - Địa điểm du lịch tâm linh văn hóa Hà Nội", url: "https://lazivoyage.wordpress.com/2024/02/21/chua-tran-quoc-dia-diem-du-lich-bieu-tuong-tam-linh-van-hoa-ha-noi" },
  { name: "Quần thể Lăng Bác - Di tích lịch sử, văn hóa nổi tiếng tại Hà Nội", url: "https://lazivoyage.wordpress.com/2024/02/18/quan-the-lang-bac-di-tich-lich-su-van-hoa-noi-tieng-tai-ha-noi" },
  { name: "Đền Bạch Mã, Thăng Long tứ trấn - Di tích 1000 năm tuổi tại Hà Nội", url: "https://lazivoyage.wordpress.com/2024/02/15/den-bach-ma-thang-long-tu-tran-di-tich-1000-nam-tuoi-tai-ha-noi" },
  { name: "Tài liệu thuyết minh Văn Miếu Quốc Tử Giám cho HDV mới", url: "https://lazivoyage.wordpress.com/2024/02/13/tai-lieu-thuyet-minh-ve-van-mieu-quoc-tu-giam-cho-hdv-moi" },
  { name: "Kinh nghiệm du lịch Mỹ: Top 10 Công viên Quốc gia đẹp nhất nước Mỹ", url: "https://lazivoyage.wordpress.com/2024/02/09/kinh-nghiem-du-lich-my-top-10-cong-vien-quoc-gia-dep-nhat-nuoc-my" },
  { name: "Kinh nghiệm du lịch Mỹ - Những ngày lễ quan trọng ở Mỹ", url: "https://lazivoyage.wordpress.com/2024/02/09/kinh-nghiem-du-lich-my-nhung-ngay-le-quan-trong-o-my" },
  { name: "Tín ngưỡng thờ cúng của đồng bào Thái đen Điện Biên", url: "https://lazivoyage.wordpress.com/2024/01/30/tin-nguong-tho-cung-cua-dong-bao-thai-den-dien-bien" },
  { name: "Tài liệu thuyết minh bảo tàng dân tộc học Việt Nam cho HDV mới", url: "https://lazivoyage.wordpress.com/2024/01/27/tai-lieu-thuyet-minh-bao-tang-dan-toc-hoc-viet-nam-cho-hdv-moi" },
  { name: "Kinh nghiệm tham quan bảo tàng dân tộc học Việt Nam", url: "https://lazivoyage.wordpress.com/2024/01/27/kinh-nghiem-tham-quan-bao-tang-dan-toc-hoc-viet-nam" },
  { name: "Cẩm nang du lịch Pù Luông từ a-z", url: "https://lazivoyage.wordpress.com/2024/01/20/lazi-di-pu-luong-cam-nang-du-lich-pu-luong-tu-a-z" },
  { name: "Blue zones là gì? Blue zones đầu tiên của Việt Nam ở đâu?", url: "https://lazivoyage.wordpress.com/2024/01/11/blue-zones-la-gi-blue-zones-dau-tien-cua-viet-nam-o-dau" },
  { name: "Khu du lịch sinh thái Làng Xanh Bến Tre", url: "https://lazivoyage.wordpress.com/2024/01/06/khu-sinh-thai-lang-xanh-ben-tre-tan-huong-ky-nghi-duong-gia-dinh-tron-ven" },
  { name: "Khu Du Lịch Bản Ven Xanh, điểm du lịch cộng đồng tại Bắc Giang", url: "https://lazivoyage.wordpress.com/2023/12/16/tour-tham-quan-khu-du-lich-ban-ven-xanh-diem-du-lich-cong-dong-bac-giang" },
  { name: "Khu Du lịch sinh thái Đồi Trầm xây dựng nhiều hạng mục trái phép", url: "https://lazivoyage.wordpress.com/2023/12/06/khu-du-lich-sinh-thai-doi-tram-xay-dung-nhieu-hang-muc-trai-phep" },
  { name: "Top 5 địa điểm du lịch nên đi sau thi cử", url: "https://lazivoyage.wordpress.com/2023/10/10/top-5-dia-diem-du-lich-nen-di-sau-thi-cu" },
  { name: "Blackpink nên thử top 10 món đặc sản Hà Nội này", url: "https://lazivoyage.wordpress.com/2023/10/10/blackpink-nen-thu-top-10-mon-dac-san-ha-noi-nay" },
  { name: "Điểm check-in hot: Vườn mận vàng Mộc Châu", url: "https://lazivoyage.wordpress.com/2023/09/28/vuon-man-chin-vang-kho-kiem-diem-check-in-dep-mien-che-khi-du-lich-moc-chau" },
  { name: "Triển khai đường bay thuỷ phi cơ Tuần Châu - Cô Tô", url: "https://lazivoyage.wordpress.com/2023/09/28/trien-khai-duong-bay-thuy-phi-co-tuan-chau-co-to" },
  { name: "Top 6 khu du lịch sinh thái tại Phú Quốc", url: "https://lazivoyage.wordpress.com/2023/09/28/top-6-khu-du-lich-sinh-thai-tai-phu-quoc" },
  { name: "Top 28 Đặc sản Phú Quốc ngon nên thử và mua làm quà", url: "https://lazivoyage.wordpress.com/2023/09/28/top-28-dac-san-phu-quoc-ngon-nen-thu-va-mua-lam-qua" },
  { name: "TOP 12 địa điểm check-in HOT nhất Grand World Phú Quốc", url: "https://lazivoyage.wordpress.com/2023/09/28/top-12-dia-diem-check-in-hot-nhat-grand-world-phu-quoc" },
  { name: "Top 10 điểm ngắm hoàng hôn đẹp tại Phú Quốc", url: "https://lazivoyage.wordpress.com/2023/09/28/top-10-diem-ngam-hoang-hon-dep-tai-phu-quoc" },
  { name: "Rừng cây Chá cổ nên thơ trong mùa rụng lá", url: "https://lazivoyage.wordpress.com/2023/09/28/rung-cay-cha-co-nen-tho-trong-mua-rung-la" },
  { name: "Quán cà phê xanh cho người mê lá ở Hà Nội", url: "https://lazivoyage.wordpress.com/2023/09/28/quan-ca-phe-xanh-cho-nguoi-me-la-o-ha-noi" },
  { name: "Phát triển bền vững du lịch sinh thái đảo Phú Quốc", url: "https://lazivoyage.wordpress.com/2023/09/28/phat-trien-ben-vung-du-lich-sinh-thai-dao-phu-quoc" },
  { name: "Du lịch xanh: Trekking Vườn Quốc gia Phú Quốc", url: "https://lazivoyage.wordpress.com/2023/09/28/lazi-di-xanh-trekking-vuon-quoc-gia-phu-quoc" },
  { name: "Du lịch xanh: Du lịch sinh thái tại Kỳ Thượng, Hạ Long", url: "https://lazivoyage.wordpress.com/2023/09/28/lazi-di-xanh-du-lich-sinh-thai-tai-ky-thuong-ha-long" },
  { name: "Cẩm nang du lịch Sapa từ a-z", url: "https://lazivoyage.wordpress.com/2023/09/28/lazi-di-sapa-cam-nang-du-lich-tu-a-z" },
  { name: "Cẩm nang du lịch Sài Gòn tự túc từ Lazi", url: "https://lazivoyage.wordpress.com/2023/09/28/lazi-di-sai-gon-cam-nang-du-lich-tu-tuc" },
  { name: "Lazi du lịch Phú Thọ &#8211; Review Phú Thọ từ a-z", url: "https://lazivoyage.wordpress.com/2023/09/28/lazi-di-phu-tho-review-du-lich-tu-a-z" },
  { name: "Kinh nghiệm du lịch Huế tự túc từ a-z", url: "https://lazivoyage.wordpress.com/2023/09/28/lazi-di-hue-kinh-nghiem-du-lich-tu-tuc-tu-a-z" },
  { name: "Trọn bộ cẩm nang du lịch Hạ  Long", url: "https://lazivoyage.wordpress.com/2023/09/28/lazi-di-ha-long-tron-bo-cam-nang-du-lich" },
  { name: "Cẩm nang du lịch Đà Nẵng từ a-z", url: "https://lazivoyage.wordpress.com/2023/09/28/lazi-di-da-nang-cam-nang-du-lich-tu-a-z" },
  { name: "Hàng loạt khách hủy phòng, tour du thuyền vì bão Talim", url: "https://lazivoyage.wordpress.com/2023/09/28/hang-loat-khach-huy-phong-tour-du-thuyen-vi-bao-talim" },
  { name: "Giá khách sạn Phú Quốc từ 1 đến 5 sao", url: "https://lazivoyage.wordpress.com/2023/09/28/gia-khach-san-phu-quoc-tu-1-5-sao" },
  { name: "21 điểm checkin hot thị trấn Địa Trung Hải Phú Quốc", url: "https://lazivoyage.wordpress.com/2023/09/28/chup-gi-tai-thi-tran-dia-trung-hai-phu-quoc-xem-21-diem-hot" },
  { name: "Cẩm nang du lịch Na Hang chi tiết nhất", url: "https://lazivoyage.wordpress.com/2023/09/28/cam-nang-du-lich-na-hang-chi-tiet-nhat" },
  { name: "Khám phá Bảo tàng Quảng Ninh trong chuyến du lịch văn hoá Hạ Long", url: "https://lazivoyage.wordpress.com/2023/09/28/bao-tang-quang-ninh-kham-pha-vien-ngoc-den-ben-vinh-ha-long" },
  { name: "Review Phú Quốc chi tiết nhất", url: "https://lazivoyage.wordpress.com/2023/09/24/lazi-di-phu-quoc-review-du-lich-chi-tiet-nhat" },
];

// Hàm tiện ích để xóa dấu tiếng Việt
function removeVietnameseTones(str) {
  if (!str) return "";
  return str
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/đ/g, "d")
    .replace(/Đ/g, "D")
    .toLowerCase();
}

// 2. Logic xử lý tìm kiếm cho thanh gợi ý (sẽ được gọi từ include.js)
function initSearchFunctionality() {
  const searchInput = document.getElementById('mySearchInput');
  const resultsContainer = document.getElementById('searchResults');
  
  if (!searchInput || !resultsContainer) {
    return;
  }

  resultsContainer.style.width = "66%";
  resultsContainer.style.maxWidth = "none";

  function renderSuggestions(searchTerm) {
    resultsContainer.innerHTML = '';
    if (searchTerm.trim() === '') {
      resultsContainer.style.display = 'none';
      return;
    }

    const filteredUrls = urlList.filter(item =>
      removeVietnameseTones(item.name).includes(removeVietnameseTones(searchTerm))
    );

    if (filteredUrls.length > 0) {
      resultsContainer.style.display = 'block';
      filteredUrls.forEach(item => {
        const link = document.createElement('a');
        link.href = item.url;
        link.textContent = item.name;
        link.target = "_blank";
        link.style.display = 'block';
        link.style.padding = '5px 0';
        resultsContainer.appendChild(link);
      });
    } else {
      resultsContainer.style.display = 'none';
    }
  }
  
  function performSearch() {
      const query = searchInput.value.trim();
      if (query !== '') {
        const searchPageUrl = `/search.html?q=${encodeURIComponent(query)}`;
        window.location.href = searchPageUrl;
      }
  }

  searchInput.addEventListener('input', function() {
    renderSuggestions(this.value);
  });

  searchInput.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      performSearch();
    }
  });

  document.addEventListener('click', function(event) {
    if (!resultsContainer.contains(event.target) && event.target !== searchInput) {
      resultsContainer.style.display = 'none';
    }
  });

  const searchButton = document.querySelector('.search-bar button');
  if (searchButton) {
      searchButton.addEventListener('click', function(e) {
          e.preventDefault();
          performSearch();
      });
  }
}

// 3. Logic hiển thị kết quả trên trang search.html (ĐÃ CẬP NHẬT)
function renderSearchPageResults() {
  const resultsContainer = document.getElementById('search-results');
  const searchQueryElement = document.getElementById('searchQuery');

  if (resultsContainer && searchQueryElement) {
    const params = new URLSearchParams(window.location.search);
    const query = params.get('q') || "";
    searchQueryElement.textContent = query;

    resultsContainer.innerHTML = '';

    const filteredResults = urlList.filter(item =>
      removeVietnameseTones(item.name).includes(removeVietnameseTones(query))
    );

    if (filteredResults.length > 0) {
      // THAY ĐỔI 1: Tạo một danh sách (<ul>) để chứa các kết quả
      const resultList = document.createElement('ul');
      resultList.style.listStyleType = 'disc'; // Dùng dấu chấm tròn
      resultList.style.paddingLeft = '20px';   // Căn lề cho dấu chấm

      filteredResults.forEach(item => {
        // THAY ĐỔI 2: Mỗi kết quả là một mục trong danh sách (<li>)
        const listItem = document.createElement('li');
        listItem.style.marginBottom = '10px'; // Khoảng cách giữa các dòng

        const link = document.createElement('a');
        link.href = item.url;
        link.textContent = item.name;
        link.target = "_blank";
        link.style.fontSize = '1.1em'; // Điều chỉnh cỡ chữ nếu cần

        // Gắn link vào trong mục <li>
        listItem.appendChild(link);
        
        // THAY ĐỔI 3: Không còn hiển thị link trần nữa

        // Gắn mục <li> vào danh sách <ul>
        resultList.appendChild(listItem);
      });

      // Gắn toàn bộ danh sách vào container
      resultsContainer.appendChild(resultList);
    } else {
      resultsContainer.innerHTML = "<p>Không tìm thấy kết quả nào phù hợp với từ khóa của bạn.</p>";
    }
  }
}

// Gọi hàm render kết quả khi trang search.html được tải
document.addEventListener('DOMContentLoaded', renderSearchPageResults);
