import { Controller } from '@hotwired/stimulus';

/**
 * Escape closes a dashboard modal.
 *
 * The modals are Live components whose open/closed state lives on the
 * server, so closing must go through their `close` LiveAction. The backdrop
 * already carries that action: Escape simply clicks it. Binding
 * `live#action` to a window event directly doesn't work — LiveComponent
 * reads the action's parameters from the event's element, and window has none.
 *
 * Only reacts while the modal is open (Bulma's `is-active`), so a press with
 * every modal closed sends no request.
 */
export default class extends Controller {
    static targets = ['backdrop'];

    close(event) {
        if (!this.element.classList.contains('is-active') || !this.hasBackdropTarget) {
            return;
        }
        event.preventDefault();
        this.backdropTarget.click();
    }
}
