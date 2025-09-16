// assets/js/include.js

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

      if (headerEl) {
        // Nếu là header -> đưa lên đầu body để sticky hoạt động
        document.body.insertBefore(headerEl, document.body.firstChild);
        el.remove();
      } else if (footerEl) {
        // Nếu là footer -> đưa xuống cuối body
        document.body.appendChild(footerEl);
        el.remove();
      } else {
        // Nếu không phải header/footer -> chèn như bình thường
        el.innerHTML = temp.innerHTML;
      }
    } catch (e) {
      console.error("❌ Lỗi load include:", file, e);
    }
  }

  // Sau khi include xong thì init các tính năng
  setTimeout(() => {
    fixStickyFallback();
    initMobileMenu();
  }, 100);
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

// Khởi tạo menu mobile (hamburger)
function initMobileMenu() {
  const hamburger = document.querySelector(".hamburger");
  const mobileMenu = document.querySelector(".mobile-menu-content");

  if (hamburger && mobileMenu) {
    hamburger.addEventListener("click", () => {
      mobileMenu.classList.toggle("active");
    });
  }
}

// Gọi hàm include khi load trang
document.addEventListener("DOMContentLoaded", includeHTML);
