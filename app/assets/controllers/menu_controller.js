import { Controller } from '@hotwired/stimulus';

/**
 * A task row's "…" actions menu, on a native <details>/<summary>.
 *
 * The task tables scroll sideways inside their card (overflow-x: auto), which
 * would clip an absolutely positioned menu: the open list is placed with
 * position: fixed instead, next to its trigger, flipped above it when there
 * is no room below. On a phone the stylesheet turns it into a bottom sheet
 * and the inline position is left empty.
 *
 * Only one menu is open at a time; a click outside, Escape, a scroll or a
 * resize closes it (Escape gives focus back to the trigger). An item closes
 * it before opening its dialog (`menu#close live#emit`).
 */
const MOBILE = '(max-width: 768px)';
const GAP = 4;
const MARGIN = 8;

export default class extends Controller {
    static targets = ['list'];

    connect() {
        this.onOutside = (event) => {
            if (!this.element.contains(event.target)) {
                this.close();
            }
        };
        this.onKeydown = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                this.close();
                this.element.querySelector('summary')?.focus();
            }
        };
        this.onViewportChange = (event) => {
            // Scrolling inside the open sheet itself must not close it.
            if (event.type === 'scroll' && this.hasListTarget && this.listTarget.contains(event.target)) {
                return;
            }
            this.close();
        };
    }

    disconnect() {
        this.unlisten();
    }

    toggle() {
        if (!this.element.open) {
            this.unlisten();
            return;
        }

        document.querySelectorAll('details.pablo-menu[open]').forEach((other) => {
            if (other !== this.element) {
                other.open = false;
            }
        });
        this.place();
        document.addEventListener('click', this.onOutside, true);
        document.addEventListener('keydown', this.onKeydown);
        window.addEventListener('scroll', this.onViewportChange, true);
        window.addEventListener('resize', this.onViewportChange);
    }

    close() {
        this.element.open = false;
    }

    place() {
        const list = this.listTarget;
        list.style.top = '';
        list.style.left = '';

        if (window.matchMedia(MOBILE).matches) {
            return;
        }

        const trigger = this.element.querySelector('summary').getBoundingClientRect();
        const menu = list.getBoundingClientRect();
        const below = trigger.bottom + GAP;
        const top = below + menu.height > window.innerHeight - MARGIN && trigger.top - GAP - menu.height >= MARGIN
            ? trigger.top - GAP - menu.height
            : below;
        const left = Math.max(MARGIN, Math.min(trigger.right - menu.width, window.innerWidth - menu.width - MARGIN));

        list.style.top = `${Math.round(top)}px`;
        list.style.left = `${Math.round(left)}px`;
    }

    unlisten() {
        document.removeEventListener('click', this.onOutside, true);
        document.removeEventListener('keydown', this.onKeydown);
        window.removeEventListener('scroll', this.onViewportChange, true);
        window.removeEventListener('resize', this.onViewportChange);
    }
}
