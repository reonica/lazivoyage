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
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/đ/g, "d")
    .replace(/Đ/g, "D")
    .toLowerCase();
}

// 2. Logic xử lý tìm kiếm cho thanh gợi ý
const searchInput = document.getElementById('mySearchInput');
const resultsContainer = document.getElementById('searchResults');

if (resultsContainer) {
  resultsContainer.style.width = "66%";
  resultsContainer.style.maxWidth = "none";
}

function renderResults(searchTerm) {
  if (resultsContainer) {
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
}

if (searchInput) {
  searchInput.addEventListener('input', function() {
    renderResults(searchInput.value);
  });

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
}

document.addEventListener('click', function(event) {
  if (resultsContainer && !resultsContainer.contains(event.target) && event.target !== searchInput) {
    resultsContainer.style.display = 'none';
  }
});

// 3. Logic hiển thị kết quả trên trang search.html
function renderSearchPageResults() {
  const resultsContainer = document.getElementById('search-results');
  const searchQueryElement = document.getElementById('searchQuery');
  
  if (resultsContainer && searchQueryElement) {
    const params = new URLSearchParams(window.location.search);
    const query = params.get('q') || "";
    searchQueryElement.textContent = query;

    const filteredResults = urlList.filter(item =>
      removeVietnameseTones(item.name).includes(removeVietnameseTones(query))
    );

    if (filteredResults.length > 0) {
      resultsContainer.innerHTML = filteredResults.map(r => `
        <article>
          <div class="image">
            <img src="${r.image || 'images/lazi-voyage/default-placeholder.jpg'}" alt="${r.name}" />
          </div>
          <div class="caption">
            <h3>${r.name}</h3>
            <p>${r.description || 'Khám phá thêm về ' + r.name}</p>
            <ul class="actions fixed">
              <li><a href="${r.url}" class="button small">Chi tiết</a></li>
            </ul>
          </div>
        </article>
      `).join("");
    } else {
      resultsContainer.innerHTML = "<p>Không tìm thấy kết quả cho từ khóa này.</p>";
    }
  }
}

// Gọi hàm renderSearchPageResults khi trang search.html được tải
document.addEventListener('DOMContentLoaded', renderSearchPageResults);
