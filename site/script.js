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
    { id: 'sso', branch: 'oms-1290-sso-login', state: 'in-progress', pr: '—', agents: '🏃 1' },
    { id: 'csv', branch: 'oms-1274-export-csv', state: 'draft', pr: '📪 #1281', agents: '—' },
    { id: 'cache', branch: 'oms-1251-cache-bust', state: 'waiting-review', pr: '📬 #1262', agents: '—' },
    { id: 'vat', branch: 'oms-1238-vat-rounding', state: 'needs-testing', pr: '📬 #1245', agents: '—' },
  ];

  const timeline = [
    { t: '22:58', msg: 'oms-1290 · <b>task-analyst</b> done, plan ready for you', set: ['sso', { agents: '💭 1' }] },
    { t: '23:04', msg: 'oms-1274 · CI turned red → <b>ci-red</b>', set: ['csv', { state: 'ci-red' }] },
    { t: '23:04', msg: 'oms-1274 · launching <b>ci-analyst</b> in its worktree', set: ['csv', { agents: '🏃 1' }] },
    { t: '23:14', msg: 'oms-1251 · approved by a reviewer → <b>needs-testing</b>', set: ['cache', { state: 'needs-testing' }] },
    { t: '23:19', msg: 'oms-1274 · <b>ci-analyst</b> done, fix plan waiting', set: ['csv', { agents: '💭 1' }] },
    { t: '07:42', msg: 'you · <b>/pablo-commit-and-pr</b> on oms-1274 → draft', set: ['csv', { state: 'draft', agents: '—' }] },
    { t: '07:52', msg: 'oms-1274 · CI green, PR marked ready → <b>waiting-review</b>', set: ['csv', { state: 'waiting-review', pr: '📬 #1281' }] },
    { t: '08:02', msg: 'oms-1238 · merged → task closed, worktree removed', remove: 'vat' },
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

  function runTerminal() {
    if (!tbody || !log) return;
    renderRows();
    if (reduceMotion) {
      timeline.slice(0, 4).forEach((e) => addLog(e.t, e.msg));
      return;
    }
    let i = 0;
    const tick = () => {
      if (i === timeline.length) {
        rows = initialRows();
        log.innerHTML = '';
        renderRows();
        i = 0;
        setTimeout(tick, 1800);
        return;
      }
      const e = timeline[i++];
      if (e.set) {
        const [id, patch] = e.set;
        rows = rows.map((r) => (r.id === id ? { ...r, ...patch } : r));
        renderRows(id);
      } else if (e.remove) {
        rows = rows.filter((r) => r.id !== e.remove);
        renderRows();
      }
      addLog(e.t, e.msg);
      setTimeout(tick, i === timeline.length ? 4200 : 2400);
    };
    setTimeout(tick, 1400);
  }

  /* ------------------------------------------------------------------ *
   * State machine explorer
   * ------------------------------------------------------------------ */
  const DETAILS = {
    'in-progress': {
      trigger: 'you · pablo task:start <issue-url | prompt>',
      agent: 'task-analyst',
      body: 'A branch and worktree are created off <code>origin/main</code>. <b>task-analyst</b> reads the ticket, its parents, linked PRs and the code, and writes a plan. It runs once per task. Your project\'s startup script runs alongside it.',
    },
    'draft': {
      trigger: 'you · /pablo-commit-and-pr',
      body: 'The only way in is a command you type. It commits, pushes and opens the PR <b>as a draft</b>. From there the poller watches CI.',
    },
    'ci-red': {
      trigger: 'poller · a check failed',
      agent: 'ci-analyst',
      body: '<b>ci-analyst</b> fetches the failing checks and their logs, links each failure to the code, and proposes a fix. Checks you\'ve told PABLO to ignore (e.g. manual approval gates) don\'t count.',
    },
    'ready-to-review': {
      trigger: 'poller · CI is green',
      body: 'PABLO takes the PR out of draft on GitHub and moves the task straight on to <code>waiting-review</code>.',
    },
    'waiting-review': {
      trigger: 'chained from ready-to-review',
      body: 'Nothing runs here. This is where a task waits for humans. The poller keeps checking reviews and CI. Your own replies and bots don\'t count as reviews, and an approval from an earlier round doesn\'t carry over.',
    },
    'approved': {
      trigger: 'poller · review approved',
      body: 'Moves straight on to <code>needs-testing</code>. If the project has no QA step, the task stays here until it\'s merged.',
    },
    'needs-testing': {
      trigger: 'chained from approved',
      body: 'Records a timestamp as the QA baseline, then watches the tracker for a failure signal that comes after it.',
    },
    'request-changes': {
      trigger: 'poller · a reviewer asked for changes',
      agent: 'pr-feedback',
      body: 'The PR goes back to draft on GitHub. <b>pr-feedback</b> turns the review comments into a checklist so you don\'t have to dig through the thread.',
    },
    'testing-failed': {
      trigger: 'poller · QA found an issue',
      agent: 'task-feedback',
      body: 'The PR goes back to draft. <b>task-feedback</b> checks the QA feedback against the PR one point at a time, and drafts a reply for the ticket.',
    },
    'waiting': {
      trigger: 'you · pablo task:waiting',
      body: 'A manual pause: polling stops until you run the command again, then the task goes back to the state it was in. You can\'t pause from <code>request-changes</code> or <code>testing-failed</code>, because those already need you.',
    },
  };

  const panel = document.getElementById('sm-panel');
  const nodes = document.querySelectorAll('.node[data-state]');

  function select(state) {
    const d = DETAILS[state];
    if (!d || !panel) return;
    nodes.forEach((n) => n.classList.toggle('active', n.dataset.state === state));
    panel.innerHTML = `<h3>${state}</h3><div class="trig">${esc(d.trigger)}</div><p>${d.body}</p>`
      + (d.agent ? `<span class="agent">launches → ${d.agent}</span>` : '');
  }

  nodes.forEach((n) => {
    n.addEventListener('click', () => select(n.dataset.state));
    n.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); select(n.dataset.state); }
    });
  });

  select('in-progress');
  runTerminal();
})();
