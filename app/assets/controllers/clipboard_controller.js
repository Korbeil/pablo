import { Controller } from '@hotwired/stimulus';

/**
 * Copies a textarea's contents to the clipboard.
 *
 * navigator.clipboard needs a secure context; http://127.0.0.1 counts as one,
 * so this works over the dashboard's plain HTTP. The execCommand path is the
 * fallback for anything that doesn't (e.g. reaching the server by LAN IP).
 */
export default class extends Controller {
    static targets = ['source', 'idle', 'done'];

    async copy() {
        const text = this.sourceTarget.value;

        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(text);
            } else {
                this.sourceTarget.select();
                document.execCommand('copy');
                this.sourceTarget.setSelectionRange(0, 0);
                this.sourceTarget.blur();
            }
        } catch (error) {
            console.error('clipboard copy failed', error);
            return;
        }

        this.flash();
    }

    flash() {
        if (!this.hasIdleTarget || !this.hasDoneTarget) {
            return;
        }
        this.idleTarget.hidden = true;
        this.doneTarget.hidden = false;
        window.clearTimeout(this.resetTimer);
        this.resetTimer = window.setTimeout(() => {
            this.idleTarget.hidden = false;
            this.doneTarget.hidden = true;
        }, 1500);
    }
}
