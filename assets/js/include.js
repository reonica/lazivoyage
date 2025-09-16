// assets/js/include.js

let isInitialized = false;

async function includeHTML() {
  try {
    const includes = document.querySelectorAll("[data-include]");
    const includePromises = [];

    for (let el of includes) {
      const file = el.getAttribute("data-include");
      includePromises.push(loadIncludeFile(el, file));
    }

    await Promise.all(includePromises);
    
    if (!isInitialized) {
      initFeatures();
      isInitialized = true;
    }
  } catch (error) {
    console.error("❌ Lỗi includeHTML:", error);
  }
}

async function loadIncludeFile(el, file) {
  try {
    const response = await fetch(file);
    if (!response.ok) throw new Error(`HTTP ${response.status}: ${file}`);
    
    const content = await response.text();
    const temp = document.createElement("div");
    temp.innerHTML = content.trim();

    const headerEl = temp.querySelector(".sticky-header, header");
    const footerEl = temp.querySelector("footer, .minimalist-footer");

    if (headerEl) {
      const newHeader = headerEl.cloneNode(true);
      document.body.insertBefore(newHeader, document.body.firstChild);
      el.remove();
    } else if (footerEl) {
      const newFooter = footerEl.cloneNode(true);
      document.body.appendChild(newFooter);
      el.remove();
    } else {
      el.innerHTML = temp.innerHTML;
    }
  } catch (e) {
    console.error(`❌ Lỗi load ${file}:`, e);
  }
}

function initFeatures() {
  requestAnimationFrame(() => {
    fixStickyFallback();
    initMobileMenu();
    // KHÔNG gọi initSearchFunctionality() ở đây nữa
    initSmoothScrolling();
  });
}

function initMobileMenu() {
  const hamburger = document.querySelector(".hamburger");
  const mobileMenu = document.querySelector(".mobile-menu-content");
  
  if (!hamburger || !mobileMenu) return;

  const newHamburger = hamburger.cloneNode(true);
  hamburger.replaceWith(newHamburger);

  newHamburger.addEventListener("click", (e) => {
    e.preventDefault();
    e.stopPropagation();
    mobileMenu.classList.toggle("active");
    document.body.style.overflow = mobileMenu.classList.contains("active") ? "hidden" : "";
  });

  document.addEventListener("click", (e) => {
    if (mobileMenu.classList.contains("active") && 
        !e.target.closest(".mobile-menu-content") && 
        !e.target.closest(".hamburger")) {
      mobileMenu.classList.remove("active");
      document.body.style.overflow = "";
    }
  });

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && mobileMenu.classList.contains("active")) {
      mobileMenu.classList.remove("active");
      document.body.style.overflow = "";
    }
  });
}

// XÓA HOÀN TOÀN hàm initSearchFunctionality từ include.js
// Search functionality sẽ được xử lý bởi search.js

function initSmoothScrolling() {
  document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener("click", function (e) {
      e.preventDefault();
      const target = document.querySelector(this.getAttribute("href"));
      if (target) {
        target.scrollIntoView({ behavior: "smooth" });
        const mobileMenu = document.querySelector(".mobile-menu-content");
        if (mobileMenu && mobileMenu.classList.contains("active")) {
          mobileMenu.classList.remove("active");
          document.body.style.overflow = "";
        }
      }
    });
  });
}

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

document.addEventListener("DOMContentLoaded", includeHTML);
