(() => {
  'use strict';

  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ------------------------------------------------------------------ *
   * Hero terminal: a night in the life of `pablo task:list`
   * ------------------------------------------------------------------ */
  const STATES = {
    'in-progress':    ['🔨 in-progress', 'st-progress'],
    'draft':          ['📝 draft', 'st-draft'],
    'ci-red':         ['🔴 ci-red', 'st-red'],
    'waiting-review': ['👀 waiting-review', 'st-review'],
    'approved':       ['✅ approved', 'st-approved'],
    'needs-testing':  ['🧪 needs-testing', 'st-testing'],
  };

  const initialRows = () => [
    { id: 'sso', branch: 'acme-1290-sso-login', state: 'in-progress', pr: '—', agents: '🏃 1' },
    { id: 'csv', branch: 'acme-1274-export-csv', state: 'draft', pr: '📪 #1281', agents: '—' },
    { id: 'cache', branch: 'acme-1251-cache-bust', state: 'waiting-review', pr: '📬 #1262', agents: '—' },
    { id: 'vat', branch: 'acme-1238-vat-rounding', state: 'needs-testing', pr: '📬 #1245', agents: '—' },
  ];

  const timeline = [
    { t: '22:58', msg: 'acme-1290 · <b>task-analyst</b> done, plan ready for you', set: ['sso', { agents: '💭 1' }] },
    { t: '23:04', msg: 'acme-1274 · CI turned red → <b>ci-red</b>', set: ['csv', { state: 'ci-red' }] },
    { t: '23:04', msg: 'acme-1274 · launching <b>ci-analyst</b> in its worktree', set: ['csv', { agents: '🏃 1' }] },
    { t: '23:14', msg: 'acme-1251 · approved by a reviewer → <b>needs-testing</b>', set: ['cache', { state: 'needs-testing' }] },
    { t: '23:19', msg: 'acme-1274 · <b>ci-analyst</b> done, fix plan waiting', set: ['csv', { agents: '💭 1' }] },
    { t: '07:42', msg: 'you · <b>/pablo-commit-and-pr</b> on acme-1274 → draft', set: ['csv', { state: 'draft', agents: '—' }] },
    { t: '07:52', msg: 'acme-1274 · CI green, PR marked ready → <b>waiting-review</b>', set: ['csv', { state: 'waiting-review', pr: '📬 #1281' }] },
    { t: '08:02', msg: 'acme-1238 · merged → task closed, worktree removed', remove: 'vat' },
  ];

  const tbody = document.getElementById('term-rows');
  const log = document.getElementById('term-log');
  let rows = initialRows();

  const esc = (s) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

  function renderRows(flashId) {
    tbody.innerHTML = rows.map((r) => {
      const [label, cls] = STATES[r.state];
      return `<tr data-id="${r.id}"${r.id === flashId ? ' class="flash"' : ''}>`
        + `<td>${esc(r.branch)}</td>`
        + `<td><span class="st ${cls}">${label}</span></td>`
        + `<td class="${r.pr === '—' ? 'dim' : ''}">${r.pr}</td>`
        + `<td class="${r.agents === '—' ? 'dim' : ''}">${r.agents}</td></tr>`;
    }).join('');
  }

  function addLog(t, msg) {
    const line = document.createElement('div');
    line.innerHTML = `<time>${t}</time>${msg}`;
    log.appendChild(line);
    while (log.children.length > 5) log.removeChild(log.firstChild);
  }

  function apply(e, flash) {
    if (e.set) {
      const [id, patch] = e.set;
      rows = rows.map((r) => (r.id === id ? { ...r, ...patch } : r));
      renderRows(flash ? id : undefined);
    } else if (e.remove) {
      rows = rows.filter((r) => r.id !== e.remove);
      renderRows();
    }
    addLog(e.t, e.msg);
  }

  // The loop never ends, so it gets a pause button (WCAG 2.2.2). With
  // reduced motion it starts paused, half-way through the night.
  function runTerminal() {
    if (!tbody || !log) return;
    const toggle = document.getElementById('term-toggle');
    let i = 0;
    let timer = null;
    let paused = reduceMotion;

    const schedule = (delay) => {
      clearTimeout(timer);
      timer = paused ? null : setTimeout(tick, delay);
    };
    const tick = () => {
      if (i === timeline.length) {
        rows = initialRows();
        log.innerHTML = '';
        renderRows();
        i = 0;
        schedule(1800);
        return;
      }
      apply(timeline[i++], true);
      schedule(i === timeline.length ? 4200 : 2400);
    };

    renderRows();
    if (reduceMotion) {
      while (i < 4) apply(timeline[i++], false);
    }
    if (toggle) {
      const label = () => { toggle.textContent = paused ? 'Play animation' : 'Pause animation'; };
      toggle.hidden = false;
      label();
      toggle.addEventListener('click', () => {
        paused = !paused;
        label();
        schedule(800);
      });
    }
    schedule(1400);
  }

  document.querySelectorAll('[data-copy]').forEach((btn) => {
    btn.addEventListener('click', () => {
      navigator.clipboard?.writeText(btn.dataset.copy).then(() => {
        btn.textContent = 'Copied';
        setTimeout(() => { btn.textContent = 'Copy'; }, 1500);
      });
    });
  });

  runTerminal();
})();
