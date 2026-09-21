import { Controller } from '@hotwired/stimulus';

/**
 * Cycles the dashboard colour theme: light -> dark -> system -> light.
 *
 * 'light'/'dark' are stored in localStorage('pablo-theme') and applied as
 * data-theme on <html> (the head bootstrap script re-applies them before
 * first paint on the next load). 'system' removes both, so Bulma's
 * prefers-color-scheme media query takes over — and keeps tracking OS
 * changes while the page stays open. Storage is best-effort: some privacy
 * modes throw on access, and the theme must never crash the page over it.
 */
const ORDER = ['light', 'dark', 'system'];

const LABELS = {
    light: 'Theme: light — click for dark',
    dark: 'Theme: dark — click for system',
};

export default class extends Controller {
    connect() {
        this.media = window.matchMedia('(prefers-color-scheme: dark)');
        // OS flips must update the tooltip while the state is "system";
        // the sun/moon glyph handles the same flip in pure CSS.
        this.onSystemChange = () => {
            if (currentTheme() === 'system') {
                this.label('system');
            }
        };
        this.media.addEventListener('change', this.onSystemChange);
        this.label(currentTheme());
        this.syncGlyphs();
    }

    disconnect() {
        this.media.removeEventListener('change', this.onSystemChange);
    }

    toggle() {
        const next = ORDER[(ORDER.indexOf(currentTheme()) + 1) % ORDER.length];

        try {
            if (next === 'system') {
                localStorage.removeItem('pablo-theme');
            } else {
                localStorage.setItem('pablo-theme', next);
            }
        } catch (error) {
            // Keep switching for this page view even if persistence failed.
        }

        if (next === 'system') {
            delete document.documentElement.dataset.theme;
        } else {
            document.documentElement.dataset.theme = next;
        }

        this.label(next);
        this.syncGlyphs();
    }

    /**
     * Sets hidden on the two inactive glyphs so exactly one icon shows even
     * if the stylesheet loads broken or partially. CSS does the same job
     * from the same source (the html[data-theme] attribute), so the two can
     * never disagree — this is pure defence in depth.
     *
     * toggleAttribute, not `.hidden =`: `hidden` is an HTMLElement property,
     * so assigning it on an SVGElement sets a JS expando and writes nothing
     * to the DOM — the silent no-op that once let all three glyphs show.
     */
    syncGlyphs() {
        const state = document.documentElement.dataset.theme;
        const active = state === 'light' || state === 'dark' ? state : 'system';
        for (const glyph of this.element.querySelectorAll('.pablo-theme-icon')) {
            glyph.toggleAttribute('hidden', !glyph.classList.contains(`pablo-theme-icon-${active}`));
        }
    }

    label(theme) {
        let text;
        if (theme === 'system') {
            const resolved = this.media.matches ? 'dark' : 'light';
            text = `Theme: system (using ${resolved}) — click for light`;
        } else {
            text = LABELS[theme] ?? LABELS.light;
        }
        this.element.setAttribute('aria-label', text);
        this.element.title = text;
    }
}

function currentTheme() {
    const stored = safeGet();
    if (stored === 'light' || stored === 'dark') {
        return stored;
    }

    return 'system';
}

function safeGet() {
    try {
        return localStorage.getItem('pablo-theme');
    } catch (error) {
        return null;
    }
}
