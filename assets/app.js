/* Immo Maurice — application (aucune dépendance) */
'use strict';

const $app = document.getElementById('app');
const S = { tab: 'deal', profile: '', data: null, scroll: {}, share: null };

const KINDS = { appartement: 'Appartement', maison: 'Maison', terrain: 'Terrain', terrain_res: 'Terrain résidentiel', terrain_agri: 'Terrain agricole', autre: 'Autre bien', inconnu: 'Bien' };
const STATUTS = { fav: 'Gardée', contacted: 'Contactée', visited: 'Visitée', rejected: 'Écartée' };
const TABS = { deal: 'Bons plans', check: 'À vérifier', follow: 'Suivi', plus: 'Plus' };

const ICONS = {
  deal: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10v9.5h13V10"/><path d="M10 19.500v-5h4v5"/></svg>',
  check: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.500 4.500"/><path d="M11 8v3.500"/><path d="M11 14h.01"/></svg>',
  follow: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6.500 4h11v16.500L12 16.500 6.500 20.500z"/></svg>',
  plus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>',
  back: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.200" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5 8 12l7 7"/></svg>',
  wa: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.600 15.100L2 22l5-1.300A10 10 0 1 0 12 2Zm0 1.800a8.200 8.200 0 1 1-4.200 15.300l-.300-.200-3 .800.800-2.900-.200-.300A8.200 8.200 0 0 1 12 3.800Zm-3.500 4c-.200 0-.500.100-.700.400-.300.300-1 1-1 2.300 0 1.400 1 2.700 1.100 2.900.200.200 2 3.100 4.900 4.300 2.400.900 2.900.800 3.400.700.500 0 1.700-.700 1.900-1.400.200-.600.200-1.200.200-1.300-.100-.100-.300-.200-.600-.400l-1.900-.900c-.300-.100-.500-.200-.700.100l-.900 1.100c-.200.200-.300.200-.600.100-.300-.200-1.200-.500-2.300-1.400-.800-.800-1.400-1.700-1.600-2-.200-.300 0-.400.100-.600l.400-.500c.200-.200.200-.300.300-.500.100-.200 0-.400 0-.500l-.900-2.100c-.200-.500-.400-.500-.600-.500h-.500Z"/></svg>',
  tel: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 4h4l2 5-2.500 1.500a11 11 0 0 0 5 5L15 13l5 2v4a1 1 0 0 1-1 1A16 16 0 0 1 4 5a1 1 0 0 1 1-1Z"/></svg>',
  ext: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 4h6v6"/><path d="M20 4 10 14"/><path d="M19 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/></svg>',
  house: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.800" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11.500 12 4l9 7.500"/><path d="M5.500 10v9.500h13V10"/></svg>',
  land: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.800" stroke-linecap="round" stroke-linejoin="round"><path d="M3 18.500h18"/><path d="M5 18.500 9 9l4 6 2.500-3.500 3.500 7"/><circle cx="17" cy="6" r="1.800"/></svg>',
};

// ------------------------------------------------------------------ outils
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const nb = (n) => Math.round(n).toLocaleString('fr-FR').replace(/ | /g, ' ');

function prix(p) {
  if (!p) return null;
  if (p >= 1e6) return (p / 1e6).toLocaleString('fr-FR', { maximumFractionDigits: 2 }) + ' M';
  return nb(p / 1e3) + ' k';
}
function surface(m2, land) {
  if (!m2) return null;
  if (!land) return nb(m2) + ' m²';
  const perches = m2 / 42.21;
  if (m2 >= 2100) return (m2 / 4221).toLocaleString('fr-FR', { maximumFractionDigits: 2 }) + ' arpent' + (m2 >= 6300 ? 's' : '') + ' (' + nb(m2) + ' m²)';
  return nb(m2) + ' m² (' + perches.toLocaleString('fr-FR', { maximumFractionDigits: 1 }) + ' perches)';
}
function ilya(ts) {
  if (!ts) return '';
  const s = Date.now() / 1000 - ts;
  if (s < 3600) return 'il y a ' + Math.max(1, Math.round(s / 60)) + ' min';
  if (s < 86400) return 'il y a ' + Math.round(s / 3600) + ' h';
  if (s < 86400 * 2) return 'hier';
  if (s < 86400 * 30) return 'il y a ' + Math.round(s / 86400) + ' j';
  return new Date(ts * 1000).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' });
}
function heure(ts) {
  if (!ts) return 'jamais';
  const d = new Date(ts * 1000);
  const today = new Date().toDateString() === d.toDateString();
  return (today ? '' : d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' }) + ' ') + d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
}

let toastTimer;
function toast(msg, action, fn) {
  const t = document.getElementById('toast');
  t.innerHTML = `<span>${esc(msg)}</span>${action ? `<button>${esc(action)}</button>` : ''}`;
  if (action) t.querySelector('button').onclick = () => { t.classList.remove('vu'); fn(); };
  t.classList.add('vu');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.remove('vu'), action ? 6000 : 3200);
}

async function api(action, { params = {}, body } = {}) {
  const qs = new URLSearchParams({ a: action, ...params }).toString();
  let res;
  try {
    res = await fetch('api/index.php?' + qs, body !== undefined
      ? { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-Immo': '1' }, body: JSON.stringify(body) }
      : { credentials: 'same-origin' });
  } catch (e) {
    throw new Error('Pas de connexion. Réessaie quand le réseau revient.');
  }
  let data = null;
  try { data = await res.json(); } catch (e) { /* réponse vide */ }
  if (res.status === 401 && action !== 'login') { renderLogin(); throw new Error('Connexion requise.'); }
  if (!res.ok) throw new Error((data && data.error) || 'Erreur du serveur (' + res.status + ').');
  return data;
}

// --------------------------------------------------------------- connexion
function renderLogin(err) {
  $app.innerHTML = `
    <main class="porte">
      <h1>Immo Maurice</h1>
      <p>Ta veille des bons plans dans le Nord. Entre ta clé d'accès pour ouvrir l'application sur ce téléphone.</p>
      <form id="f">
        <label for="k">Clé d'accès</label>
        <input id="k" type="password" autocomplete="current-password" autocapitalize="none" spellcheck="false" required>
        <p class="erreur" id="e">${esc(err || '')}</p>
        <button class="btn plein">Ouvrir</button>
      </form>
    </main>`;
  document.getElementById('f').onsubmit = async (ev) => {
    ev.preventDefault();
    const btn = ev.target.querySelector('button');
    btn.disabled = true;
    try {
      await api('login', { body: { key: document.getElementById('k').value } });
      route();
    } catch (e) {
      document.getElementById('e').textContent = e.message;
      btn.disabled = false;
    }
  };
}

// ----------------------------------------------------------------- cadre
function shell(tab, inner) {
  const c = (S.data && S.data.counts) || {};
  const badge = (k) => (k === 'deal' && c.deal_new ? `<span class="pastille">${c.deal_new}</span>` : '');
  $app.innerHTML = `
    <div class="ecran">${inner}</div>
    <div class="barre"><nav aria-label="Navigation">
      ${Object.keys(TABS).map((k) => `<a class="onglet" href="#/${k === 'deal' ? '' : k}" ${k === tab ? 'aria-current="page"' : ''} style="text-decoration:none">${ICONS[k]}${badge(k)}<span>${TABS[k]}</span></a>`).join('')}
    </nav></div>`;
}

function card(l) {
  const land = (l.kind || '').startsWith('terrain');
  const p = prix(l.price);
  const facts = [surface(land ? l.land_m2 : l.built_m2, land), !land && l.beds ? l.beds + ' ch.' : null, !land && l.land_m2 ? 'terrain ' + nb(l.land_m2) + ' m²' : null].filter(Boolean).join(', ');
  const cls = l.score >= 75 ? 'fort' : l.score >= 60 ? 'bon' : '';
  const atouts = l.reasons.slice(0, 2).join(' · ');
  return `
    <div class="rang" data-id="${l.id}">
      <div class="rang-fond"><span class="garder">Garder</span><span class="ecarter">Écarter</span></div>
      <button class="annonce ${l.seen ? '' : 'neuve'}" data-open="${l.id}">
        <span class="vignette">${l.photo ? `<img src="${esc(l.photo)}" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()">` : (land ? ICONS.land : ICONS.house)}</span>
        <span class="corps">
          <span class="prix-ligne">
            <span class="prix ${p ? '' : 'inconnu'}">${p ? `${p} <small>Rs</small>` : 'Prix à demander'}</span>
            ${l.bucket !== 'none' ? `<span class="note ${cls}" title="Note sur 100">${l.score}</span>` : ''}
          </span>
          <span class="quoi" style="display:block">${esc(KINDS[l.kind] || 'Bien')}${l.zone ? ', ' + esc(l.zone) : ''}</span>
          ${facts ? `<span class="faits" style="display:block">${esc(facts)}</span>` : ''}
          ${atouts ? `<span class="atout" style="display:block">${esc(atouts)}</span>` : ''}
          ${l.flags[0] ? `<span class="alerte">${esc(l.flags[0])}</span>` : ''}
          <span class="origine" style="display:block">${STATUTS[l.status] ? `<span class="etat">${STATUTS[l.status]}</span>` : ''}${esc(l.source || '')} · ${ilya(l.posted_at || l.first_seen)}</span>
        </span>
      </button>
    </div>`;
}

// ------------------------------------------------------------------ liste
async function renderFeed(tab) {
  S.tab = tab;
  if (!S.data || S.data._tab !== tab) shell(tab, `<div class="tete"><div class="tete-ligne"><h1>${TABS[tab]}</h1></div></div><div class="charge">Chargement…</div>`);
  let d;
  try {
    d = await api('list', { params: { tab, profile: S.profile } });
  } catch (e) {
    if (!document.querySelector('.porte')) $app.querySelector('.charge, .liste, .vide')?.insertAdjacentHTML('beforebegin', `<p class="vide">${esc(e.message)}</p>`);
    return;
  }
  d._tab = tab;
  S.data = d;
  const P = d.profiles;
  const total = Object.values(d.by_profile).reduce((a, b) => a + b, 0);
  const chips = tab === 'follow' ? '' : `
    <div class="filtres" role="group" aria-label="Type de bien">
      <button class="filtre" data-p="" aria-pressed="${S.profile === ''}">Tout<span>${total}</span></button>
      ${Object.keys(P).map((k) => `<button class="filtre" data-p="${k}" aria-pressed="${S.profile === k}">${esc(P[k].label)}<span>${d.by_profile[k] || 0}</span></button>`).join('')}
    </div>`;

  let body;
  if (d.items.length) {
    body = `<div class="liste">${d.items.map(card).join('')}</div>
      <div class="pied-liste">
        ${tab === 'deal' && d.items.some((x) => !x.seen) ? '<button class="lien" id="toutvu">Tout marquer comme vu</button>' : ''}
        ${tab !== 'follow' ? '<p class="muet petit" style="margin-top:8px">Glisse une annonce à droite pour la garder, à gauche pour l\'écarter.</p>' : ''}
      </div>`;
  } else if (d.total === 0) {
    body = `<div class="vide"><h2>La première collecte est en cours</h2><p>Les annonces arrivent dans quelques minutes. Tu peux fermer l'application : tu seras prévenu si tu actives les notifications.</p><a class="btn" href="#/reglages">Activer les notifications</a></div>`;
  } else if (tab === 'deal') {
    body = `<div class="vide"><h2>Rien de neuf pour l'instant</h2><p>Aucune annonce ne passe tes critères en ce moment. La veille continue toute seule.</p><a class="btn second" href="#/check">Voir les annonces à vérifier (${d.counts.check})</a></div>`;
  } else if (tab === 'check') {
    body = `<div class="vide"><h2>Rien à vérifier</h2><p>Ici arrivent les annonces prometteuses mais incomplètes : prix non indiqué ou localité floue.</p></div>`;
  } else {
    body = `<div class="vide"><h2>Aucune annonce suivie</h2><p>Garde une annonce pour la retrouver ici, puis note quand tu as contacté le vendeur ou visité.</p></div>`;
  }

  const sub = { deal: 'Dans ton budget et dans tes secteurs, les meilleures en premier.', check: 'Prometteuses, mais il manque le prix ou la localité.', follow: 'Celles que tu as gardées, contactées ou visitées.' }[tab];
  shell(tab, `
    <div class="tete">
      <div class="tete-ligne"><h1>${TABS[tab]}</h1><button class="maj" id="maj" title="Lancer une collecte">Collecte <b>${heure(d.last_run)}</b></button></div>
      <p class="muet petit" style="margin-top:4px">${sub}</p>
      ${chips}
    </div>
    ${body}`);

  $app.querySelectorAll('.filtre').forEach((b) => (b.onclick = () => { S.profile = b.dataset.p; renderFeed(tab); }));
  $app.querySelectorAll('[data-open]').forEach((b) => (b.onclick = () => { if (b.dataset.moved) return; S.scroll[tab] = scrollY; location.hash = '#/a/' + b.dataset.open; }));
  $app.querySelectorAll('.rang').forEach((r) => swipe(r, tab));
  const tv = document.getElementById('toutvu');
  if (tv) tv.onclick = async () => { await api('seen', { body: { ids: d.items.map((x) => x.id) } }); renderFeed(tab); };
  document.getElementById('maj').onclick = async () => { await api('run', { body: {} }); toast('Collecte demandée. Résultats dans quelques minutes.'); };
  if (S.scroll[tab]) { scrollTo(0, S.scroll[tab]); S.scroll[tab] = 0; }
}

async function setStatus(id, status, tab, label) {
  await api('mark', { body: { id, status } });
  toast(label, 'Annuler', async () => { await api('mark', { body: { id, status: 'new' } }); renderFeed(tab); });
}

function swipe(row, tab) {
  const el = row.querySelector('.annonce');
  const id = +row.dataset.id;
  let x0 = null, y0 = 0, dx = 0, locked = null;
  el.addEventListener('pointerdown', (e) => { x0 = e.clientX; y0 = e.clientY; dx = 0; locked = null; delete el.dataset.moved; });
  el.addEventListener('pointermove', (e) => {
    if (x0 === null) return;
    dx = e.clientX - x0;
    const dy = e.clientY - y0;
    if (locked === null && (Math.abs(dx) > 12 || Math.abs(dy) > 12)) locked = Math.abs(dx) > Math.abs(dy) * 1.4 ? 'x' : 'y';
    if (locked !== 'x') return;
    el.dataset.moved = '1';
    try { el.setPointerCapture(e.pointerId); } catch (err) { /* sans importance */ }
    el.style.transform = `translateX(${dx}px)`;
  });
  const end = async () => {
    if (x0 === null) return;
    x0 = null;
    if (locked !== 'x') return;
    const w = row.offsetWidth;
    if (Math.abs(dx) > Math.max(110, w * 0.32)) {
      const keep = dx > 0;
      el.style.transition = 'transform 0.18s';
      el.style.transform = `translateX(${keep ? w : -w}px)`;
      setTimeout(() => { row.style.display = 'none'; }, 180);
      try { await setStatus(id, keep ? 'fav' : 'rejected', tab, keep ? 'Annonce gardée' : 'Annonce écartée'); } catch (e) { toast(e.message); renderFeed(tab); }
    } else {
      el.style.transition = 'transform 0.18s';
      el.style.transform = '';
      setTimeout(() => { el.style.transition = ''; }, 200);
    }
    setTimeout(() => delete el.dataset.moved, 50);
  };
  el.addEventListener('pointerup', end);
  el.addEventListener('pointercancel', end);
}

// ------------------------------------------------------------------ fiche
async function renderDetail(id) {
  $app.innerHTML = `<div class="fiche"><div class="fiche-tete"><button class="rond" onclick="history.back()" aria-label="Retour">${ICONS.back}</button></div><div class="charge">Chargement…</div></div>`;
  scrollTo(0, 0);
  let l;
  try { l = await api('get', { params: { id } }); } catch (e) { $app.querySelector('.charge').textContent = e.message; return; }
  const land = (l.kind || '').startsWith('terrain');
  const p = prix(l.price);
  const cls = l.score >= 75 ? 'fort' : l.score >= 60 ? 'bon' : '';
  const base = land ? l.land_m2 : l.built_m2;
  const facts = [
    ['Type', KINDS[l.kind] || 'Bien'],
    ['Secteur', l.zone || 'non précisé'],
    land ? ['Terrain', surface(l.land_m2, true)] : ['Surface', surface(l.built_m2)],
    !land && l.land_m2 ? ['Terrain', surface(l.land_m2, true)] : null,
    !land && l.beds !== null ? ['Chambres', l.beds === 0 ? 'studio' : l.beds] : null,
    l.price && base ? ['Prix au m²', nb(l.price / base) + ' Rs'] : null,
    land && l.price && l.land_m2 ? ['Prix par perche', nb(l.price / (l.land_m2 / 42.21)) + ' Rs'] : null,
    ['Plage', l.beach || (l.ai && l.ai.plage) || 'non précisé'],
    ['Vendeur', { particulier: 'Particulier', agence: 'Agence', inconnu: 'Non précisé' }[l.seller] || 'Non précisé'],
    ['Publiée', ilya(l.posted_at || l.first_seen)],
  ].filter((f) => f && f[1]);
  const msg = encodeURIComponent(`Bonjour, je vous contacte au sujet de votre annonce « ${(l.title || '').slice(0, 70)} ». Est-elle toujours disponible ?`);
  const vig = [...l.flags, ...((l.ai && l.ai.vigilance) || [])];
  const stub = /à compléter$/.test(l.title || '');

  $app.innerHTML = `
    <div class="fiche">
      <div class="fiche-tete">
        <button class="rond" id="retour" aria-label="Retour">${ICONS.back}</button>
        <span class="muet petit" style="padding-right:14px">${esc(l.source || '')}</span>
      </div>
      ${l.photos.length ? `<div class="photos">${l.photos.map((u) => `<img src="${esc(u)}" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()">`).join('')}</div>` : ''}
      <div class="bloc">
        <div class="prix-ligne">
          <span class="prix ${p ? '' : 'inconnu'}">${p ? `${p} <small>Rs</small>` : 'Prix à demander'}</span>
          ${l.bucket !== 'none' ? `<span class="note ${cls}">${l.score}<small>/100</small></span>` : ''}
        </div>
        ${l.price_prev ? `<p class="atout">Prix baissé : ${prix(l.price_prev)} Rs auparavant</p>` : ''}
        ${l.price_note ? `<p class="muet petit">${esc(l.price_note)}</p>` : ''}
        <h2>${esc(l.title)}</h2>
        ${l.ai && l.ai.resume ? `<p class="resume">${esc(l.ai.resume)}</p>` : ''}
        ${l.reasons.length ? `<div class="pourquoi"><h3>Pourquoi c'est intéressant</h3><ul>${l.reasons.map((r) => `<li>${esc(r)}</li>`).join('')}</ul></div>` : ''}
        ${vig.length ? `<div class="vigilance"><h3>À vérifier avant d'appeler</h3><ul>${vig.map((r) => `<li>${esc(r)}</li>`).join('')}</ul></div>` : ''}
        <dl class="grille">${facts.map((f) => `<div><dt>${f[0]}</dt><dd>${esc(f[1])}</dd></div>`).join('')}</dl>
        <div class="suivi" role="group" aria-label="Suivi">
          ${['fav', 'contacted', 'visited'].map((s) => `<button data-s="${s}" aria-pressed="${l.status === s}">${STATUTS[s]}</button>`).join('')}
        </div>
        <label for="note" style="margin-top:16px">Mes notes</label>
        <textarea id="note" style="min-height:84px" placeholder="Nom du vendeur, date de visite, impression…">${esc(l.note || '')}</textarea>
        ${stub ? `<label for="comp" style="margin-top:16px">Texte de l'annonce</label><textarea id="comp" placeholder="Colle ici le texte de la publication pour qu'elle soit analysée."></textarea><button class="btn plein" id="compb" style="margin-top:8px">Analyser ce texte</button>` : `
        <p class="texte-annonce replie" id="txt">${esc(l.text || '')}</p>
        <button class="lien" id="plus">Lire toute l'annonce</button>`}
        ${l.others.length ? `<p class="muet petit" style="margin-top:14px">Aussi publiée ici : ${l.others.map((o) => (o.url ? `<a href="${esc(o.url)}" target="_blank" rel="noopener noreferrer">${esc(o.source)}</a>` : esc(o.source))).join(', ')}</p>` : ''}
      </div>
    </div>
    <div class="actions"><div class="actions-in">
      <div class="actions-ligne">
        ${l.phone ? `<a class="btn whatsapp" href="https://wa.me/${esc(l.phone)}?text=${msg}" target="_blank" rel="noopener noreferrer">${ICONS.wa}WhatsApp</a>
                     <a class="btn second" href="tel:+${esc(l.phone)}">${ICONS.tel}Appeler</a>` : ''}
        ${l.url ? `<a class="btn ${l.phone ? 'second' : ''}" href="${esc(l.url)}" target="_blank" rel="noopener noreferrer">${ICONS.ext}${l.phone ? 'Annonce' : (l.source_kind === 'site' ? 'Voir l\'annonce et contacter' : 'Ouvrir la publication')}</a>` : ''}
      </div>
      <div class="actions-ligne">
        <button class="btn second" id="garder">${l.status === 'fav' ? 'Gardée ✓' : 'Garder'}</button>
        <button class="btn danger" id="ecarter">${l.status === 'rejected' ? 'Écartée ✓' : 'Écarter'}</button>
      </div>
    </div></div>`;

  document.getElementById('retour').onclick = () => (history.length > 1 ? history.back() : (location.hash = '#/'));
  const mark = async (status, label) => { try { await api('mark', { body: { id: l.id, status } }); toast(label); renderDetail(l.id); } catch (e) { toast(e.message); } };
  document.getElementById('garder').onclick = () => mark(l.status === 'fav' ? 'new' : 'fav', l.status === 'fav' ? 'Retirée du suivi' : 'Annonce gardée');
  document.getElementById('ecarter').onclick = async () => { await api('mark', { body: { id: l.id, status: l.status === 'rejected' ? 'new' : 'rejected' } }); toast(l.status === 'rejected' ? 'Annonce rétablie' : 'Annonce écartée'); history.back(); };
  $app.querySelectorAll('.suivi button').forEach((b) => (b.onclick = () => mark(l.status === b.dataset.s ? 'new' : b.dataset.s, STATUTS[b.dataset.s])));
  const note = document.getElementById('note');
  note.onchange = async () => { await api('mark', { body: { id: l.id, note: note.value } }); toast('Note enregistrée'); };
  const plus = document.getElementById('plus');
  if (plus) {
    const txt = document.getElementById('txt');
    if (txt.scrollHeight <= txt.clientHeight + 4) { txt.classList.remove('replie'); plus.remove(); }
    else plus.onclick = () => { txt.classList.remove('replie'); plus.remove(); };
  }
  const compb = document.getElementById('compb');
  if (compb) compb.onclick = async () => {
    try { const r = await api('complete', { body: { id: l.id, text: document.getElementById('comp').value } }); toast('Annonce analysée'); location.replace('#/a/' + r.id); renderDetail(r.id); } catch (e) { toast(e.message); }
  };
}

// --------------------------------------------------------------- « Plus »
function renderPlus() {
  shell('plus', `
    <div class="tete"><div class="tete-ligne"><h1>Plus</h1></div></div>
    <div class="page">
      <div class="carte menu">
        <a href="#/ajouter">Ajouter une annonce <span>lien ou texte</span></a>
        <a href="#/sources">Sources suivies <span>sites, groupes, pages</span></a>
        <a href="#/reglages">Réglages <span>budgets, notifications, clés</span></a>
        <a href="#/ecartees">Annonces écartées <span>les rétablir</span></a>
        <a href="#/autres">Hors critères <span>dans le Nord, mais trop chères</span></a>
      </div>
      <p class="muet petit" style="margin-top:16px">Depuis Facebook : bouton Partager de la publication, puis « Immo Maurice ». L'annonce arrive ici avec son lien.</p>
    </div>`);
}

async function renderSimpleList(tab, title, empty) {
  shell('plus', `<div class="tete"><div class="tete-ligne"><h1>${title}</h1></div></div><div class="charge">Chargement…</div>`);
  const d = await api('list', { params: { tab } });
  $app.querySelector('.charge').outerHTML = d.items.length ? `<div class="liste" style="margin-top:12px">${d.items.map(card).join('')}</div>` : `<div class="vide"><p>${empty}</p></div>`;
  $app.querySelectorAll('[data-open]').forEach((b) => (b.onclick = () => (location.hash = '#/a/' + b.dataset.open)));
}

function renderAdd() {
  const sh = S.share || {};
  S.share = null;
  shell('plus', `
    <div class="tete"><div class="tete-ligne"><h1>Ajouter une annonce</h1></div></div>
    <div class="page">
      <form class="carte" id="f">
        <label for="u">Lien de l'annonce</label>
        <input id="u" type="url" inputmode="url" placeholder="https://…" value="${esc(sh.url || '')}">
        <label for="t">Texte de l'annonce</label>
        <textarea id="t" placeholder="Colle ici le texte de la publication Facebook ou du message reçu.">${esc(sh.text || '')}</textarea>
        <p class="aide">Pour une publication Facebook, colle aussi son texte : Facebook ne laisse pas lire le contenu à partir du seul lien.</p>
        <button class="btn plein" style="margin-top:14px">Analyser et garder</button>
      </form>
    </div>`);
  document.getElementById('f').onsubmit = async (ev) => {
    ev.preventDefault();
    const btn = ev.target.querySelector('button');
    btn.disabled = true;
    try {
      const r = await api('add', { body: { url: document.getElementById('u').value, text: document.getElementById('t').value } });
      toast(r.new ? 'Annonce ajoutée au suivi' : 'Annonce déjà connue, ajoutée au suivi');
      location.hash = '#/a/' + r.id;
    } catch (e) { toast(e.message); btn.disabled = false; }
  };
}

async function renderSources() {
  shell('plus', `<div class="tete"><div class="tete-ligne"><h1>Sources</h1></div></div><div class="charge">Chargement…</div>`);
  const d = await api('sources');
  const group = (kind) => d.items.filter((s) => s.kind === kind);
  const line = (s) => {
    let sub;
    if (s.kind === 'site') sub = s.last_error && !s.last_ok_at ? `<span class="souci">${esc(s.last_error)}</span>` : `<span>${s.last_ok_at ? 'Lu ' + ilya(s.last_ok_at) + ', ' + s.last_count + ' annonces en vitrine, ' + s.matched + ' retenues' : 'Pas encore lu'}${s.fail_streak >= 3 ? ` <span class="souci">ne répond plus</span>` : ''}</span>`;
    else sub = `<span>${s.total ? s.total + ' publications lues' : (d.apify.ready ? 'En attente du prochain passage' : 'Inactif tant que Facebook n\'est pas branché')}</span>`;
    return `<div class="ligne"><div class="nom"><b>${esc(s.name)}</b>${sub}</div><button class="inter" role="switch" aria-checked="${s.enabled ? 'true' : 'false'}" data-id="${s.id}" aria-label="Suivre ${esc(s.name)}"></button></div>`;
  };
  const fb = d.apify;
  $app.querySelector('.charge').outerHTML = `
    <div class="page">
      <form class="carte" id="add">
        <label for="su">Ajouter une source</label>
        <input id="su" type="url" inputmode="url" placeholder="Adresse d'un site, d'un groupe ou d'une page Facebook" required>
        <p class="aide">Pour un site, donne la page qui liste les biens à vendre. Le format est reconnu automatiquement.</p>
        <button class="btn plein second" style="margin-top:12px">Ajouter</button>
      </form>
      <h2>Sites d'annonces</h2>
      <div class="carte">${group('site').map(line).join('') || '<p class="muet">Aucun site.</p>'}</div>
      <h2>Facebook</h2>
      <div class="carte">
        <p><span class="voyant ${fb.ready ? (fb.error ? 'souci' : 'ok') : ''}"></span>${fb.ready ? `Branché. ${fb.used} publications lues ce mois sur ${fb.cap} autorisées.` : 'Pas encore branché.'}</p>
        ${fb.error ? `<p class="aide"><span class="souci" style="color:var(--canne)">${esc(fb.error)}</span></p>` : ''}
        ${fb.ready ? `<div class="jauge"><i style="width:${Math.min(100, (fb.used / Math.max(1, fb.cap)) * 100)}%"></i></div>` : '<p class="aide">La lecture automatique des groupes et pages publics demande un jeton Apify gratuit. <a href="#/reglages">Le brancher dans les réglages</a>.</p>'}
      </div>
      <h2>Groupes publics (${group('fb_group').length})</h2>
      <div class="carte">${group('fb_group').map(line).join('')}</div>
      <h2>Pages d'agents (${group('fb_page').length})</h2>
      <div class="carte">${group('fb_page').map(line).join('')}</div>
    </div>`;
  $app.querySelectorAll('.inter').forEach((b) => (b.onclick = async () => {
    const on = b.getAttribute('aria-checked') !== 'true';
    b.setAttribute('aria-checked', on);
    try { await api('source_save', { body: { id: +b.dataset.id, enabled: on } }); } catch (e) { toast(e.message); b.setAttribute('aria-checked', !on); }
  }));
  document.getElementById('add').onsubmit = async (ev) => {
    ev.preventDefault();
    const btn = ev.target.querySelector('button');
    btn.disabled = true;
    try {
      const r = await api('source_save', { body: { url: document.getElementById('su').value } });
      toast(r.kind === 'site' ? `Site ajouté, ${r.found} annonces repérées` : 'Source Facebook ajoutée');
      renderSources();
    } catch (e) { toast(e.message); btn.disabled = false; }
  };
}

// --------------------------------------------------------------- réglages
async function pushState() {
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) return 'absent';
  if (Notification.permission === 'denied') return 'bloque';
  const reg = await navigator.serviceWorker.ready;
  return (await reg.pushManager.getSubscription()) ? 'actif' : 'inactif';
}
function b64ToBytes(s) {
  const pad = '='.repeat((4 - (s.length % 4)) % 4);
  const raw = atob((s + pad).replace(/-/g, '+').replace(/_/g, '/'));
  return Uint8Array.from(raw, (c) => c.charCodeAt(0));
}
async function enablePush() {
  const perm = await Notification.requestPermission();
  if (perm !== 'granted') throw new Error('Notifications refusées. Autorise-les dans les réglages du navigateur pour ce site.');
  const reg = await navigator.serviceWorker.ready;
  const { key } = await api('push_key');
  const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToBytes(key) });
  await api('push_sub', { body: { endpoint: sub.endpoint } });
}

async function renderSettings() {
  shell('plus', `<div class="tete"><div class="tete-ligne"><h1>Réglages</h1></div></div><div class="charge">Chargement…</div>`);
  const [d, ps, st] = await Promise.all([api('settings'), pushState(), api('status')]);
  const P = d.profiles;
  const pushText = { actif: 'Activées sur ce téléphone.', inactif: 'Pas encore activées sur ce téléphone.', bloque: 'Bloquées par le navigateur pour ce site.', absent: 'Ce navigateur ne les gère pas. Ouvre l\'application dans Chrome.' }[ps];
  $app.querySelector('.charge').outerHTML = `
    <div class="page">
      <h2>Notifications</h2>
      <div class="carte">
        <p><span class="voyant ${ps === 'actif' ? 'ok' : ps === 'bloque' ? 'souci' : ''}"></span>${pushText}</p>
        <p class="aide">Tu es prévenu dès qu'un bon plan apparaît. Rien entre ${esc(d.quiet_hours.split('-')[0])} h et ${esc(d.quiet_hours.split('-')[1])} h, heure de Paris.</p>
        ${ps === 'inactif' ? '<button class="btn plein" id="pon" style="margin-top:12px">Activer les notifications</button>' : ''}
        ${ps === 'actif' ? '<button class="btn plein second" id="ptest" style="margin-top:12px">Envoyer une notification d\'essai</button>' : ''}
        <label for="nmin">Me prévenir à partir de la note</label>
        <input id="nmin" type="number" inputmode="numeric" min="30" max="95" value="${d.notify_min}">
        <p class="aide">62 par défaut. Monte à 75 pour n'être dérangé que par les meilleures.</p>
      </div>

      <h2>Budgets maximum</h2>
      <form class="carte" id="budgets">
        ${Object.keys(P).map((k) => `<label for="b_${k}">${esc(P[k].label)} (${P[k].group === 'cote' ? 'secteurs plage' : 'tout le Nord'})</label><input id="b_${k}" type="number" inputmode="numeric" step="100000" min="100000" value="${P[k].max}">`).join('')}
        <p class="aide">En roupies. Une annonce jusqu'à 15 % au-dessus est gardée, signalée « à négocier ».</p>
        <button class="btn plein second" style="margin-top:12px">Enregistrer les budgets</button>
      </form>

      <h2>Facebook automatique</h2>
      <form class="carte" id="apify">
        <p><span class="voyant ${d.apify.set ? (d.apify.error ? 'souci' : 'ok') : ''}"></span>${d.apify.set ? `Branché, ${d.apify.used} publications lues ce mois sur ${d.apify.cap}.` : 'Pas encore branché.'}</p>
        ${d.apify.error ? `<p class="aide" style="color:var(--canne)">${esc(d.apify.error)}</p>` : ''}
        <p class="aide">Les groupes et pages publics sont lus par Apify, sans jamais utiliser ton compte Facebook. Le forfait gratuit suffit pour démarrer.</p>
        <ol class="etapes petit">
          <li>Crée un compte gratuit sur <a href="https://console.apify.com/sign-up" target="_blank" rel="noopener">console.apify.com</a>.</li>
          <li>Ouvre <a href="https://console.apify.com/settings/integrations" target="_blank" rel="noopener">console.apify.com/settings/integrations</a> et copie le jeton de la section « Personal API tokens ».</li>
          <li>Colle-le ci-dessous.</li>
        </ol>
        <label for="atok">Jeton Apify</label>
        <input id="atok" type="password" autocomplete="off" placeholder="${d.apify.set ? 'Déjà enregistré. Colle un nouveau jeton pour le remplacer.' : 'apify_api_…'}">
        <label for="acap">Publications lues par mois, au maximum</label>
        <input id="acap" type="number" inputmode="numeric" min="0" step="100" value="${d.apify.cap}">
        <p class="aide">C'est ton garde-fou de dépense. 1 200 reste dans le crédit gratuit de 5 $ par mois ; une fois atteint, Facebook n'est plus lu jusqu'au mois suivant, les sites continuent.</p>
        <label for="akw">Recherches lancées dans les groupes</label>
        <textarea id="akw" style="min-height:130px">${esc(d.fb_keywords)}</textarea>
        <p class="aide">Une par ligne. Facebook est lu deux fois par jour : recherches le matin, pages d'agents ou groupes suivis le soir (heure de Maurice 8 h et 19 h).</p>
        <button class="btn plein second" style="margin-top:12px">Enregistrer</button>
      </form>

      <h2>Analyse par IA</h2>
      <form class="carte" id="gemini">
        <p><span class="voyant ${d.gemini.set ? (d.gemini.error ? 'souci' : 'ok') : ''}"></span>${d.gemini.set ? 'Active' + (d.gemini.model ? ' (' + esc(d.gemini.model) + ').' : '.') : 'Inactive. Le tri fonctionne déjà sans elle.'}</p>
        ${d.gemini.error ? `<p class="aide" style="color:var(--canne)">${esc(d.gemini.error)}</p>` : ''}
        <p class="aide">Avec une clé Gemini gratuite, chaque annonce retenue reçoit un résumé, et les publications Facebook mal rédigées sont mieux comprises (prix, lieu, créole).</p>
        <ol class="etapes petit">
          <li>Ouvre <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">aistudio.google.com/apikey</a> avec ton compte Google.</li>
          <li>Crée une clé. N'active pas la facturation : le niveau gratuit suffit.</li>
          <li>Colle-la ci-dessous.</li>
        </ol>
        <label for="gkey">Clé Gemini</label>
        <input id="gkey" type="password" autocomplete="off" placeholder="${d.gemini.set ? 'Déjà enregistrée. Colle une nouvelle clé pour la remplacer.' : 'AIza…'}">
        <button class="btn plein second" style="margin-top:12px">Enregistrer</button>
      </form>

      <h2>Dernières collectes</h2>
      <div class="carte">
        ${st.runs.length ? st.runs.map((r) => `<div class="ligne"><div class="nom"><b>${heure(r.at)}</b><span>${r.error ? `<span class="souci">${esc(r.error)}</span>` : `${r.new} nouvelles annonces, dont ${r.deals} bons plans`}</span></div></div>`).join('') : '<p class="muet">Aucune collecte pour l\'instant.</p>'}
        ${st.broken.length ? `<p class="aide" style="color:var(--canne);margin-top:10px">Sites qui ne répondent plus : ${st.broken.map((b) => esc(b.name)).join(', ')}.</p>` : ''}
      </div>
      <p class="pied-liste"><button class="lien" id="out">Fermer la session sur ce téléphone</button><br><span class="muet petit">Version ${esc(d.version)}</span></p>
    </div>`;

  const on = (id, fn) => { const el = document.getElementById(id); if (el) el.onclick = fn; };
  on('pon', async () => { try { await enablePush(); toast('Notifications activées'); renderSettings(); } catch (e) { toast(e.message); } });
  on('ptest', async () => { try { const r = await api('push_test', { body: {} }); toast(r.sent_to ? 'Envoyée. Elle arrive dans quelques secondes.' : 'Échec de l\'envoi. ' + (r.error || '')); } catch (e) { toast(e.message); } });
  on('out', async () => { await api('logout', { body: {} }); renderLogin(); });
  document.getElementById('nmin').onchange = async (e) => { await api('settings', { body: { notify_min: +e.target.value } }); toast('Seuil enregistré'); };
  const save = (form, build, msg) => (document.getElementById(form).onsubmit = async (ev) => {
    ev.preventDefault();
    const btn = ev.target.querySelector('button');
    btn.disabled = true;
    try { await api('settings', { body: build() }); toast(msg); renderSettings(); } catch (e) { toast(e.message); btn.disabled = false; }
  });
  save('budgets', () => ({ profiles: Object.fromEntries(Object.keys(P).map((k) => [k, +document.getElementById('b_' + k).value])) }), 'Budgets enregistrés, annonces reclassées');
  save('apify', () => {
    const b = { apify_month_cap: +document.getElementById('acap').value, fb_keywords: document.getElementById('akw').value };
    const t = document.getElementById('atok').value.trim();
    if (t) b.apify_token = t;
    return b;
  }, 'Réglages Facebook enregistrés');
  save('gemini', () => ({ gemini_key: document.getElementById('gkey').value.trim() }), 'Clé enregistrée');
}

// ---------------------------------------------------------------- routage
async function route() {
  const h = location.hash.replace(/^#\/?/, '');
  // Arrivée par le bouton Partager d'une autre application
  const q = new URLSearchParams(location.search);
  if (q.has('share-url') || q.has('share-text') || q.has('share-title')) {
    let url = q.get('share-url') || '';
    let text = [q.get('share-title'), q.get('share-text')].filter(Boolean).join('\n');
    const m = !url && text.match(/https?:\/\/\S+/);
    if (m) { url = m[0]; text = text.replace(m[0], '').trim(); }
    S.share = { url, text };
    history.replaceState(null, '', location.pathname + '#/ajouter');
    return route();
  }
  try {
    if (h.startsWith('a/')) return await renderDetail(+h.slice(2));
    if (h === 'check' || h === 'follow') return await renderFeed(h);
    if (h === 'plus') return renderPlus();
    if (h === 'ajouter') return renderAdd();
    if (h === 'sources') return await renderSources();
    if (h === 'reglages') return await renderSettings();
    if (h === 'ecartees') return await renderSimpleList('rejected', 'Annonces écartées', 'Aucune annonce écartée.');
    if (h === 'autres') return await renderSimpleList('other', 'Hors critères', 'Rien ici pour l\'instant.');
    return await renderFeed('deal');
  } catch (e) {
    if (!document.querySelector('.porte')) toast(e.message);
  }
}

addEventListener('hashchange', route);
document.addEventListener('visibilitychange', () => { if (!document.hidden && /^#?\/?(check|follow)?$/.test(location.hash)) route(); });
if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});
route();
