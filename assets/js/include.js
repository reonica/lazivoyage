// assets/js/include.js

// Biến toàn cục để theo dõi trạng thái
let isInitialized = false;

// Hàm include header/footer
async function includeHTML() {
  const includes = document.querySelectorAll("[data-include]");

  for (let el of includes) {
    const file = el.getAttribute("data-include");
    try {
      const response = await fetch(file);
      if (!response.ok) throw new Error("Không tìm thấy " + file);
      const content = await response.text();

      // Tạo node tạm để parse HTML
      const temp = document.createElement("div");
      temp.innerHTML = content.trim();

      // Tìm header/footer trong file include
      const headerEl = temp.querySelector(".sticky-header") || temp.querySelector("header");
      const footerEl = temp.querySelector("footer") || temp.querySelector(".minimalist-footer");

      let newElement;
      
      if (headerEl) {
        newElement = headerEl.cloneNode(true);
        // Đưa header lên đầu body để sticky hoạt động
        document.body.insertBefore(newElement, document.body.firstChild);
        el.remove();
      } else if (footerEl) {
        newElement = footerEl.cloneNode(true);
        // Đưa footer xuống cuối body
        document.body.appendChild(newElement);
        el.remove();
      } else {
        el.innerHTML = temp.innerHTML;
      }
    } catch (e) {
      console.error("❌ Lỗi load include:", file, e);
    }
  }

  // Chỉ init một lần duy nhất
  if (!isInitialized) {
    initFeatures();
    isInitialized = true;
  }
}

// Khởi tạo tất cả tính năng
function initFeatures() {
  fixStickyFallback();
  initMobileMenu();
}

// Nếu sticky bị phá bởi ancestor -> fallback về fixed
function fixStickyFallback() {
  const header = document.querySelector(".sticky-header");
  if (!header) return;

  if (isStickyBroken(header)) {
    header.style.position = "fixed";
    header.style.top = "0";
    header.style.left = "0";
    header.style.width = "100%";
    header.style.zIndex = "1000";
    document.body.style.paddingTop = header.offsetHeight + "px";
    console.warn("⚠️ sticky không hoạt động -> fallback position:fixed");
  }
}

// Kiểm tra ancestor có phá sticky không
function isStickyBroken(el) {
  let node = el.parentElement;
  while (node && node !== document.documentElement) {
    const cs = window.getComputedStyle(node);
    if (["hidden", "auto", "scroll"].includes(cs.overflow)) return true;
    if (cs.transform && cs.transform !== "none") return true;
    if (cs.perspective && cs.perspective !== "none") return true;
    if (cs.filter && cs.filter !== "none") return true;
    if (cs.willChange && /\b(transform|perspective|filter)\b/.test(cs.willChange)) return true;
    node = node.parentElement;
  }
  return false;
}

// Khởi tạo menu mobile với kiểm tra kỹ hơn
function initMobileMenu() {
  console.log("Initializing mobile menu...");
  
  const hamburger = document.querySelector(".hamburger");
  const mobileMenu = document.querySelector(".mobile-menu-content");

  if (!hamburger || !mobileMenu) {
    console.error("❌ Không tìm thấy hamburger hoặc mobile menu");
    return;
  }

  console.log("Found elements:", hamburger, mobileMenu);

  // Xóa event listeners cũ (nếu có)
  hamburger.replaceWith(hamburger.cloneNode(true));
  const newHamburger = document.querySelector(".hamburger");

  newHamburger.addEventListener("click", function(e) {
    e.preventDefault();
    e.stopPropagation();
    console.log("Hamburger clicked");
    
    mobileMenu.classList.toggle("active");
    console.log("Mobile menu active:", mobileMenu.classList.contains("active"));
  });

  // Thêm sự kiện đóng menu khi click ra ngoài
  document.addEventListener("click", function(e) {
    if (mobileMenu.classList.contains("active") && 
        !e.target.closest(".mobile-menu-content") && 
        !e.target.closest(".hamburger")) {
      mobileMenu.classList.remove("active");
      console.log("Closed menu by clicking outside");
    }
  });

  // Ngăn sự kiện click trong menu lan ra ngoài
  mobileMenu.addEventListener("click", function(e) {
    e.stopPropagation();
  });
}

// Gọi hàm include khi load trang
document.addEventListener("DOMContentLoaded", function() {
  includeHTML().catch(error => {
    console.error("Error including HTML:", error);
  });
});

// Re-init nếu cần thiết (cho các SPA)
window.reInitMenu = function() {
  isInitialized = false;
  initFeatures();
};
