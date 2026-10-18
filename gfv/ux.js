/* Glossaire UX v2 — progressive enhancement limited to /gfv/.
   The source of truth remains /data/glossary.json (generated from the original Sheet). */
(() => {
  'use strict';
  const root = document.querySelector('[data-tool="glossary"]');
  if (!root) return;
  const dataURL = new URL('../data/glossary.json', document.currentScript.src);
  const normalize = value => String(value || '').toLocaleLowerCase('fr').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/œ/g, 'oe').replace(/æ/g, 'ae').replace(/\s+/g, ' ').trim();
  const el = (tag, cls, value) => {
    const node = document.createElement(tag);
    if (cls) node.className = cls;
    if (value !== undefined) node.textContent = String(value);
    return node;
  };
  const get = selector => root.querySelector(selector);
  const params = new URLSearchParams(location.search);
  const state = {
    words: [], domain: params.get('domain') || '',
    theme: params.get('theme') || params.get('filter') || '',
    q: (params.get('q') || '').slice(0, 200),
    page: Math.max(1, Number(params.get('p')) || 1),
    chosenId: params.get('word') || '',
    savedOnly: false,
    saved: new Set()
  };
  try { state.saved = new Set(JSON.parse(localStorage.getItem('lazi-glossary-saved-v1') || '[]')); } catch (_) {}
  root.lang = 'fr'; // The study UI is French; Vietnamese translations declare lang=vi individually.
  root.removeAttribute('data-tool'); // Prevent the legacy glossary controller from binding.
  root.classList.add('gf-app');
  root.innerHTML = [
    '<nav class="gf-breadcrumb" aria-label="Fil d’Ariane"><a href="../">Accueil</a><span aria-hidden="true">/</span><span>Glossaire</span></nav>',
    '<header class="gf-intro"><p class="gf-eyebrow">Glossaire franco-vietnamien</p><h1>Les mots en contexte</h1><p>Apprenez en français grâce aux définitions, aux exemples et aux synonymes. La traduction reste facultative.</p></header>',
    '<div class="gf-toolbar">',
      '<label class="gf-search-label" for="gf-search">Rechercher un mot</label>',
      '<div class="gf-search-wrap"><span aria-hidden="true">⌕</span><input id="gf-search" type="search" maxlength="200" autocomplete="off" placeholder="Rechercher un mot en français…" /></div>',
      '<div class="gf-filter-grid">',
        '<label>Domaine<select id="gf-domain"><option value="">Tous les domaines</option></select></label>',
        '<label>Thème<select id="gf-theme"><option value="">Tous les thèmes</option></select></label>',
      '</div>',
      '<div class="gf-toolbar-bottom"><button type="button" class="gf-quiet" id="gf-reset">Effacer les filtres</button><button type="button" class="gf-quiet" id="gf-saved" aria-pressed="false">☆ Mes mots enregistrés</button></div>',
    '</div>',
    '<div class="gf-layout">',
      '<aside class="gf-list-panel" aria-label="Liste de vocabulaire"><div class="gf-panel-top"><strong>Vocabulaire</strong><span id="gf-count" role="status" aria-live="polite">Chargement…</span></div>',
      '<div id="gf-list" class="gf-list" role="listbox" aria-label="Choisissez un mot"></div>',
      '<div id="gf-pager" class="gf-pager"></div></aside>',
      '<section class="gf-detail-panel" id="gf-detail" aria-label="Fiche de vocabulaire" aria-live="polite" aria-atomic="false"><p>Chargement du lexique…</p></section>',
    '</div>',
    '<p class="gf-source">Contenu issu du lexique Lazi Voyage. Les images affichées proviennent des liens du jeu de données ; certains mots n’ont pas d’illustration.</p>'
  ].join('');
  const search = get('#gf-search'), domain = get('#gf-domain'), theme = get('#gf-theme');
  const list = get('#gf-list'), count = get('#gf-count'), pager = get('#gf-pager'), detail = get('#gf-detail'), saveToggle = get('#gf-saved');
  search.value = state.q;
  const collator = new Intl.Collator('fr', {sensitivity:'base'});
  const PAGE_SIZE = 25;
  const audioCache = new Map();
  let currentAudio = null;
  const spoken = (text, status) => {
    const value = String(text || '').trim().slice(0, 260);
    if (!value) return;
    status.textContent = 'Chargement de la voix…';
    const fallback = () => {
      if (!('speechSynthesis' in window)) {
        status.textContent = 'Lecture indisponible. Configurez Azure Speech sur Netlify.';
        return;
      }
      speechSynthesis.cancel();
      const utterance = new SpeechSynthesisUtterance(value);
      utterance.lang = 'fr-FR';
      utterance.rate = 0.9;
      const voices = speechSynthesis.getVoices().filter(v => v.lang.toLowerCase().replace('_','-') === 'fr-fr');
      const male = voices.find(v => /henri|male|homme/i.test(v.name));
      if (male) utterance.voice = male;
      else if (voices.length) utterance.voice = voices[0];
      utterance.onerror = () => { status.textContent = 'Lecture vocale indisponible.'; };
      utterance.onend = () => { status.textContent = ''; };
      status.textContent = 'Voix du navigateur (accent non garanti).';
      speechSynthesis.speak(utterance);
    };
    (async () => {
      try {
        if (currentAudio) { currentAudio.pause(); currentAudio = null; }
        let url = audioCache.get(value);
        if (!url) {
          const response = await fetch('/.netlify/functions/pronounce', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({text:value})
          });
          if (!response.ok) throw new Error('TTS unavailable');
          url = URL.createObjectURL(await response.blob());
          audioCache.set(value, url);
        }
        currentAudio = new Audio(url);
        currentAudio.onended = () => { status.textContent = ''; };
        await currentAudio.play();
        status.textContent = 'Voix française · Henri (Azure)';
      } catch (_) { fallback(); }
    })();
  };
  function button(label, cls, onClick) {
    const node = el('button', cls, label);
    node.type = 'button';
    node.addEventListener('click', onClick);
    return node;
  }
  function selectOptions(node, values, placeholder, selected) {
    node.replaceChildren();
    node.append(new Option(placeholder, ''));
    values.forEach(value => node.append(new Option(value, value)));
    node.value = values.includes(selected) ? selected : '';
    return node.value;
  }
  function filteredThemes() {
    const values = state.words.filter(w => !state.domain || w.domain === state.domain).map(w => w.theme).filter(Boolean);
    return [...new Set(values)].sort(collator.compare);
  }
  function syncFilters() {
    const domains = [...new Set(state.words.map(w => w.domain).filter(Boolean))].sort(collator.compare);
    state.domain = selectOptions(domain, domains, 'Tous les domaines', state.domain);
    state.theme = selectOptions(theme, filteredThemes(), 'Tous les thèmes', state.theme);
  }
  function filteredWords() {
    const terms = normalize(state.q).split(' ').filter(Boolean);
    return state.words.filter(item =>
      (!state.domain || item.domain === state.domain) &&
      (!state.theme || item.theme === state.theme) &&
      (!state.savedOnly || state.saved.has(item.id)) &&
      terms.every(t => item._search.includes(t))
    );
  }
  function updateURL() {
    const url = new URL(location.href);
    const values = {q:state.q, domain:state.domain, theme:state.theme, p:state.page === 1 ? '' : String(state.page), word:state.chosenId};
    ['filter','secondary'].forEach(k => url.searchParams.delete(k));
    Object.entries(values).forEach(([key,value]) => value ? url.searchParams.set(key,String(value)) : url.searchParams.delete(key));
    history.replaceState(null, '', url);
  }
  function imageURL(value) {
    if (typeof value !== 'string' || !value.trim()) return '';
    try {
      const url = new URL(value.trim());
      return ['https:','http:'].includes(url.protocol) ? url.href : '';
    } catch (_) { return ''; }
  }
  function addSection(container, label, value, cls) {
    if (!value) return;
    const section = el('section','gf-section'+(cls?' '+cls:''));
    section.append(el('h3','gf-section-label',label),el('p','',value));
    container.append(section);
  }
  function renderDetail(item) {
    detail.replaceChildren();
    if (!item) {
      detail.append(el('p','gf-empty','Aucun mot ne correspond à votre recherche. Modifiez les filtres ou le mot recherché.'));
      return;
    }
    const header = el('header','gf-word-header'), headings = el('div','gf-headings');
    headings.append(el('h2','gf-word-title',item.word));
    const pronounce = el('p','gf-ipa', [item.pronunciation,item.grammar || item.type].filter(Boolean).join(' · '));
    pronounce.lang = 'fr';
    headings.append(pronounce);
    const actions = el('div','gf-word-actions');
    const save = button(state.saved.has(item.id) ? '★' : '☆','gf-icon-button',() => {
      if (state.saved.has(item.id)) state.saved.delete(item.id); else state.saved.add(item.id);
      try { localStorage.setItem('lazi-glossary-saved-v1', JSON.stringify([...state.saved])); } catch (_) {}
      renderDetail(item);
      if (state.savedOnly) render(false);
    });
    save.title = state.saved.has(item.id) ? 'Retirer des mots enregistrés' : 'Enregistrer ce mot';
    save.setAttribute('aria-label',save.title);
    const play = button('♪ Écouter','gf-audio-button',() => spoken(item.word, audioStatus));
    play.title = 'Écouter la prononciation française';
    actions.append(save,play);
    header.append(headings,actions); detail.append(header);
    const audioStatus = el('p','gf-audio-status');
    audioStatus.setAttribute('role','status');
    detail.append(audioStatus);
    const tags = el('div','gf-tags');
    [item.domain,item.theme,item.type].filter(Boolean).forEach(t => tags.append(el('span','gf-tag',t)));
    detail.append(tags);
    const photoURL = imageURL(item.imageURL);
    if (photoURL) {
      const figure = el('figure','gf-figure'), img = el('img');
      img.src = photoURL;
      img.alt = 'Illustration de « '+item.word+' »';
      img.loading = 'lazy';
      img.decoding = 'async';
      img.addEventListener('error',()=>figure.remove(),{once:true});
      figure.append(img); detail.append(figure);
    }
    const definition = el('section','gf-section gf-definition');
    definition.append(el('h3','gf-section-label','Définition en français'));
    const definitionText = el('p','',item.definition || 'Définition non renseignée.');
    definitionText.lang='fr'; definition.append(definitionText);detail.append(definition);
    if (item.example) {
      const example = el('section','gf-section gf-example');
      const exampleTitle = el('div','gf-example-title');
      exampleTitle.append(el('h3','gf-section-label','Exemple en contexte'));
      const listen = button('♪ Écouter la phrase','gf-example-play',()=>spoken(item.example, audioStatus));
      exampleTitle.append(listen);
      const quote = el('blockquote','',item.example); quote.lang='fr';
      example.append(exampleTitle,quote);detail.append(example);
    }
    const duo = el('div','gf-two-cols');
    addSection(duo,'Synonymes',item.synonyms);
    addSection(duo,'Collocations',item.collocations);
    if (duo.childNodes.length) detail.append(duo);
    if (item.vietnamese) {
      const translation = el('details','gf-disclosure');
      const summary = el('summary','','Voir la traduction vietnamienne');
      const body = el('p','gf-translation',item.vietnamese);body.lang='vi';
      translation.append(summary,body);detail.append(translation);
    }
    if (item.notes || item.family) {
      const more = el('details','gf-disclosure');
      more.append(el('summary','','Notes d’usage et famille lexicale'));
      const info = el('div','gf-more');
      addSection(info,'Notes d’usage',item.notes);
      addSection(info,'Famille lexicale',item.family);
      more.append(info);detail.append(more);
    }
    // Update the active list item without reconstructing the full list.
    list.querySelectorAll('[role="option"]').forEach(node => {
      const active = node.dataset.id === item.id;
      node.classList.toggle('is-selected',active);
      node.setAttribute('aria-selected',String(active));
    });
  }
  function render(scrollToDetail) {
    const found = filteredWords();
    const total = Math.max(1,Math.ceil(found.length/PAGE_SIZE));
    state.page=Math.min(Math.max(1,state.page),total);
    const pageItems=found.slice((state.page-1)*PAGE_SIZE,state.page*PAGE_SIZE);
    const chosen = found.find(w => w.id === state.chosenId) || pageItems[0] || null;
    state.chosenId = chosen ? chosen.id : '';
    // A selected word should always be visible on the current list page.
    if (chosen && !pageItems.includes(chosen)) {
      state.page=Math.floor(found.indexOf(chosen)/PAGE_SIZE)+1;
      return render(scrollToDetail);
    }
    count.textContent=found.length+' mots';
    list.replaceChildren();
    pageItems.forEach(item => {
      const active=item.id===state.chosenId;
      const row=button('','gf-list-word'+(active?' is-selected':''),()=>{
        state.chosenId=item.id;renderDetail(item);updateURL();
        if (matchMedia('(max-width: 760px)').matches) detail.scrollIntoView({behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth',block:'start'});
      });
      row.dataset.id=item.id;
      row.setAttribute('role','option');
      row.setAttribute('aria-selected',String(active));
      row.append(el('span','gf-list-title',item.word),el('span','gf-list-meta',[item.grammar || item.type,item.theme || item.domain].filter(Boolean).join(' · ')));
      list.append(row);
    });
    pager.replaceChildren();
    if (total>1) {
      const prev=button('← Précédent','gf-page-button',()=>changePage(state.page-1)); prev.disabled=state.page===1;
      const next=button('Suivant →','gf-page-button',()=>changePage(state.page+1));next.disabled=state.page===total;
      pager.append(prev,el('span','gf-page-number',state.page+' / '+total),next);
    }
    renderDetail(chosen);updateURL();
    if(scrollToDetail) detail.scrollIntoView({behavior:'smooth',block:'start'});
  }
  function changePage(page) {
    const found=filteredWords();
    const total=Math.max(1,Math.ceil(found.length/PAGE_SIZE));
    state.page=Math.min(total,Math.max(1,page));
    state.chosenId='';
    render(false);
    list.scrollTop=0;
  }
  function onFilterChange() {state.page=1;state.chosenId='';render(false);}
  search.addEventListener('input', () => {state.q=search.value.slice(0,200);onFilterChange();});
  domain.addEventListener('change',() => {
    state.domain=domain.value;state.theme='';
    state.theme=selectOptions(theme, filteredThemes(),'Tous les thèmes','');
    onFilterChange();
  });
  theme.addEventListener('change',()=>{state.theme=theme.value;onFilterChange();});
  get('#gf-reset').addEventListener('click',()=>{
    state.q='';state.domain='';state.theme='';state.savedOnly=false;
    search.value='';saveToggle.setAttribute('aria-pressed','false');
    syncFilters();onFilterChange();
  });
  saveToggle.addEventListener('click',()=>{
    state.savedOnly=!state.savedOnly;
    saveToggle.setAttribute('aria-pressed',String(state.savedOnly));
    onFilterChange();
  });
  fetch(dataURL).then(response => {if(!response.ok) throw Error('Impossible de charger le lexique');return response.json();})
    .then(items => {
      if (!Array.isArray(items)) throw Error('Format de données invalide');
      state.words=items.map((item,index) => ({
        ...item,id:String(item.id || 'word-'+index),
        _search:normalize([item.word,item.definition,item.example,item.synonyms,item.collocations,item.vietnamese].join(' '))
      })).sort((a,b)=>collator.compare(a.word,b.word));
      syncFilters();
      render(false);
    }).catch(() => {
      count.textContent='Chargement impossible';
      detail.replaceChildren(el('p','gf-empty','Le lexique est momentanément indisponible. Actualisez la page pour réessayer.'));
    });
})();
