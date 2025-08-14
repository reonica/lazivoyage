// search.js

// 1. Danh sách URL (dữ liệu)
const urlList = [
  { name: "Sức Sống Văn Hoá Việt Qua Thế Hệ Trẻ", url: "https://lazivoyage.blogspot.com/2025/05/suc-song-van-hoa-viet-qua-he-tre.html" },
  { name: "Phân Biệt Hệ Tư Tưởng, Tôn Giáo, Tín Ngưỡng Và Mê Tín Dị Đoan
", url: "https://lazivoyage.blogspot.com/2025/06/phan-biet-he-tu-tuong-ton-giao-tin-nguong-va-me-tin-di-doan.html" },
  { name: "Long Ly Quy Phụng – Tứ Linh Trong Văn Hóa Việt", url: "https://lazivoyage.wordpress.com/2024/08/11/long-ly-quy-phung-tu-linh-trong-van-hoa-viet/" },
];

// 2. Logic xử lý tìm kiếm
const searchInput = document.getElementById('mySearchInput');
const resultsContainer = document.getElementById('searchResults');

searchInput.addEventListener('input', function() {
  const searchTerm = searchInput.value.toLowerCase();
  
  resultsContainer.innerHTML = '';
  
  if (searchTerm === '') {
    resultsContainer.style.display = 'none';
    return;
  }

  const filteredUrls = urlList.filter(item => 
    item.name.toLowerCase().includes(searchTerm)
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
});

document.addEventListener('click', function(event) {
  if (!resultsContainer.contains(event.target) && event.target !== searchInput) {
    resultsContainer.style.display = 'none';
  }
});
