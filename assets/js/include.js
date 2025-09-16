// assets/js/include.js
async function includeHTML() {
  const includes = document.querySelectorAll("[data-include]");

  for (let el of includes) {
    const file = el.getAttribute("data-include");
    try {
      console.log("👉 Đang load:", file);
      const response = await fetch(file);
      if (!response.ok) throw new Error("Không tìm thấy " + file);
      const content = await response.text();

      // parse content
      const temp = document.createElement("div");
      temp.innerHTML = content.trim();

      // TÌM header/footer trong nội dung include (nếu có)
      const headerEl = temp.querySelector(".sticky-header") || temp.querySelector("header");
      const footerEl = temp.querySelector("footer") || temp.querySelector(".minimalist-footer");

      if (headerEl) {
        // Nếu placeholder là chính thẻ <header> (không muốn lồng), thay thế
        if (el.tagName.toLowerCase() === "header") {
          el.replaceWith(headerEl);
        } else {
          // đưa header lên là con trực tiếp của <body> (đặt ở đầu)
          document.body.insertBefore(headerEl, document.body.firstChild);
          // xóa placeholder
          el.remove();
        }
      } else if (footerEl) {
        // append footer vào cuối body
        document.body.appendChild(footerEl);
        el.remove();
      } else {
        // mặc định: thay placeholder bằng nội dung include (không có header/footer)
        el.insertAdjacentHTML("afterend", temp.innerHTML);
        el.remove();
      }

      console.log("✅ Load thành công:", file);
    } catch (e) {
      console.error("❌ Lỗi load include:", file, e);
    }
  }

  // Sau khi include xong -> kiểm tra sticky; nếu sticky không hợp lệ thì chuyển về fixed (fallback)
  setTimeout(() => {
    const header = document.querySelector(".sticky-header");
    if (header) {
      if (isStickyBroken(header)) {
        // fallback: fixed + padding-top cho body
        header.style.position = "fixed";
        header.style.top = "0";
        header.style.left = "0";
        header.style.width = "100%";
        header.style.zIndex = "1000";
        document.body.style.paddingTop = header.offsetHeight + "px";
        console.warn("⚠️ sticky không hoạt động -> đã bật fallback position:fixed");
      } else {
        // giữ z-index/nền cho an toàn
        header.style.zIndex = header.style.zIndex || "1000";
        if (!getComputedStyle(header).backgroundColor || getComputedStyle(header).backgroundColor === "rgba(0, 0, 0, 0)") {
          header.style.background = header.style.background || "white";
        }
      }
    }

    // gọi initPage nếu có (menu, hamburger, v.v.)
    if (typeof initPage === "function") initPage();
  }, 60);
}

// Kiểm tra nhanh xem có ancestor nào "phá" sticky không
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
