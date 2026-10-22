(function(){
// public-content.js — loads News, Announcements and Events from api/content/list_published.php and draws
// them into the website. All staff-written text goes in with textContent (never innerHTML), and
// photo paths are checked against a strict pattern, so nothing staff type can run as code.

const MONTHS_LONG  = ['JANUARY','FEBRUARY','MARCH','APRIL','MAY','JUNE','JULY','AUGUST','SEPTEMBER','OCTOBER','NOVEMBER','DECEMBER'];
const MONTHS_SHORT = ['JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'];
const IMG_RE = /^uploads\/content\/[a-f0-9]{32}\.(jpg|png|webp)$/;

// 'YYYY-MM-DD' or 'YYYY-MM-DD HH:MM:SS' -> numbers (avoids timezone shifts from new Date(string))
function parseDate(str){
  const p = String(str).slice(0,10).split('-');
  return { y:+p[0], m:+p[1], d:+p[2] };
}
function pad2(n){ return String(n).padStart(2,'0'); }
function longDate(str){ const d = parseDate(str); return pad2(d.d) + ' ' + MONTHS_LONG[d.m-1] + ' ' + d.y; }
function shortDate(str){ const d = parseDate(str); return pad2(d.d) + ' ' + MONTHS_SHORT[d.m-1] + ' ' + d.y; }

// Create an element with optional class and text
function el(tag, className, text){
  const e = document.createElement(tag);
  if(className) e.className = className;
  if(text !== undefined && text !== null) e.textContent = text;
  return e;
}

// Image placeholder box (static markup only, no user data in it)
function placeholder(label){
  const ph = el('div','ph');
  if(label) ph.setAttribute('data-label', label);
  ph.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>';
  return ph;
}

// Cover photo if there is one, otherwise the placeholder
function coverOrPlaceholder(path, label){
  if(path && IMG_RE.test(path)){
    const box = el('div','cms-cover');
    const img = document.createElement('img');
    img.src = path; img.alt = ''; img.loading = 'lazy';
    box.appendChild(img);
    return box;
  }
  return placeholder(label);
}

// Staff-written content blocks -> readable page content (also used by the staff preview)
function renderBlocks(blocks, container){
  (blocks || []).forEach(b => {
    if(!b || typeof b !== 'object') return;
    if(b.type === 'heading' && b.text){
      container.appendChild(el('h3','cms-b-heading', b.text));
    } else if(b.type === 'text' && b.text){
      String(b.text).split(/\n{2,}/).forEach(chunk => {
        if(chunk.trim()) container.appendChild(el('p','cms-b-text', chunk.trim()));
      });
    } else if(b.type === 'photo' && IMG_RE.test(b.src || '')){
      const size = ['small','medium','full'].includes(b.size) ? b.size : 'medium';
      const fig = el('figure','cms-b-photo cms-size-' + size);
      const img = document.createElement('img');
      img.src = b.src; img.alt = b.caption || ''; img.loading = 'lazy';
      fig.appendChild(img);
      if(b.caption) fig.appendChild(el('figcaption','', b.caption));
      container.appendChild(fig);
    }
  });
}

async function fetchJson(url){
  const res = await fetch(url, { credentials: 'same-origin' });
  if(!res.ok) throw new Error('HTTP ' + res.status);
  return res.json();
}
async function fetchContent(type){
  const data = await fetchJson('api/content/list_published.php?type=' + encodeURIComponent(type));
  return data.items;
}
function showMessage(container, text){
  container.replaceChildren(el('p', '', text));
}

// ---------- "read more" window ----------
function closeReader(){
  const ov = document.querySelector('.cms-reader-overlay');
  if(ov) ov.remove();
  document.body.style.overflow = '';
}
document.addEventListener('keydown', e => { if(e.key === 'Escape') closeReader(); });

function showReader(p){
  closeReader();
  const ov  = el('div','cms-reader-overlay');
  ov.setAttribute('role','dialog'); ov.setAttribute('aria-modal','true');
  const art = el('article','cms-reader');

  const close = el('button','cms-reader-close','✕ Close');
  close.type = 'button'; close.onclick = closeReader;
  art.appendChild(close);

  if(p.category === 'event' && p.event_date){
    art.appendChild(el('div','news-date', longDate(p.event_date)));
  } else if(p.published_at){
    art.appendChild(el('div','news-date', longDate(p.published_at)));
  }
  art.appendChild(el('h2','cms-reader-title', p.title));

  if(p.category === 'event'){
    const bits = [p.event_time, p.venue].filter(Boolean);
    if(bits.length) art.appendChild(el('div','event-meta', bits.join('  ·  ')));
  }
  if(p.cover_path && IMG_RE.test(p.cover_path)){
    const img = document.createElement('img');
    img.className = 'cms-reader-cover'; img.src = p.cover_path; img.alt = '';
    art.appendChild(img);
  }
  if(p.body) art.appendChild(el('p','cms-reader-summary', p.body));
  renderBlocks(p.blocks, art);

  ov.appendChild(art);
  ov.addEventListener('click', e => { if(e.target === ov) closeReader(); });
  document.body.appendChild(ov);
  document.body.style.overflow = 'hidden';
}
async function openReader(id){
  try{
    const data = await fetchJson('api/content/view.php?id=' + encodeURIComponent(id));
    showReader(data.post);
  }catch(err){ /* nothing to show; the card already has the summary */ }
}

// ---------- NEWS ----------
async function loadNews(){
  const box = document.getElementById('news-layout');
  if(!box) return;
  try{
    const items = await fetchContent('news');
    if(items.length === 0){ showMessage(box, 'No news yet.'); return; }

    const feature = items.find(n => n.is_featured) || items[0];
    const others  = items.filter(n => n !== feature).slice(0,4);

    const card = el('a','news-feature');
    card.href = '#';
    card.addEventListener('click', e => { e.preventDefault(); if(feature.has_details) openReader(feature.id); });
    card.appendChild(coverOrPlaceholder(feature.cover_path, 'REPLACE → Featured news photo (1200×675)'));
    const body = el('div','news-feature-body');
    body.appendChild(el('div','news-date', longDate(feature.published_at)));
    body.appendChild(el('h3','',feature.title));
    body.appendChild(el('p','',feature.body));
    if(feature.has_details) body.appendChild(el('span','program-link','Read the full story →'));
    card.appendChild(body);

    const side = el('div','news-side');
    others.forEach(n => {
      const item = el('a','news-item');
      item.href = '#';
      item.addEventListener('click', e => { e.preventDefault(); if(n.has_details) openReader(n.id); });
      item.appendChild(coverOrPlaceholder(n.cover_path, ''));
      const txt = el('div');
      txt.appendChild(el('div','news-date', shortDate(n.published_at)));
      txt.appendChild(el('h4','',n.title));
      txt.appendChild(el('p','',n.body));
      item.appendChild(txt);
      side.appendChild(item);
    });

    box.replaceChildren(card, side);
  }catch(err){
    showMessage(box, 'News is unavailable right now.');
  }
}

// ---------- ANNOUNCEMENTS ----------
async function loadAnnouncements(){
  const box = document.getElementById('announce-list');
  if(!box) return;
  try{
    const items = await fetchContent('announcement');
    if(items.length === 0){ showMessage(box, 'No announcements.'); return; }

    const known = ['urgent','info','event'];     // CSS classes that exist
    box.replaceChildren(...items.map(a => {
      const item = el('div','announce-item');
      item.appendChild(el('span','tag ' + (known.includes(a.tag) ? a.tag : 'info'), a.tag_label || 'NOTICE'));
      item.appendChild(el('h5','',a.title));
      item.appendChild(el('p','cms-pre',a.body));
      return item;
    }));
  }catch(err){
    showMessage(box, 'Announcements are unavailable right now.');
  }
}

// ---------- EVENTS ----------
async function loadEvents(){
  const box = document.getElementById('events-list');
  if(!box) return;
  try{
    const items = await fetchContent('event');
    if(items.length === 0){ showMessage(box, 'No upcoming events.'); return; }

    box.replaceChildren(...items.map(ev => {
      const d = parseDate(ev.event_date);
      const row = el('div','event-row');

      const date = el('div','event-date');
      date.appendChild(el('div','m',MONTHS_SHORT[d.m-1]));
      date.appendChild(el('div','d',pad2(d.d)));

      const body = el('div','event-body');
      body.appendChild(el('h4','',ev.title));
      const meta = el('div','event-meta');
      if(ev.event_time) meta.appendChild(el('span','',ev.event_time));
      if(ev.venue)      meta.appendChild(el('span','',ev.venue));
      body.appendChild(meta);
      body.appendChild(el('p','',ev.body));
      if(ev.has_details){
        const more = el('button','cms-more','More details →');
        more.type = 'button';
        more.addEventListener('click', () => openReader(ev.id));
        body.appendChild(more);
      }

      row.append(date, body);
      return row;
    }));
  }catch(err){
    showMessage(box, 'Events are unavailable right now.');
  }
}

// Shared with the staff editor so its live preview looks exactly like the real site
window.CFSContent = { renderBlocks: renderBlocks, longDate: longDate, el: el, IMG_RE: IMG_RE };

loadNews();
loadAnnouncements();
loadEvents();
})();
