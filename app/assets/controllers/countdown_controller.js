import { Controller } from '@hotwired/stimulus';

/**
 * Drains a poller progress bar between server renders.
 *
 * PollProgress re-renders only every 15s, which would make the bar jump. This
 * interpolates locally once a second from the two epoch timestamps the server
 * sent, and every re-render re-syncs the values — so clock drift can never
 * accumulate.
 */
export default class extends Controller {
    static targets = ['bar', 'label'];
    static values = {
        start: Number, // epoch seconds of the last poll (or now, if never polled)
        end: Number,   // epoch seconds of the next scheduled poll
    };

    connect() {
        this.tick();
        this.timer = window.setInterval(() => this.tick(), 1000);
    }

    disconnect() {
        window.clearInterval(this.timer);
    }

    tick() {
        const now = Date.now() / 1000;
        const span = Math.max(1, this.endValue - this.startValue);
        const remaining = Math.max(0, this.endValue - now);
        const percent = Math.min(100, (remaining / span) * 100);

        if (this.hasBarTarget) {
            this.barTarget.value = percent;
        }
        if (this.hasLabelTarget) {
            this.labelTarget.textContent = this.format(Math.round(remaining));
        }
    }

    format(seconds) {
        if (seconds <= 0) {
            return 'due now';
        }
        if (seconds < 60) {
            return `${seconds}s`;
        }
        const minutes = Math.floor(seconds / 60);
        if (minutes < 60) {
            const rest = seconds % 60;
            return rest === 0 ? `${minutes}m` : `${minutes}m ${rest}s`;
        }
        return `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
    }
}
