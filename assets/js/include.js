async function includeHTML() {
  const includes = document.querySelectorAll("[data-include]");
  for (let el of includes) {
    const file = el.getAttribute("data-include");
    try {
      const response = await fetch(file);
      if (!response.ok) throw new Error("Không tìm thấy " + file);
      const content = await response.text();
      el.innerHTML = content;
    } catch (e) {
      console.error("Lỗi load include:", e);
    }
  }
}
document.addEventListener("DOMContentLoaded", includeHTML);
