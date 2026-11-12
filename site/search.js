/*
 * Local search for the PABLO website: no service, no library.
 *
 * site/build writes search-index.json with one entry per documentation
 * section: { p: page, s: sidebar section, h: heading, u: url, t: text }.
 * The index is fetched the first time the search opens, then every query
 * is a linear scan (a few hundred entries): every word must appear in the
 * entry; headings weigh more than page titles, which weigh more than text.
 *
 * Open with "/", Ctrl/Cmd+K, or any [data-search-open] button.
 */
(() => {
  'use strict';

  const script = document.currentScript;
  const indexUrl = script && script.dataset.index ? script.dataset.index : 'search-index.json';
  const SUGGESTED = ['Installation', 'Quickstart', 'Managing tasks', 'Configuration', 'Commands', 'Troubleshooting'];
  const MAX_RESULTS = 12;

  let entries = null;
  let loading = null;
  let failed = false;
  let results = [];
  let selected = 0;
  let lastFocus = null;

  // ------------------------------------------------------------------ dom

  const root = document.createElement('div');
  root.className = 'search';
  root.hidden = true;
  root.innerHTML = `
    <div class="search-backdrop" data-search-close></div>
    <div class="search-panel" role="dialog" aria-modal="true" aria-label="Search the documentation">
      <div class="search-field">
        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5" fill="none" stroke="currentColor" stroke-width="2.2"/><path d="M15.5 15.5L20 20" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
        <input type="search" placeholder="Search the docs…" aria-label="Search the docs" role="combobox" aria-expanded="false" aria-controls="search-results" aria-autocomplete="list" autocomplete="off" spellcheck="false">
        <kbd>Esc</kbd>
      </div>
      <ul class="search-results" id="search-results" role="listbox" aria-label="Results"></ul>
      <p class="visually-hidden" role="status" aria-live="polite"></p>
      <div class="search-foot"><span><kbd>↑</kbd> <kbd>↓</kbd> to move</span><span><kbd>↵</kbd> to open</span><span><kbd>Esc</kbd> to close</span></div>
    </div>`;
  document.body.appendChild(root);

  const input = root.querySelector('input');
  const list = root.querySelector('.search-results');
  const status = root.querySelector('[role="status"]');

  // ---------------------------------------------------------------- index

  // Lowercase and strip accents; precomposed characters keep their length,
  // so positions found in the folded text map back onto the original.
  const fold = (s) => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');

  function load() {
    if (entries) return Promise.resolve();
    if (!loading) {
      failed = false;
      loading = fetch(indexUrl)
        .then((r) => {
          if (!r.ok) throw new Error(`HTTP ${r.status}`);
          return r.json();
        })
        .then((data) => {
          entries = data.map((e) => ({ ...e, P: fold(e.p), H: fold(e.h), T: fold(e.t) }));
        })
        .catch(() => { loading = null; failed = true; });
    }
    return loading;
  }

  // ---------------------------------------------------------------- query

  const tokenize = (q) => fold(q).split(/\s+/).map((t) => t.replace(/^[^\w/.:-]+|[^\w/.:-]+$/g, '')).filter((t) => t.length > 0);

  function count(haystack, needle) {
    let n = 0;
    for (let i = haystack.indexOf(needle); i !== -1 && n < 6; i = haystack.indexOf(needle, i + needle.length)) n++;
    return n;
  }

  const startsWord = (haystack, needle) => {
    const i = haystack.indexOf(needle);
    return i === 0 || (i > 0 && /[^a-z0-9]/.test(haystack[i - 1]));
  };

  function score(e, tokens, phrase) {
    let total = 0;
    for (const t of tokens) {
      let s = 0;
      if (e.H.includes(t)) s += startsWord(e.H, t) ? 14 : 8;
      if (e.P.includes(t)) s += startsWord(e.P, t) ? 9 : 5;
      const inText = count(e.T, t);
      if (inText) s += 1 + inText + (startsWord(e.T, t) ? 1 : 0);
      if (s === 0) return 0; // every word must match somewhere
      total += s;
    }
    if (tokens.length > 1) {
      if (e.H.includes(phrase)) total += 20;
      else if (e.T.includes(phrase)) total += 8;
    }
    if (!e.h) total += 1; // page intros first among equals
    return total;
  }

  function search(q) {
    const tokens = tokenize(q);
    if (!tokens.length || !entries) return [];
    const phrase = tokens.join(' ');
    return entries
      .map((e) => ({ e, s: score(e, tokens, phrase) }))
      .filter((r) => r.s > 0)
      .sort((a, b) => b.s - a.s)
      .slice(0, MAX_RESULTS)
      .map((r) => ({ ...r.e, tokens }));
  }

  // --------------------------------------------------------------- render

  const esc = (s) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

  function mark(text, tokens) {
    const folded = fold(text);
    const ranges = [];
    for (const t of tokens) {
      for (let i = folded.indexOf(t); i !== -1; i = folded.indexOf(t, i + t.length)) ranges.push([i, i + t.length]);
    }
    ranges.sort((a, b) => a[0] - b[0]);
    let out = '';
    let at = 0;
    for (const [from, to] of ranges) {
      if (from < at) continue;
      out += esc(text.slice(at, from)) + '<mark>' + esc(text.slice(from, to)) + '</mark>';
      at = to;
    }
    return out + esc(text.slice(at));
  }

  function snippet(text, tokens) {
    const folded = fold(text);
    let first = -1;
    for (const t of tokens) {
      const i = folded.indexOf(t);
      if (i !== -1 && (first === -1 || i < first)) first = i;
    }
    if (first === -1) return text.slice(0, 150) + (text.length > 150 ? '…' : '');
    const start = Math.max(0, text.lastIndexOf(' ', Math.max(0, first - 60)) + 1);
    const end = Math.min(text.length, first + 120);
    return (start > 0 ? '…' : '') + text.slice(start, end) + (end < text.length ? '…' : '');
  }

  function item(e, i, tokens) {
    const title = e.h || e.p;
    const page = e.h ? (e.s ? `${e.s} › ${e.p}` : e.p) : (e.s || 'Documentation');
    return `<li role="option" id="search-opt-${i}" aria-selected="false"><a href="${esc(e.u)}" data-i="${i}" tabindex="-1">`
      + `<span class="search-hit-page">${esc(page)}</span>`
      + `<span class="search-hit-title">${tokens ? mark(title, tokens) : esc(title)}</span>`
      + `<span class="search-hit-text">${tokens ? mark(snippet(e.t, tokens), tokens) : esc(snippet(e.t, []))}</span>`
      + '</a></li>';
  }

  function render() {
    const q = input.value.trim();
    results = [];
    if (!entries) {
      const message = failed
        ? 'Search is unavailable: the index could not be loaded. Check your connection, then type again to retry.'
        : 'Loading…';
      list.innerHTML = `<li class="search-empty${failed ? ' search-error' : ''}" role="presentation">${message}</li>`;
      announce(message);
      return;
    }
    if (!q) {
      results = SUGGESTED.map((p) => entries.find((e) => e.p === p && !e.h)).filter(Boolean);
      list.innerHTML = '<li class="search-label" role="presentation">Popular pages</li>' + results.map((e, i) => item(e, i, null)).join('');
      announce('');
    } else {
      results = search(q);
      list.innerHTML = results.length
        ? results.map((e, i) => item(e, i, e.tokens)).join('')
        : `<li class="search-empty" role="presentation">No results for “${esc(q)}”. Try fewer or shorter words, or a command such as <b>task:start</b>.</li>`;
      announce(results.length ? `${results.length} result${results.length > 1 ? 's' : ''}` : `No results for ${q}`);
    }
    select(0);
  }

  function announce(message) {
    if (status.textContent !== message) status.textContent = message;
    input.setAttribute('aria-expanded', String(results.length > 0));
    if (!results.length) input.removeAttribute('aria-activedescendant');
  }

  function select(i) {
    const links = list.querySelectorAll('a');
    if (!links.length) return;
    selected = (i + links.length) % links.length;
    links.forEach((a, j) => {
      a.classList.toggle('is-selected', j === selected);
      a.parentElement.setAttribute('aria-selected', String(j === selected));
    });
    input.setAttribute('aria-activedescendant', `search-opt-${selected}`);
    links[selected].scrollIntoView({ block: 'nearest' });
  }

  // ---------------------------------------------------------- open/close

  function open() {
    if (!root.hidden) return;
    lastFocus = document.activeElement;
    root.hidden = false;
    document.body.classList.add('search-open');
    input.select();
    input.focus();
    render();
    load().then(render);
  }

  function close() {
    if (root.hidden) return;
    root.hidden = true;
    document.body.classList.remove('search-open');
    input.setAttribute('aria-expanded', 'false');
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }

  // --------------------------------------------------------------- events

  input.addEventListener('input', () => {
    render();
    if (failed && !loading) load().then(render);
  });

  input.addEventListener('keydown', (ev) => {
    if (ev.key === 'ArrowDown') { ev.preventDefault(); select(selected + 1); }
    else if (ev.key === 'ArrowUp') { ev.preventDefault(); select(selected - 1); }
    else if (ev.key === 'Enter') {
      const a = list.querySelectorAll('a')[selected];
      if (a) { ev.preventDefault(); close(); window.location.href = a.href; }
    }
  });

  list.addEventListener('click', (ev) => { if (ev.target.closest('a')) close(); });
  list.addEventListener('mousemove', (ev) => {
    const a = ev.target.closest('a[data-i]');
    if (a && Number(a.dataset.i) !== selected) select(Number(a.dataset.i));
  });
  root.addEventListener('click', (ev) => { if (ev.target.closest('[data-search-close]')) close(); });

  document.addEventListener('click', (ev) => {
    if (ev.target.closest('[data-search-open]')) { ev.preventDefault(); open(); }
  });

  document.addEventListener('keydown', (ev) => {
    const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName) || document.activeElement.isContentEditable;
    if (ev.key === 'Escape') close();
    else if ((ev.key === 'k' || ev.key === 'K') && (ev.metaKey || ev.ctrlKey)) { ev.preventDefault(); root.hidden ? open() : close(); }
    else if (ev.key === '/' && !typing) { ev.preventDefault(); open(); }
  });

  // Keep focus inside the dialog.
  root.addEventListener('keydown', (ev) => {
    if (ev.key === 'Tab') { ev.preventDefault(); input.focus(); }
  });
})();
