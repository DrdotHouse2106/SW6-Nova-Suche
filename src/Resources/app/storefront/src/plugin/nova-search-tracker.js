/**
 * Anonyme Nutzungssignale für das Lernen der Suche.
 *
 * - Ereignisse werden gesammelt und gebündelt per navigator.sendBeacon verschickt
 *   (überlebt auch einen Seitenwechsel nach einem Klick).
 * - „Kauf nach Suche“: Nur mit Zustimmung zur Cookie-Kategorie „Statistik“ merkt sich der Browser im
 *   Cookie „nova-search-attr“ kurzzeitig, welches Produkt über welchen Begriff angeklickt wurde.
 */
const ATTRIBUTION_COOKIE = 'nova-search-attr';
const ATTRIBUTION_MAX = 15;
const ATTRIBUTION_DAYS = 2;

export function hasCookie(name) {
    return document.cookie.split('; ').indexOf(`${name}=1`) !== -1;
}

export default class NovaSearchTracker {
    constructor(url, { enabled = true, attributionAllowed = () => false } = {}) {
        this._url = url;
        this._enabled = enabled && !!url;
        this._attributionAllowed = attributionAllowed;
        this._queue = [];
        this._timer = null;

        if (this._enabled) {
            const flush = () => this.flush();
            window.addEventListener('pagehide', flush);
            document.addEventListener('visibilitychange', () => document.visibilityState === 'hidden' && flush());
        }
    }

    search(term, hits, rewritten = false) {
        this._push({ type: 'search', term, hits, rewritten });
    }

    /** Begriff ohne Klick, direkt danach ein anderer Begriff mit Klick – Grundlage für gelernte Korrekturen */
    refine(term, target) {
        this._push({ type: 'refine', term, target });
    }

    click(term, productId) {
        this._push({ type: 'click', term, productId });
        this.remember(productId, term);
        this.flush();
    }

    cart(term, productId) {
        this._push({ type: 'cart', term, productId });
        this.flush();
    }

    /** Suchbegriff, über den ein Produkt gefunden wurde (nur mit Zustimmung) */
    termFor(productId) {
        return this._attribution()[productId] || null;
    }

    remember(productId, term) {
        if (!productId || !term || !this._attributionAllowed()) {
            return;
        }

        const map = this._attribution();
        delete map[productId];
        map[productId] = term.slice(0, 60);

        // Nur die letzten Einträge behalten (Cookie klein halten)
        const entries = Object.entries(map).slice(-ATTRIBUTION_MAX);
        const value = encodeURIComponent(JSON.stringify(Object.fromEntries(entries)));
        document.cookie = `${ATTRIBUTION_COOKIE}=${value}; Max-Age=${ATTRIBUTION_DAYS * 86400}; path=/; SameSite=Lax`;
    }

    flush() {
        window.clearTimeout(this._timer);
        if (!this._enabled || this._queue.length === 0) {
            return;
        }

        const body = JSON.stringify({ events: this._queue.splice(0, 20) });
        // text/plain ist für sendBeacon ohne Vorabanfrage erlaubt; der Server liest den Inhalt als JSON
        const blob = new Blob([body], { type: 'text/plain' });
        const sent = navigator.sendBeacon && navigator.sendBeacon(this._url, blob);
        if (!sent) {
            fetch(this._url, { method: 'POST', body, keepalive: true, credentials: 'same-origin' }).catch(() => {});
        }

        if (this._queue.length > 0) {
            this.flush();
        }
    }

    _push(event) {
        if (!this._enabled || !event.term) {
            return;
        }
        this._queue.push(event);
        window.clearTimeout(this._timer);
        this._timer = window.setTimeout(() => this.flush(), 2000);
    }

    _attribution() {
        const match = document.cookie.match(new RegExp(`(?:^|; )${ATTRIBUTION_COOKIE}=([^;]*)`));
        if (!match) {
            return {};
        }
        try {
            const value = JSON.parse(decodeURIComponent(match[1]));
            return value && typeof value === 'object' ? value : {};
        } catch (e) {
            return {};
        }
    }
}
