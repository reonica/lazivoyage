/* Progressive enhancement. Page navigation and reading work without JavaScript. */
(() => {
  'use strict';
  const $=(s,root=document)=>root.querySelector(s), $$=(s,root=document)=>[...root.querySelectorAll(s)];
  // Resolve from this root script so both / and /repository/ work without rebuilding.
  const base=new URL('.', document.currentScript.src).pathname.replace(/\/$/, '');
  const norm=value=>String(value||'').toLocaleLowerCase('vi').replace(/đ/g,'d').normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/\s+/g,' ').trim();
  const reduced=()=>matchMedia('(prefers-reduced-motion: reduce)').matches;
  const create=(tag,cls,text)=>{const node=document.createElement(tag);if(cls)node.className=cls;if(text!==undefined)node.textContent=text;return node;};
  $$('.js-only').forEach(node=>node.hidden=false);
  const cache=new Map();
  function getData(name) {
    if(!cache.has(name)) {
      const promise=fetch(base+'/data/'+name+'.json').then(response=>{if(!response.ok)throw Error('Không tải được dữ liệu.');return response.json();}).then(data=>name==='search-index'?data.map(post=>({...post,path:post.path.startsWith('/')?base+post.path:post.path,cover:{...post.cover,src:post.cover.src.startsWith('/')?base+post.cover.src:post.cover.src}})):data).catch(error=>{cache.delete(name);throw error;});
      cache.set(name,promise);
    }
    return cache.get(name);
  }
  function card(post) {
    const article=create('article','ls-story'), imageLink=create('a','ls-story-image');
    imageLink.href=post.path;imageLink.tabIndex=-1;imageLink.setAttribute('aria-hidden','true');
    const img=create('img');img.src=post.cover.src;img.alt=post.cover.alt;img.loading='lazy';img.decoding='async';img.width=post.cover.width;img.height=post.cover.height;imageLink.append(img);
    const body=create('div','la-post-body'), category=create('p','ls-story-topic',post.category),title=create('h2'),link=create('a','',post.title);
    link.href=post.path;title.append(link);
    const excerpt=create('p','ls-story-excerpt',post.excerpt),date=create('p','ls-story-date'),time=create('time');
    time.dateTime=post.date;time.textContent=new Intl.DateTimeFormat('vi-VN',{day:'2-digit',month:'2-digit',year:'numeric',timeZone:'Asia/Ho_Chi_Minh'}).format(new Date(post.date));
    date.append(time,document.createTextNode(' · '+post.minutes+' phút đọc'));
    body.append(category,title,excerpt,date);article.append(imageLink,body);return article;
  }
  function clientPager(root,page,total,onPage) {
    root.replaceChildren();if(total<2)return;
    const nav=create('nav','ls-pagination');nav.setAttribute('aria-label','Phân trang');
    function button(label,value,disabled=false,current=false) {
      const b=create('button','',label);b.type='button';b.disabled=disabled;
      if(current)b.setAttribute('aria-current','page');
      b.addEventListener('click',()=>onPage(value));nav.append(b);
    }
    button('Trước',page-1,page===1);
    const nums=[...new Set([1,page-1,page,page+1,total].filter(n=>n>0&&n<=total))].sort((a,b)=>a-b);
    let last=0;nums.forEach(n=>{if(n-last>1)nav.append(create('span','','…'));button(String(n),n,false,n===page);last=n;});
    button('Sau',page+1,page===total);root.append(nav);
  }
  function scrollToResults(node) {node.scrollIntoView({behavior:reduced()?'auto':'smooth',block:'start'});node.tabIndex=-1;node.focus({preventScroll:true});}

  // Swipe, arrows and text category tabs share the same native scroll position.
  $$('[data-carousel-track]').forEach(track=>{
    const key=track.dataset.carouselTrack, controls=$('[data-carousel-controls="'+key+'"]');
    if(!controls)return;
    const prev=$('[data-carousel-prev]',controls),next=$('[data-carousel-next]',controls),dots=$('[data-carousel-dots]',controls),counter=$('.la-carousel-counter',controls);
    const tabs=key==='categories'?$$('[data-category-index]'):[];
    let stops=[0],index=0,count=-1,active=false,frame=0;
    const clamp=n=>Math.max(0,Math.min(track.scrollWidth-track.clientWidth,n));
    function update() {
      index=stops.reduce((best,n,i)=>Math.abs(n-track.scrollLeft)<Math.abs(stops[best]-track.scrollLeft)?i:best,0);
      counter.textContent=(index+1)+' / '+stops.length;prev.disabled=index===0;next.disabled=index===stops.length-1;
      $$('button',dots).forEach((b,i)=>b.setAttribute('aria-pressed',String(i===index)));
      if(active)tabs.forEach((b,i)=>b.setAttribute('aria-pressed',String(i===index)));
    }
    function move(n) {track.scrollTo({left:stops[Math.max(0,Math.min(stops.length-1,n))],behavior:reduced()?'auto':'smooth'});}
    function refresh() {
      active=getComputedStyle(track).display==='flex';const max=Math.max(0,track.scrollWidth-track.clientWidth);
      controls.hidden=!active||max<2;stops=[0];
      if(!active)track.scrollLeft=0;
      else if(max>1) {
        const box=track.getBoundingClientRect();
        [...track.children].forEach(child=>{const stop=clamp(child.getBoundingClientRect().left-box.left+track.scrollLeft);if(stop>stops.at(-1)+1)stops.push(stop);});
        if(max>stops.at(-1)+1)stops.push(max);
      }
      if(count!==stops.length) {
        dots.replaceChildren();stops.forEach((_,i)=>{const b=create('button','la-page-dot');b.type='button';b.setAttribute('aria-label','Xem nhóm '+(i+1));b.setAttribute('aria-controls',track.id);b.addEventListener('click',()=>move(i));dots.append(b);});count=stops.length;
      }
      update();
    }
    prev.addEventListener('click',()=>move(index-1));next.addEventListener('click',()=>move(index+1));
    tabs.forEach((b,i)=>{b.addEventListener('click',()=>move(i));b.addEventListener('keydown',event=>{if(['ArrowLeft','ArrowRight','Home','End'].includes(event.key)){event.preventDefault();const n=event.key==='Home'?0:event.key==='End'?tabs.length-1:Math.max(0,Math.min(tabs.length-1,i+(event.key==='ArrowLeft'?-1:1)));tabs[n].focus();move(n);}});});
    track.addEventListener('scroll',()=>{if(!frame)frame=requestAnimationFrame(()=>{frame=0;update();});},{passive:true});
    if('ResizeObserver' in window)new ResizeObserver(refresh).observe(track);else window.addEventListener('resize',refresh);
    refresh();
  });
  const mq=matchMedia('(max-width:980px)');
  const updateDetails=()=>$$('[data-responsive-details]').forEach(node=>node.open=!mq.matches);
  updateDetails();mq.addEventListener('change',updateDetails);
  const menu=$('.la-menu');
  if(menu){document.addEventListener('click',e=>{if(!menu.contains(e.target))menu.open=false;});document.addEventListener('keydown',e=>{if(e.key==='Escape'&&menu.open){menu.open=false;$('summary',menu).focus();}});}

  const search=$('[data-search-page]');
  if(search) {
    const form=$('form',search),input=$('input[name="q"]',search),status=$('[data-search-status]',search),results=$('[data-search-results]',search),pages=$('[data-search-pagination]',search);
    let found=[],generation=0;
    function show(page=1,scroll=false) {
      const size=9,total=Math.max(1,Math.ceil(found.length/size));
      results.replaceChildren(...found.slice((page-1)*size,page*size).map(card));
      if(!found.length)results.append(create('p','ls-note','Chưa tìm thấy bài phù hợp. Thử tên địa điểm hoặc một từ khóa ngắn hơn.'));
      status.textContent=found.length+' bài viết cho “'+input.value.trim()+'”';
      clientPager(pages,page,total,n=>show(n,true));if(scroll)scrollToResults(results);
    }
    async function run(updateURL=false) {
      const id=++generation,q=input.value.trim().slice(0,200);input.value=q;
      if(updateURL){const url=new URL(location.href);q?url.searchParams.set('q',q):url.searchParams.delete('q');history.pushState({},'',url);}
      if(!q){results.replaceChildren();pages.replaceChildren();status.textContent='Nhập từ khóa để tìm bài viết trên Lazi.';return;}
      status.textContent='Đang tìm kiếm…';
      try {const data=await getData('search-index');if(id!==generation)return;const terms=norm(q).split(' ').filter(Boolean);found=data.filter(p=>terms.every(term=>p.search.includes(term)));show();}
      catch {if(id===generation)status.textContent='Chưa tải được dữ liệu tìm kiếm. Vui lòng thử lại hoặc mở Blog.';}
    }
    form.addEventListener('submit',event=>{event.preventDefault();run(true);});
    const restore=()=>{input.value=new URLSearchParams(location.search).get('q')?.slice(0,200)||'';run();};restore();window.addEventListener('popstate',restore);
  }
  const archive=$('[data-archive]');
  if(archive) {
    const sort=$('[data-archive-sort]',archive),grid=$('[data-archive-grid]',archive),status=$('[data-archive-count]',archive),pages=$('[data-archive-pagination]',archive),metadata=JSON.parse($('[data-archive-data]',archive).textContent);
    let sequence=0;
    sort.addEventListener('change',async()=>{
      const id=++sequence;
      try {
        const all=await getData('search-index');if(id!==sequence)return;
        const ids=new Set(metadata.ids),found=all.filter(x=>ids.has(x.id));
        found.sort((a,b)=>sort.value==='old'?new Date(a.date)-new Date(b.date):new Date(b.date)-new Date(a.date));
        $('[data-archive-feature]',archive).hidden=true;
        const show=(page,scroll=false)=>{
          const total=Math.max(1,Math.ceil(found.length/metadata.size));grid.replaceChildren(...found.slice((page-1)*metadata.size,page*metadata.size).map(card));status.textContent=found.length+' bài viết · Trang '+page+' / '+total;
          clientPager(pages,page,total,n=>show(n,true));if(scroll)scrollToResults(grid);
        };show(1);
      } catch {status.textContent='Chưa tải được dữ liệu sắp xếp. Các bài hiện tại vẫn có thể đọc bình thường.';}
    });
  }

  function field(list,title,value,lang) {
    if(!value)return;const row=create('div'),term=create('dt','',title),desc=create('dd','',value);if(lang)desc.lang=lang;row.append(term,desc);list.append(row);
  }
  function detail(title) {const details=create('details','tool-details'),summary=create('summary','',title),list=create('dl','tool-fields');details.append(summary,list);return {details,list};}
  function eventCard(item) {
    const article=create('article','ls-timeline-item');article.id=item.id;
    const year=v=>Number(v)<0?Math.abs(Number(v))+' TCN':v;
    const years=!item.end||item.start===item.end?year(item.start):year(item.start)+'–'+year(item.end);
    const body=create('div'),info=detail('Thông tin & nguồn');
    body.append(create('p','ls-kicker',item.period||item.dynasty||'Sử Việt'),create('h2','',item.title),create('p','',item.description));
    [['Giai đoạn',item.period],['Vương triều',item.dynasty],['Người trị vì',item.ruler],['Niên hiệu',item.era],['Tên nước',item.country],['Loại sự kiện',item.type],['Địa điểm',item.location],['Ngày bắt đầu',item.startDate],['Ngày kết thúc',item.endDate],['Nguồn',item.source],['Trạng thái',item.verified?'Đã đánh dấu kiểm chứng trong dữ liệu nguồn':'Chưa đánh dấu kiểm chứng trong dữ liệu nguồn']].forEach(([label,value])=>field(info.list,label,value));
    body.append(info.details);article.append(create('p','ls-timeline-year',years),body);return article;
  }
  const tool=$('[data-tool="history"]');
  if(tool) {
    const form=$('[data-tool-filters]',tool),status=$('[data-tool-status]',tool),results=$('[data-tool-results]',tool),pages=$('[data-tool-pages]',tool),size=Number(tool.dataset.toolSize);
    const input=$('[name="q"]',form),filter=$('[name="filter"]',form),secondary=$('[name="secondary"]',form);
    let generation=0,timer;
    async function run(page=1,updateURL=true,scroll=false) {
      const id=++generation;status.textContent='Đang tra cứu…';
      const q=input.value.slice(0,200),f=filter.value,s=secondary.value;
      if(updateURL){const url=new URL(location.href);[['q',q],['filter',f],['secondary',s]].forEach(([k,v])=>v?url.searchParams.set(k,v):url.searchParams.delete(k));url.searchParams.set('p',String(page));history.replaceState({},'',url);}
      try {
        const all=await getData('history');if(id!==generation)return;
        const terms=norm(q).split(' ').filter(Boolean);
        const found=all.filter(item=>(!f||item.period===f)&&(!s||item.start===s)&&terms.every(term=>norm(Object.values(item).join(' ')).includes(term)));
        const total=Math.max(1,Math.ceil(found.length/size));page=Math.max(1,Math.min(Math.floor(Number(page))||1,total));
        const slice=found.slice((page-1)*size,page*size);results.replaceChildren(...slice.map(eventCard));
        if(!found.length)results.append(create('p','ls-note','Không tìm thấy kết quả phù hợp. Thử từ khóa khác hoặc xóa bộ lọc.'));
        status.textContent=found.length+' sự kiện · Trang '+page+' / '+total;
        clientPager(pages,page,total,n=>run(n,true,true));if(scroll)scrollToResults(results);
      } catch {if(id===generation)status.textContent='Chưa tải được dữ liệu tra cứu. Bạn vẫn có thể đọc và chuyển trang bằng các liên kết bên dưới.';}
    }
    form.addEventListener('submit',event=>{event.preventDefault();clearTimeout(timer);run();});
    input.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>run(),250);});
    [filter,secondary].forEach(node=>node.addEventListener('change',()=>run()));
    form.addEventListener('reset',event=>{event.preventDefault();clearTimeout(timer);input.value='';filter.value='';secondary.value='';run();});
    const query=new URLSearchParams(location.search);
    if(['q','filter','secondary','p'].some(key=>query.has(key))){input.value=(query.get('q')||'').slice(0,200);filter.value=query.get('filter')||'';secondary.value=query.get('secondary')||'';run(Number(query.get('p'))||1,false);}
  }

  $$('[data-form]').forEach(form=>{
    const status=$('[data-form-status]',form);
    form.addEventListener('submit',event=>{
      if(form.dataset.formProvider!=='email')return; // Native submission handles Netlify and configured form endpoints.
      event.preventDefault();if(!form.reportValidity())return;
      const data=new FormData(form);if(data.get('bot-field'))return;
      const newsletter=form.dataset.formKind==='newsletter';
      const subject=newsletter?'Đăng ký nhận bản tin Lazi Voyage':'Liên hệ Lazi Voyage';
      const body=['Tên: '+data.get('name'),'Email: '+data.get('email'),data.get('article')?'Bài viết: '+data.get('article'):'',newsletter?'Tôi muốn nhận bản tin và đồng ý với chính sách bảo mật.':'Tôi đồng ý với chính sách bảo mật.','',data.get('message')||''].filter((line,i)=>line||i>3).join('\n');
      location.href='mailto:'+encodeURIComponent(form.dataset.email)+'?subject='+encodeURIComponent(subject)+'&body='+encodeURIComponent(body);
      status.textContent='Hãy gửi thư từ ứng dụng email vừa mở. Nếu ứng dụng không mở, gửi trực tiếp đến '+form.dataset.email+'.';
    });
  });
  const copy=$('[data-copy-link]');
  if(copy)copy.addEventListener('click',async()=>{const status=$('[data-copy-status]');try{await navigator.clipboard.writeText($('link[rel="canonical"]').href);status.textContent='Đã sao chép liên kết.';}catch{status.textContent='Liên kết: '+$('link[rel="canonical"]').href;}});
})();
