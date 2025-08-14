// search.js

// 1. Danh sách URL (dữ liệu)
const urlList = [
{ name: "Ý Nghĩa Của Trống Đồng Trong Văn Hoá Việt", url: "https://lazivoyage.blogspot.com/2025/08/y-nghia-cua-trong-ong-trong-van-hoa-viet.html" },
{ name: "Phân Biệt Hệ Tư Tưởng, Tôn Giáo, Tín Ngưỡng Và Mê Tín Dị Đoan", url: "https://lazivoyage.blogspot.com/2025/06/phan-biet-he-tu-tuong-ton-giao-tin-nguong-va-me-tin-di-doan.html" },
{ name: "Vịnh Hạ Long, Bái Tử Long Hay Lan Hạ?", url: "https://lazivoyage.blogspot.com/2025/06/vinh-ha-long-bai-tu-long-hay-lan-ha.html" },
{ name: "Cuộc Sống Ở Vịnh Hạ Long", url: "https://lazivoyage.blogspot.com/2025/06/cuoc-song-o-vinh-ha-long.html" },
{ name: "Sự Hình Thành Núi Đá Vôi Ở Việt Nam", url: "https://lazivoyage.blogspot.com/2025/06/su-hinh-thanh-nui-da-voi-o-viet-nam.html" },
{ name: "Lịch Sử Hình Thành Và Phát Triển Đạo Công Giáo Tại Việt Nam", url: "https://lazivoyage.blogspot.com/2025/06/lich-su-hinh-thanh-va-phat-trien-dao-cong-giao-tai-viet-nam.html" },
{ name: "Sức Sống Văn Hoá Việt Qua Thế Hệ Trẻ", url: "https://lazivoyage.blogspot.com/2025/05/suc-song-van-hoa-viet-qua-he-tre.html" },
{ name: "Hình Tượng Rắn Trong Thờ Mẫu Tại Việt Nam", url: "https://lazivoyage.blogspot.com/2025/05/hinh-tuong-ran-trong-tho-mau-tai-viet-nam.html" },
{ name: "Tìm hiểu cuộc sống miền núi Đông Bắc Việt Nam", url: "https://lazivoyage.wordpress.com/2025/05/23/tim-hieu-cuoc-song-mien-nui-dong-bac-viet-nam/" },
{ name: "Cuộc Sống Miền Tây Như Thế Nào?", url: "https://lazivoyage.wordpress.com/2025/05/22/cuoc-song-mien-tay-nhu-the-nao/" },
];

function removeVietnameseTones(str) {
  return str
    .normalize("NFD") // tách dấu
    .replace(/[\u0300-\u036f]/g, "") // xóa dấu
    .replace(/đ/g, "d")
    .replace(/Đ/g, "D")
    .toLowerCase();
}

// 2. Logic xử lý tìm kiếm
const searchInput = document.getElementById('mySearchInput');
const resultsContainer = document.getElementById('searchResults');

resultsContainer.style.width = "66%"; // 2/3 màn hình
resultsContainer.style.maxWidth = "none";

function renderResults(searchTerm) {
  resultsContainer.innerHTML = '';

  if (searchTerm === '') {
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
      resultsContainer.appendChild(link);
    });
  } else {
    resultsContainer.style.display = 'none';
  }
}

searchInput.addEventListener('input', function() {
  renderResults(searchInput.value);
});

// 3. Tạo trang kết quả riêng khi bấm Enter
searchInput.addEventListener('keydown', function(e) {
  if (e.key === 'Enter') {
    e.preventDefault();
    const query = searchInput.value.trim();
    if (query !== '') {
      const searchPageUrl = `/search.html?q=${encodeURIComponent(query)}`;
      window.location.href = searchPageUrl;
    }
  }
});

document.addEventListener('click', function(event) {
  if (!resultsContainer.contains(event.target) && event.target !== searchInput) {
    resultsContainer.style.display = 'none';
  }
});
