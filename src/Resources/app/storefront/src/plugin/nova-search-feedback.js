/**
 * Rückmeldung zum Suchergebnis: „Gefällt Ihnen das Suchergebnis?“ (Suchfenster und Suchergebnisseite).
 *
 * - Daumen hoch → sofort senden, „Danke!“
 * - Daumen runter → sofort senden, danach optionales Textfeld („Was haben Sie gesucht?“, max. 200 Zeichen);
 *   „Senden“ reicht den Text nach, danach „Danke!“
 *
 * Ein Klick-/Submit-Handler auf document deckt beide Stellen ab – auch die per HTML nachgeladenen
 * Inhalte des Suchfensters. Gesendet wird als JSON mit dem Sitzungs-Cookie (gleiche Website).
 * Bereits bewertete Begriffe merkt sich der Browser für die Sitzung (sessionStorage), damit das
 * Suchfenster beim erneuten Anzeigen gleich „Danke!“ zeigt; der Server prüft das ohnehin selbst.
 */
const STORAGE_KEY = 'nova-search-feedback';
const MAX_TEXT = 200;

function key(box) {
    return `${new Date().toISOString().slice(0, 10)}|${(box.getAttribute('data-term') || '').trim().toLowerCase()}`;
}

function rated() {
    try {
        const list = JSON.parse(window.sessionStorage.getItem(STORAGE_KEY) || '[]');
        return Array.isArray(list) ? list : [];
    } catch (e) {
        return [];
    }
}

function remember(box) {
    try {
        const list = rated().filter((entry) => entry !== key(box));
        list.push(key(box));
        window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify(list.slice(-50)));
    } catch (e) { /* Speicher gesperrt */ }
}

function send(box, data) {
    const url = box.getAttribute('data-url');
    if (!url) {
        return;
    }
    const body = JSON.stringify({
        term: box.getAttribute('data-term') || '',
        source: box.getAttribute('data-source') || 'page',
        ...data,
    });
    fetch(url, {
        method: 'POST',
        body,
        keepalive: true,
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    }).catch(() => {});
}

function thank(box, focus = false) {
    box.setAttribute('data-done', '1');
    box.querySelector('[data-nova-search-feedback-ask]')?.setAttribute('hidden', '');
    box.querySelector('[data-nova-search-feedback-form]')?.setAttribute('hidden', '');
    const status = box.querySelector('[data-nova-search-feedback-status]');
    if (status) {
        status.textContent = status.getAttribute('data-thanks') || '';
        if (focus) {
            status.setAttribute('tabindex', '-1');
            status.focus({ preventScroll: true });
        }
    }
}

/** Schon bewertete Begriffe direkt mit „Danke!“ anzeigen */
export function applyFeedbackState(root = document) {
    const done = rated();
    root.querySelectorAll('[data-nova-search-feedback]:not([data-done])').forEach((box) => {
        if (done.includes(key(box))) {
            thank(box);
        }
    });
}

function onClick(event) {
    const button = event.target.closest('[data-nova-search-feedback-rating]');
    const box = button && button.closest('[data-nova-search-feedback]');
    if (!box || box.hasAttribute('data-done') || box.hasAttribute('data-rated')) {
        return;
    }

    const rating = button.getAttribute('data-nova-search-feedback-rating') === 'down' ? 'down' : 'up';
    box.setAttribute('data-rated', rating);
    send(box, { rating });
    remember(box);

    if (rating === 'up') {
        thank(box, true);
        return;
    }

    box.querySelector('[data-nova-search-feedback-ask]')?.setAttribute('hidden', '');
    const form = box.querySelector('[data-nova-search-feedback-form]');
    if (!form) {
        thank(box, true);
        return;
    }
    form.removeAttribute('hidden');
    form.querySelector('input[name="text"]')?.focus();
}

function onSubmit(event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-nova-search-feedback-form]')) {
        return;
    }
    event.preventDefault();

    const box = form.closest('[data-nova-search-feedback]');
    if (!box || box.hasAttribute('data-done')) {
        return;
    }

    const text = (form.elements.text?.value || '').replace(/\s+/g, ' ').trim().slice(0, MAX_TEXT);
    if (text !== '') {
        send(box, { rating: 'down', text });
    }
    thank(box, true);
}

export default function initSearchFeedback() {
    document.addEventListener('click', onClick);
    document.addEventListener('submit', onSubmit);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => applyFeedbackState());
    } else {
        applyFeedbackState();
    }
}
