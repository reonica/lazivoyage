async function includeHTML() {
  const includes = document.querySelectorAll("[data-include]");
  for (let el of includes) {
    const file = el.getAttribute("data-include");
    try {
      console.log("👉 Đang load:", file); // log debug

      const response = await fetch(file);
      if (!response.ok) throw new Error("Không tìm thấy " + file);

      const content = await response.text();
      el.innerHTML = content;

      console.log("✅ Load thành công:", file);
    } catch (e) {
      console.error("❌ Lỗi load include:", file, e);
    }
  }

  // 👉 Nếu bạn có script init menu/header thì gọi lại ở đây
  if (typeof initPage === "function") {
    initPage();
  }
}

document.addEventListener("DOMContentLoaded", includeHTML);
