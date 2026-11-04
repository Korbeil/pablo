/* Documentation pages: copy buttons, the mobile sidebar, and the
   "On this page" highlight that follows your reading position. */
(() => {
  'use strict';

  // Copy button on every code block.
  document.querySelectorAll('.code pre').forEach((pre) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'code-copy';
    button.textContent = 'Copy';
    button.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(pre.innerText.replace(/\n$/, ''));
        button.textContent = 'Copied';
        button.classList.add('is-done');
      } catch {
        button.textContent = 'Press Ctrl+C';
      }
      setTimeout(() => { button.textContent = 'Copy'; button.classList.remove('is-done'); }, 1600);
    });
    pre.parentElement.appendChild(button);
  });

  // Mobile sidebar.
  const sidebar = document.getElementById('docs-sidebar');
  const toggle = document.querySelector('[data-sidebar-toggle]');
  if (sidebar && toggle) {
    const set = (open) => {
      sidebar.classList.toggle('is-open', open);
      document.body.classList.toggle('sidebar-open', open);
      toggle.setAttribute('aria-expanded', String(open));
    };
    // Leaving the mobile layout (rotation, resize) must not keep the page locked.
    window.matchMedia('(min-width: 901px)').addEventListener('change', (ev) => { if (ev.matches) set(false); });
    toggle.addEventListener('click', () => set(!sidebar.classList.contains('is-open')));
    sidebar.addEventListener('click', (ev) => { if (ev.target.closest('a')) set(false); });
    document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') set(false); });
    document.addEventListener('click', (ev) => {
      if (sidebar.classList.contains('is-open') && !sidebar.contains(ev.target) && !toggle.contains(ev.target)) set(false);
    });
  }

  // Folded "On this page" (narrow screens): sit under the page's lead
  // paragraph rather than above its title, and fold back once used.
  const tocMobile = document.querySelector('.toc-mobile');
  if (tocMobile) {
    const lead = document.querySelector('.prose > h1 + p');
    if (lead) lead.after(tocMobile);
    tocMobile.addEventListener('click', (ev) => { if (ev.target.closest('a')) tocMobile.open = false; });
  }

  // Highlight the section being read in "On this page".
  const links = new Map();
  document.querySelectorAll('.docs-toc a[href^="#"]').forEach((a) => links.set(decodeURIComponent(a.hash.slice(1)), a));
  const headings = [...document.querySelectorAll('.prose h2[id], .prose h3[id]')].filter((h) => links.has(h.id));
  if (headings.length) {
    const update = () => {
      let current = headings[0];
      for (const h of headings) {
        if (h.getBoundingClientRect().top < 120) current = h; else break;
      }
      links.forEach((a, id) => a.classList.toggle('is-active', id === current.id));
    };
    let ticking = false;
    window.addEventListener('scroll', () => {
      if (!ticking) { requestAnimationFrame(() => { update(); ticking = false; }); ticking = true; }
    }, { passive: true });
    update();
  }
})();
