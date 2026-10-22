/* Prototype only: editorial sample records. Future version will consume
   validated JSON exported from Google Sheets, not call Sheets per visitor. */
(() => {
  "use strict";

  const categories = [
    {id:"tin-nguong", title:"Tín ngưỡng", description:"Các hình thức tín ngưỡng và thực hành tâm linh trong đời sống văn hóa Việt Nam."},
    {id:"phong-tuc", title:"Phong tục", description:"Tập quán, nghi lễ và nếp sống được truyền qua nhiều thế hệ."},
    {id:"le-hoi", title:"Lễ hội", description:"Những dịp sinh hoạt văn hóa và cộng đồng gắn với lịch truyền thống."},
    {id:"nghe-thuat", title:"Nghệ thuật", description:"Âm nhạc, sân khấu và các hình thức biểu đạt của cộng đồng."},
    {id:"am-thuc", title:"Ẩm thực", description:"Món ăn, nguyên liệu và những câu chuyện đằng sau mâm cơm Việt."},
    {id:"trang-phuc", title:"Trang phục", description:"Trang phục truyền thống và những biến đổi qua thời gian."},
    {id:"kien-truc", title:"Kiến trúc", description:"Không gian di sản, công trình và nếp sống gắn với kiến trúc."},
    {id:"doi-song", title:"Đời sống", description:"Làng xóm, chợ phiên, nghề thủ công và văn hóa sinh hoạt."}
  ];

  const concepts = [
    {id:"dao-mau", title:"Đạo Mẫu", categories:["tin-nguong"], summary:"Tín ngưỡng thờ các vị Thánh Mẫu trong đời sống tinh thần người Việt.", overview:"Đạo Mẫu là tên gọi phổ biến của các hình thức tín ngưỡng tôn thờ Mẫu ở Việt Nam. Trong đó, tín ngưỡng thờ Mẫu Tam phủ, Tứ phủ gắn với hệ thống các vị thánh, nghi lễ hầu đồng và âm nhạc chầu văn.", related:["hau-dong","hat-van","tam-phu","tho-cung-to-tien"], aliases:["thờ mẫu","tín ngưỡng thờ mẫu"]},
    {id:"tho-cung-to-tien", title:"Thờ cúng tổ tiên", categories:["tin-nguong","phong-tuc","doi-song"], summary:"Tập quán tưởng nhớ, tri ân các thế hệ đã khuất.", overview:"Thờ cúng tổ tiên là một thực hành phổ biến trong nhiều gia đình Việt Nam. Bàn thờ và các ngày giỗ thường là dịp con cháu tưởng nhớ người đi trước và duy trì mối liên kết gia đình.", related:["ban-tho-gia-tien","tet-nguyen-dan","dao-mau"]},
    {id:"hau-dong", title:"Hầu đồng", categories:["tin-nguong","nghe-thuat"], summary:"Nghi lễ gắn với tín ngưỡng thờ Mẫu Tam phủ, Tứ phủ.", overview:"Hầu đồng là một hình thức thực hành nghi lễ trong tín ngưỡng thờ Mẫu, thường có trang phục nghi lễ, âm nhạc chầu văn và các giá đồng. Cần phân biệt ý nghĩa tín ngưỡng của nghi lễ với hoạt động biểu diễn nghệ thuật.", related:["dao-mau","hat-van","tam-phu"]},
    {id:"hat-van", title:"Hát văn", categories:["nghe-thuat","tin-nguong"], summary:"Hình thức âm nhạc truyền thống gắn với các nghi lễ thờ Mẫu.", overview:"Hát văn, còn gọi là chầu văn trong nhiều ngữ cảnh, kết hợp giọng hát và nhạc cụ, có vai trò quan trọng trong nghi lễ hầu đồng và cũng được nghiên cứu như một hình thức nghệ thuật truyền thống.", related:["hau-dong","dao-mau","ca-tru"]},
    {id:"tam-phu", title:"Tam phủ, Tứ phủ", categories:["tin-nguong"], summary:"Hệ thống quan niệm về các phủ trong tín ngưỡng thờ Mẫu.", overview:"Tam phủ và Tứ phủ là những khái niệm liên quan đến hệ thống thần linh trong tín ngưỡng thờ Mẫu. Tên gọi và cách giải thích có thể khác nhau theo địa phương và truyền thống thực hành.", related:["dao-mau","hau-dong"]},
    {id:"tet-nguyen-dan", title:"Tết Nguyên Đán", categories:["le-hoi","phong-tuc","doi-song"], summary:"Dịp đầu năm âm lịch quan trọng trong đời sống người Việt.", overview:"Tết Nguyên Đán là dịp đoàn tụ gia đình, tưởng nhớ tổ tiên và đón chào năm mới. Phong tục ngày Tết đa dạng theo từng vùng miền và cộng đồng.", related:["banh-chung","li-xi","hoa-dao","tho-cung-to-tien"], aliases:["tết cổ truyền","tết"]},
    {id:"banh-chung", title:"Bánh chưng", categories:["am-thuc","phong-tuc","le-hoi"], summary:"Món bánh truyền thống đặc biệt gắn với dịp Tết.", overview:"Bánh chưng thường được làm từ gạo nếp, đậu xanh, thịt và lá gói. Món bánh gắn với dịp Tết và nhiều câu chuyện về nguồn cội, gia đình và truyền thống Việt Nam.", related:["tet-nguyen-dan","tho-cung-to-tien","nuoc-mam"]},
    {id:"li-xi", title:"Lì xì", categories:["phong-tuc","le-hoi"], summary:"Tập tục tặng tiền mừng tuổi trong dịp năm mới.", overview:"Lì xì là tập tục mừng tuổi phổ biến trong dịp Tết, thường đi kèm lời chúc tốt lành. Hình thức và ý nghĩa có những biến thể giữa các vùng miền và gia đình.", related:["tet-nguyen-dan"]},
    {id:"hoa-dao", title:"Hoa đào ngày Tết", categories:["phong-tuc","le-hoi"], summary:"Một hình ảnh quen thuộc của Tết tại nhiều tỉnh miền Bắc.", overview:"Hoa đào thường được dùng trang trí trong dịp Tết ở miền Bắc Việt Nam, mang ý nghĩa về mùa xuân và sự khởi đầu. Phong tục trưng hoa thay đổi theo khu vực.", related:["tet-nguyen-dan"]},
    {id:"le-hoi-den-hung", title:"Giỗ Tổ Hùng Vương", categories:["le-hoi","tin-nguong"], summary:"Ngày lễ tưởng nhớ các Vua Hùng và truyền thống hướng về cội nguồn.", overview:"Giỗ Tổ Hùng Vương diễn ra vào mùng 10 tháng Ba âm lịch, gắn với các hoạt động tưởng niệm và sinh hoạt văn hóa tại Đền Hùng, Phú Thọ và nhiều nơi khác.", related:["tho-cung-to-tien","tet-nguyen-dan"], aliases:["lễ hội đền hùng"]},
    {id:"quan-ho", title:"Dân ca Quan họ", categories:["nghe-thuat","le-hoi"], summary:"Hình thức dân ca đặc trưng của vùng Kinh Bắc.", overview:"Quan họ là hình thức hát đối đáp gắn với sinh hoạt văn hóa cộng đồng ở Bắc Ninh, Bắc Giang và vùng Kinh Bắc. Các làn điệu và lối hát có hệ thống quy ước đặc trưng.", related:["ca-tru","mua-roi-nuoc"]},
    {id:"ca-tru", title:"Ca trù", categories:["nghe-thuat"], summary:"Loại hình nghệ thuật trình diễn với giọng hát và nhạc cụ đặc trưng.", overview:"Ca trù là loại hình diễn xướng truyền thống có sự kết hợp giữa giọng hát, phách, đàn đáy và trống chầu trong nhiều không gian trình diễn khác nhau.", related:["hat-van","quan-ho"]},
    {id:"mua-roi-nuoc", title:"Múa rối nước", categories:["nghe-thuat","doi-song"], summary:"Nghệ thuật sân khấu dân gian biểu diễn trên mặt nước.", overview:"Múa rối nước là hình thức sân khấu dân gian, trong đó con rối được điều khiển trên mặt nước, thường phản ánh đời sống nông nghiệp và các tích truyện dân gian.", related:["quan-ho","lang-viet"]},
    {id:"ao-dai", title:"Áo dài", categories:["trang-phuc","phong-tuc"], summary:"Trang phục gắn với nhiều bối cảnh văn hóa và đời sống Việt Nam.", overview:"Áo dài Việt Nam có lịch sử biến đổi về kiểu dáng và cách sử dụng. Ngày nay, áo dài xuất hiện trong đời sống, lễ hội, giáo dục và các sự kiện trang trọng.", related:["trang-phuc-dan-toc","tet-nguyen-dan"]},
    {id:"trang-phuc-dan-toc", title:"Trang phục các dân tộc", categories:["trang-phuc","doi-song"], summary:"Những chất liệu, hoa văn và cách mặc đa dạng giữa các cộng đồng.", overview:"Các cộng đồng dân tộc ở Việt Nam có nhiều hình thức trang phục đặc trưng, thể hiện điều kiện sống, kỹ thuật dệt nhuộm, lễ nghi và bản sắc cộng đồng.", related:["ao-dai","cho-phien","lang-nghe"]},
    {id:"pho", title:"Phở", categories:["am-thuc","doi-song"], summary:"Món ăn với bánh phở, nước dùng và nhiều biến thể vùng miền.", overview:"Phở là món ăn quen thuộc của Việt Nam, thường gồm bánh phở, nước dùng và thịt. Cách nấu, gia vị và cách ăn có những khác biệt đáng chú ý theo vùng miền.", related:["nuoc-mam","cho-phien"]},
    {id:"nuoc-mam", title:"Nước mắm", categories:["am-thuc","doi-song"], summary:"Gia vị lên men truyền thống có vai trò lớn trong ẩm thực Việt.", overview:"Nước mắm thường được sản xuất bằng quá trình ủ chượp cá với muối. Đây là một gia vị quan trọng trong nhiều cách chế biến và ăn uống ở Việt Nam.", related:["pho","banh-chung"]},
    {id:"cho-phien", title:"Chợ phiên", categories:["doi-song","phong-tuc"], summary:"Hình thức họp chợ theo những ngày nhất định.", overview:"Chợ phiên thường họp theo lịch định kỳ, là nơi giao thương và gặp gỡ cộng đồng. Ở nhiều vùng miền núi, chợ phiên còn gắn với trang phục, ẩm thực và sinh hoạt văn hóa địa phương.", related:["trang-phuc-dan-toc","lang-nghe","pho"]},
    {id:"lang-viet", title:"Làng Việt", categories:["doi-song","kien-truc"], summary:"Không gian cư trú và tổ chức cộng đồng tại nông thôn Việt Nam.", overview:"Làng Việt là một kiểu không gian cộng đồng với nhiều đặc trưng về cư trú, quan hệ xã hội và tín ngưỡng. Cấu trúc làng có nhiều biến thể theo vùng miền và giai đoạn lịch sử.", related:["mua-roi-nuoc","lang-nghe","ban-tho-gia-tien"]},
    {id:"pho-co-hoi-an", title:"Phố cổ Hội An", categories:["kien-truc","doi-song"], summary:"Đô thị di sản với lịch sử giao thương và giao thoa văn hóa.", overview:"Phố cổ Hội An phản ánh lịch sử của một thương cảng qua kiến trúc nhà ở, hội quán, tín ngưỡng và sinh hoạt đô thị. Nhiều lớp ảnh hưởng văn hóa cùng hiện diện trong không gian di sản.", related:["dai-noi-hue","lang-viet"], article:"../tim-hieu-lich-su-pho-co-hoi-an-di-san-van-hoa-the-gioi-viet-nam/"},
    {id:"dai-noi-hue", title:"Đại Nội Huế", categories:["kien-truc"], summary:"Không gian kiến trúc cung đình thuộc Quần thể Di tích Cố đô Huế.", overview:"Đại Nội Huế là khu vực trọng yếu của kinh đô triều Nguyễn, gồm nhiều công trình và không gian gắn với sinh hoạt, nghi lễ và quản trị cung đình.", related:["pho-co-hoi-an"], article:"../huong-dan-tham-quan-tim-hieu-dai-noi-hue-a-z/"},
    {id:"ban-tho-gia-tien", title:"Bàn thờ gia tiên", categories:["phong-tuc","tin-nguong","doi-song"], summary:"Không gian tưởng nhớ tổ tiên trong nhiều gia đình Việt.", overview:"Bàn thờ gia tiên là không gian thờ cúng được bố trí theo phong tục và điều kiện của mỗi gia đình, thường gắn với ngày giỗ, ngày Tết và những dịp quan trọng.", related:["tho-cung-to-tien","tet-nguyen-dan","lang-viet"]},
    {id:"lang-nghe", title:"Làng nghề truyền thống", categories:["doi-song","nghe-thuat"], summary:"Cộng đồng gắn với nghề thủ công và kỹ năng được truyền qua nhiều thế hệ.", overview:"Làng nghề truyền thống là nơi nghề thủ công được duy trì trong đời sống cộng đồng, liên quan đến kỹ thuật, nguyên liệu, thương mại và bản sắc địa phương.", related:["lang-viet","cho-phien","trang-phuc-dan-toc"]}
  ];

  const byId = new Map(concepts.map((item) => [item.id, item]));
  const categoryById = new Map(categories.map((item) => [item.id, item]));
  const list = document.getElementById("cv-concept-list");
  const categoryNav = document.getElementById("cv-categories");
  const categorySelect = document.getElementById("cv-mobile-category");
  const detail = document.getElementById("cv-detail");
  const sheet = document.getElementById("cv-mobile-sheet");
  const sheetBody = document.getElementById("cv-sheet-body");
  const backdrop = document.getElementById("cv-sheet-backdrop");
  const search = document.getElementById("cv-search");
  const clear = document.getElementById("cv-clear");
  const resultCount = document.getElementById("cv-result-count");
  const mobile = window.matchMedia("(max-width: 767px)");

  let activeCategory = "tin-nguong";
  let activeConcept = "dao-mau";
  let query = "";
  let lastTrigger = null;

  const el = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  };

  function normalized(value) {
    return String(value || "").normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "").replace(/đ/g, "d").replace(/Đ/g, "D").toLowerCase().trim();
  }

  function matches(item, value) {
    const text = [item.title, item.summary, item.overview, ...(item.aliases || []),
      ...item.categories.map((id) => categoryById.get(id)?.title || "")].join(" ");
    return normalized(text).includes(normalized(value));
  }

  function visibleConcepts() {
    if (query) return concepts.filter((item) => matches(item, query));
    return concepts.filter((item) => item.categories.includes(activeCategory));
  }

  function relatedConcepts(item) {
    const ids = new Set(item.related || []);
    for (const other of concepts) {
      if (other.related && other.related.includes(item.id)) ids.add(other.id);
    }
    return [...ids].filter((id) => byId.has(id) && id !== item.id).map((id) => byId.get(id)).slice(0, 7);
  }

  function setCategory(id) {
    if (!categoryById.has(id)) return;
    activeCategory = id;
    query = "";
    search.value = "";
    clear.hidden = true;
    const first = visibleConcepts()[0];
    activeConcept = first ? first.id : null;
    closeSheet(false);
    render();
  }

  function selectConcept(id, openOnMobile = true) {
    const concept = byId.get(id);
    if (!concept) return;
    activeConcept = id;
    for (const button of list.querySelectorAll("[data-concept]")) {
      const selected = button.dataset.concept === id;
      button.classList.toggle("is-selected", selected);
      button.setAttribute("aria-pressed", String(selected));
    }
    renderDetail(detail, concept);
    if (mobile.matches && openOnMobile) openSheet(concept);
    else if (mobile.matches && !sheet.hidden) renderDetail(sheetBody, concept);
  }

  function chooseConcept(id) {
    const item = byId.get(id);
    if (!item) return;
    if (query || !item.categories.includes(activeCategory)) {
      activeCategory = item.categories[0];
      query = "";
      search.value = "";
      clear.hidden = true;
      render();
    }
    selectConcept(id, true);
  }

  function renderCategories() {
    categoryNav.replaceChildren(el("h3", "", "Chủ đề văn hóa"));
    categorySelect.replaceChildren();
    for (const cat of categories) {
      const button = el("button", "cv-category" + (!query && activeCategory === cat.id ? " is-active" : ""));
      button.type = "button";
      button.dataset.category = cat.id;
      button.append(el("span", "", cat.title), el("span", "", "›"));
      if (!query && activeCategory === cat.id) button.setAttribute("aria-current", "true");
      button.addEventListener("click", () => setCategory(cat.id));
      categoryNav.append(button);
      const option = el("option", "", cat.title);
      option.value = cat.id;
      categorySelect.append(option);
    }
    categorySelect.value = activeCategory;
  }

  function renderList() {
    const category = categoryById.get(activeCategory);
    document.getElementById("cv-crumb").textContent = query ? "Tìm kiếm" : category.title;
    document.getElementById("cv-topic-title").textContent = query ? "Kết quả tìm kiếm" : category.title;
    document.getElementById("cv-topic-desc").textContent = query ? "Kết quả phù hợp với “" + query + "” trong các lĩnh vực văn hóa." : category.description;

    const items = visibleConcepts();
    resultCount.textContent = items.length + " khái niệm";
    list.replaceChildren();
    if (!items.length) {
      activeConcept = null;
      list.append(el("p", "cv-empty", "Chưa tìm thấy khái niệm phù hợp. Thử một từ khóa khác."));
      renderDetail(detail, null);
      return;
    }
    if (!items.some((item) => item.id === activeConcept)) activeConcept = items[0].id;
    for (const item of items) {
      const button = el("button", "cv-concept" + (item.id === activeConcept ? " is-selected" : ""));
      button.type = "button";
      button.dataset.concept = item.id;
      button.setAttribute("aria-pressed", String(item.id === activeConcept));
      const copy = el("span");
      copy.append(el("strong", "", item.title), el("small", "", item.summary));
      button.append(copy, el("span", "", "›"));
      button.addEventListener("click", () => {lastTrigger = button; selectConcept(item.id, true);});
      button.addEventListener("mouseenter", () => {
        if (!mobile.matches && window.matchMedia("(hover: hover)").matches) selectConcept(item.id, false);
      });
      list.append(button);
    }
    renderDetail(detail, byId.get(activeConcept));
  }

  function renderDetail(target, item) {
    target.replaceChildren();
    if (!item) {
      target.append(el("p", "cv-detail-quiet", "Chọn một khái niệm để xem nội dung."));
      return;
    }
    const top = el("div", "cv-detail-top");
    top.append(el("p", "cv-detail-kicker", "TỔNG QUAN KHÁI NIỆM"));
    target.append(top, el("h3", "", item.title));
    const tags = el("div", "cv-tags");
    for (const id of item.categories) {
      tags.append(el("span", "cv-tag", categoryById.get(id)?.title || id));
    }
    target.append(tags, el("p", "", item.overview));
    target.append(el("p", "cv-detail-source", "Nội dung minh họa — cần biên tập và xác minh trước khi xuất bản chính thức."));
    if (item.article) {
      const a = el("a", "cv-article-link", "Đọc bài viết  →");
      a.href = item.article;
      target.append(a);
    } else {
      target.append(el("p", "cv-article-unavailable", "Bài chuyên sâu đang được biên soạn."));
    }
    const connected = relatedConcepts(item);
    if (connected.length) {
      const related = el("section", "cv-related");
      related.append(el("h4", "", "Khái niệm liên quan"));
      for (const other of connected) {
        const button = el("button", "", other.title + "   ›");
        button.type = "button";
        button.addEventListener("click", () => chooseConcept(other.id));
        related.append(button);
      }
      target.append(related);
    }
  }

  function render() {
    renderCategories();
    renderList();
  }

  function openSheet(concept) {
    if (!mobile.matches) return;
    backdrop.hidden = false;
    sheet.hidden = false;
    sheet.setAttribute("aria-hidden", "false");
    document.body.classList.add("cv-sheet-open");
    renderDetail(sheetBody, concept);
    document.getElementById("cv-sheet-close").focus();
  }

  function closeSheet(restoreFocus = true) {
    sheet.hidden = true;
    backdrop.hidden = true;
    sheet.setAttribute("aria-hidden", "true");
    document.body.classList.remove("cv-sheet-open");
    if (restoreFocus && lastTrigger && lastTrigger.isConnected) lastTrigger.focus();
  }

  function changeQuery() {
    query = search.value.trim();
    clear.hidden = !query;
    closeSheet(false);
    render();
  }

  search.addEventListener("input", changeQuery);
  search.addEventListener("keydown", (event) => {
    if (event.key === "Enter") {
      event.preventDefault();
      document.getElementById("cv-explore-title").scrollIntoView({behavior:"smooth",block:"start"});
    }
  });
  clear.addEventListener("click", () => { search.value = ""; changeQuery(); search.focus(); });
  document.getElementById("cv-search-button").addEventListener("click", () => {
    document.getElementById("cv-explore-title").scrollIntoView({behavior:"smooth",block:"start"});
  });
  document.querySelectorAll("[data-choose]").forEach((button) => {
    button.addEventListener("click", () => chooseConcept(button.dataset.choose));
  });
  categorySelect.addEventListener("change", () => setCategory(categorySelect.value));
  document.getElementById("cv-sheet-close").addEventListener("click", () => closeSheet());
  backdrop.addEventListener("click", () => closeSheet());
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !sheet.hidden) closeSheet();
    if (event.key !== "Tab" || sheet.hidden) return;
    const focusables = [...sheet.querySelectorAll('button:not([disabled]),a[href]')];
    if (!focusables.length) return;
    const first = focusables[0], last = focusables[focusables.length - 1];
    if (event.shiftKey && document.activeElement === first) {event.preventDefault(); last.focus();}
    if (!event.shiftKey && document.activeElement === last) {event.preventDefault(); first.focus();}
  });
  mobile.addEventListener("change", () => closeSheet(false));
  render();
})();