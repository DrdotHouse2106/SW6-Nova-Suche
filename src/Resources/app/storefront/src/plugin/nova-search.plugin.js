import NovaSearchTracker, { hasCookie } from './nova-search-tracker';

const { PluginBaseClass } = window;
const EXACT_KEY = 'nova-search-exact';

/**
 * Suchfenster im Vollbild – öffnet sich beim Klick ins Suchfeld.
 *
 * Ohne Suchbegriff: beliebte Suchanfragen, zuletzt angesehene und Highlight-Produkte.
 * Mit Suchbegriff:  Filter (Marken, Preis), Sortierung, passende Kategorien und
 *                   Produktkarten, die beim Scrollen nachgeladen werden.
 *
 * Alle Inhalte kommen als HTML vom Server (Route frontend.nova-search.instant).
 * Anfragen werden verzögert, veraltete abgebrochen und Antworten zwischengespeichert.
 *
 * Lernen: Suchen (nach einer Tipp-Pause), Klicks und „In den Warenkorb“ nach einer Suche werden
 * anonym an den Server gemeldet (nova-search-tracker.js). Auf Produktseiten wird die Liste
 * „Zuletzt angesehen“ im localStorage gepflegt.
 *
 * Schnittstelle für andere Plugins (Events auf document):
 *  - nova:search-open / nova:search-close     Suchfenster geöffnet / geschlossen
 *  - nova:search            { term, total }  Ergebnis angezeigt (nach Tipp-Pause, je Begriff einmal)
 *  - nova:products-rendered { container }    Produktkarten eingefügt
 */
export default class NovaSearchPlugin extends PluginBaseClass {
    static options = {
        url: '',
        searchUrl: '/search',
        minChars: 3,
        debounce: 180,
        inputSelector: 'input[type=search]',
        toggleButtonSelector: '.js-search-toggle-btn',
        focusableSelector: '.nova-search-focusable',
        trackUrl: '',
        learning: true,
        recentlyViewed: true,
        recentlyViewedConsent: true,
        recentStorageKey: 'nova-recently-viewed',
        recentMax: 20,
        searchSignalDelay: 1200,
        exactNumberParam: 'exact-number',
        recentSearches: true,
        recentSearchesKey: 'nova-search-history',
        recentSearchesMax: 8,
        labels: {},
    };

    init() {
        this._tracker = new NovaSearchTracker(this.options.trackUrl, {
            enabled: this.options.learning,
            attributionAllowed: () => hasCookie('nova-search-learning'),
        });

        // Zurückgehaltene Suche beim Verlassen der Seite noch melden
        window.addEventListener('pagehide', () => {
            this._releaseHeldSearch();
            this._tracker.flush();
        });

        this._rememberProductView();
        this._registerPageTracking();

        this._headerInput = this.el.querySelector(this.options.inputSelector);
        this._template = this.el.querySelector('[data-nova-search-template]');
        if (!this._headerInput || !this._template || !this.options.url) {
            return;
        }

        this._cache = new Map();
        this._request = null;
        this._timer = null;
        this._state = this._emptyState('');

        this._restoreSearchTerm();
        this._registerTriggers();
    }

    _isExact() {
        return !!(this._exact && this._exact.checked);
    }

    _storedExact() {
        try {
            return window.sessionStorage.getItem(EXACT_KEY) === '1';
        } catch (e) {
            return false;
        }
    }

    _exactFromUrl() {
        return window.activeRoute === 'frontend.search.page'
            && new URLSearchParams(window.location.search).get(this.options.exactNumberParam) === '1';
    }

    _searchPageUrl(term) {
        const params = new URLSearchParams({ search: term });
        if (this._isExact()) {
            params.set(this.options.exactNumberParam, '1');
        }
        return `${this.options.searchUrl}?${params.toString()}`;
    }

    _emptyState(term) {
        return { term, manufacturers: new Set(), properties: new Set(), min: null, max: null, order: '', page: 1 };
    }

    /* ---------- Öffnen über das Suchfeld im Header ---------- */

    _registerTriggers() {
        const openFromHeader = (event) => {
            event.preventDefault();
            this.open(this._headerInput.value);
        };

        this._headerInput.addEventListener('focus', openFromHeader);
        this._headerInput.addEventListener('click', openFromHeader);

        // Handy: Lupen-Button öffnet direkt das Fenster statt das Suchfeld aufzuklappen
        document.addEventListener('click', (event) => {
            const toggle = event.target.closest(this.options.toggleButtonSelector);
            if (toggle) {
                event.preventDefault();
                event.stopImmediatePropagation();
                this.open(this._headerInput.value);
            }
        }, true);
    }

    /** Suchbegriff auf der Ergebnisseite wieder ins Feld schreiben (Header kommt per ESI) */
    _restoreSearchTerm() {
        if (this._headerInput.value === '' && window.activeRoute === 'frontend.search.page') {
            this._headerInput.value = new URLSearchParams(window.location.search).get('search') || '';
        }
    }

    /* ---------- Fenster ---------- */

    _build() {
        const fragment = this._template.content.cloneNode(true);
        this._backdrop = fragment.querySelector('.nova-search-backdrop');
        this._layer = fragment.querySelector('.nova-search-layer');
        this._input = fragment.querySelector('.nova-search-input');
        this._body = fragment.querySelector('.nova-search-body');
        document.body.append(fragment);

        this._input.addEventListener('input', () => this._onInput());

        // Checkbox „Nur exakte Teilenummer“ – bleibt für die Sitzung erhalten
        this._exact = this._layer.querySelector('[data-nova-search-exact]');
        if (this._exact) {
            this._exact.checked = this._exactFromUrl() || this._storedExact();
            this._exact.addEventListener('change', () => {
                try {
                    window.sessionStorage.setItem(EXACT_KEY, this._exact.checked ? '1' : '0');
                } catch (e) { /* Speicher gesperrt */ }
                this._state = this._emptyState(this._input.value.trim());
                this._load();
                this._input.focus();
            });
        }
        this._input.addEventListener('keydown', (event) => this._onInputKeydown(event));
        this._layer.querySelector('.nova-search-close').addEventListener('click', () => this.close());
        this._backdrop.addEventListener('click', () => this.close());
        this._layer.addEventListener('keydown', (event) => this._onLayerKeydown(event));

        // Esc schließt immer – auch wenn der Fokus gerade nicht im Fenster liegt (z. B. nach einer Auswahlliste)
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && this.isOpen() && !this._layer.contains(event.target)) {
                this.close();
            }
        });
        this._body.addEventListener('click', (event) => this._onBodyClick(event));
        this._body.addEventListener('click', (event) => this._onCardClick(event), true);
        this._body.addEventListener('change', (event) => this._onBodyChange(event));
        this._body.addEventListener('input', (event) => this._onBodyInput(event));
        // Nach „In den Warenkorb“ schließen, damit der Warenkorb (Offcanvas) sichtbar ist
        this._body.addEventListener('submit', (event) => {
            if (event.target.matches('[data-nova-search-buy]')) {
                window.setTimeout(() => this.close(), 0);
            }
            if (event.target.matches('[data-nova-search-request]')) {
                event.preventDefault();
                this._sendRequest(event.target);
            }
        });
        // „Teil anfragen“ aufgeklappt → Fokus ins erste Kontaktfeld
        this._body.addEventListener('toggle', (event) => {
            if (event.target.matches('.nova-search-request') && event.target.open) {
                const field = event.target.querySelector('input[name="email"]');
                if (field) {
                    field.focus();
                }
            }
        }, true);

        // Weitere Produkte laden, sobald das Ende der Liste sichtbar wird
        this._observer = 'IntersectionObserver' in window
            ? new IntersectionObserver((entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    this._loadMore();
                }
            }, { root: this._layer, rootMargin: '400px 0px' })
            : null;
    }

    isOpen() {
        return !!this._layer && this._layer.classList.contains('is-open');
    }

    open(term = '') {
        if (!this._layer) {
            this._build();
        }

        if (!this.isOpen()) {
            this._lastFocus = document.activeElement;
            this._layer.classList.add('is-open');
            this._backdrop.classList.add('is-open');
            document.documentElement.classList.add('nova-search-locked');
            this._signaledTerms = new Set();
            this._heldSearch = null;
            this._refineFrom = null;
            document.dispatchEvent(new CustomEvent('nova:search-open'));
        }

        this._input.value = term.trim();
        this._input.focus();
        this._state = this._emptyState(this._input.value.trim());
        this._load();
    }

    close() {
        if (!this.isOpen()) {
            return;
        }

        this._abort();
        this._emitSearchSignal();
        this._releaseHeldSearch();
        this._layer.classList.remove('is-open');
        this._backdrop.classList.remove('is-open');
        document.documentElement.classList.remove('nova-search-locked');
        this._headerInput.value = this._input.value;

        // Fokus nicht ins Header-Suchfeld zurückgeben – das würde das Fenster sofort wieder öffnen
        if (this._lastFocus && this._lastFocus !== this._headerInput && document.contains(this._lastFocus)) {
            this._lastFocus.focus();
        }

        document.dispatchEvent(new CustomEvent('nova:search-close'));
    }

    /* ---------- Eingabe ---------- */

    _onInput() {
        window.clearTimeout(this._timer);
        const term = this._input.value.trim();

        // Neuer Suchbegriff → Filter zurücksetzen
        this._state = this._emptyState(term);
        this._timer = window.setTimeout(() => this._load(), this.options.debounce);
    }

    _onInputKeydown(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            const term = this._input.value.trim();
            if (term.length >= this.options.minChars) {
                // Die Suchergebnisseite zählt die Suche selbst – hier nicht doppelt melden.
                // Eine vorher gesuchte, andere Suche wird noch gemeldet; ein Zwischenstand dieses Begriffs nicht.
                this._cancelSearchSignal();
                if (this._heldSearch && NovaSearchPlugin.isTypingStep(this._heldSearch.term, term)) {
                    this._heldSearch = null;
                }
                this._releaseHeldSearch();
                this._rememberSearch(term);
                window.location.href = this._searchPageUrl(term);
            }
        } else if (event.key === 'ArrowDown') {
            event.preventDefault();
            this._focusables()[0]?.focus();
        }
    }

    _onLayerKeydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            this.close();
            return;
        }

        // Fokus im Fenster halten
        if (event.key === 'Tab') {
            const items = [this._input, ...this._focusables(), this._layer.querySelector('.nova-search-close')];
            const first = items[0];
            const last = items[items.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
            return;
        }

        if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && event.target.matches(this.options.focusableSelector)
            && event.target.tagName !== 'SELECT' && event.target.type !== 'range' && event.target.type !== 'number') {
            event.preventDefault();
            const items = this._focusables();
            const index = items.indexOf(event.target) + (event.key === 'ArrowDown' ? 1 : -1);
            (index < 0 ? this._input : items[Math.min(index, items.length - 1)]).focus();
        }
    }

    _focusables() {
        return [...this._body.querySelectorAll(this.options.focusableSelector)];
    }

    /* ---------- Filter, Sortierung, Startansicht ---------- */

    _onBodyClick(event) {
        const target = event.target;

        const removeHistory = target.closest('[data-nova-search-history-remove]');
        if (removeHistory) {
            this._removeRecentSearch(removeHistory.getAttribute('data-nova-search-history-remove'));
            this._renderRecentSearches();
            this._input.focus();
            return;
        }

        if (target.closest('[data-nova-search-history-clear]')) {
            this._saveRecentSearches([]);
            this._renderRecentSearches();
            this._input.focus();
            return;
        }

        const term = target.closest('[data-nova-search-term]');
        if (term) {
            this._input.value = term.getAttribute('data-nova-search-term');
            this._state = this._emptyState(this._input.value);
            this._input.focus();
            this._load();
            return;
        }

        const slide = target.closest('[data-nova-search-slide]');
        if (slide) {
            const track = slide.parentElement.querySelector('.nova-search-slider-track');
            track.scrollBy({ left: Number(slide.getAttribute('data-nova-search-slide')) * track.clientWidth * 0.8, behavior: 'smooth' });
            return;
        }

        if (target.closest('[data-nova-search-reset]')) {
            this._state = this._emptyState(this._state.term);
            this._load();
            return;
        }

        if (target.closest('[data-nova-search-expand]')) {
            target.closest('.nova-search-facet').querySelector('.is-collapsed')?.classList.remove('is-collapsed');
            target.remove();
            return;
        }

        const filterToggle = target.closest('[data-nova-search-filter-toggle]');
        if (filterToggle) {
            const open = this._body.querySelector('.nova-search-layout').classList.toggle('show-filters');
            filterToggle.setAttribute('aria-expanded', String(open));
            return;
        }

        if (target.closest('[data-nova-search-loadmore]')) {
            this._loadMore();
        }
    }

    _onBodyChange(event) {
        const target = event.target;

        if (target.matches('[data-nova-search-manufacturer]')) {
            target.checked ? this._state.manufacturers.add(target.value) : this._state.manufacturers.delete(target.value);
            this._reload();
        } else if (target.matches('[data-nova-search-property]')) {
            target.checked ? this._state.properties.add(target.value) : this._state.properties.delete(target.value);
            this._reload();
        } else if (target.matches('[data-nova-search-sort]')) {
            this._state.order = target.value;
            this._reload();
        } else if (target.matches('[data-nova-search-range], [data-nova-search-price-input]')) {
            this._applyPrice(target.closest('[data-nova-search-price]'));
        }
    }

    /** Schieberegler und Eingabefelder für den Preis synchron halten */
    _onBodyInput(event) {
        const target = event.target;
        if (!target.matches('[data-nova-search-range]')) {
            return;
        }

        const box = target.closest('[data-nova-search-price]');
        const [from, to] = box.querySelectorAll('[data-nova-search-range]');
        if (Number(from.value) > Number(to.value)) {
            target === from ? (from.value = to.value) : (to.value = from.value);
        }
        box.querySelector('[data-nova-search-price-input="from"]').value = from.value;
        box.querySelector('[data-nova-search-price-input="to"]').value = to.value;
    }

    _applyPrice(box) {
        const lower = Number(box.getAttribute('data-min'));
        const upper = Number(box.getAttribute('data-max'));
        let from = Number(box.querySelector('[data-nova-search-price-input="from"]').value);
        let to = Number(box.querySelector('[data-nova-search-price-input="to"]').value);

        if (from > to) {
            [from, to] = [to, from];
        }

        this._state.min = from > lower ? from : null;
        this._state.max = to < upper ? to : null;
        this._reload();
    }

    _reload() {
        this._state.page = 1;
        this._load();
    }

    /* ---------- Laden ---------- */

    _query(state) {
        const params = new URLSearchParams();
        if (state.term.length >= this.options.minChars) {
            params.set('search', state.term);
            if (state.manufacturers.size) params.set('manufacturer', [...state.manufacturers].sort().join('|'));
            if (state.properties.size) params.set('properties', [...state.properties].sort().join('|'));
            if (state.min !== null) params.set('min-price', state.min);
            if (state.max !== null) params.set('max-price', state.max);
            if (state.order) params.set('order', state.order);
            if (state.page > 1) params.set('p', state.page);
            if (this._isExact()) params.set(this.options.exactNumberParam, '1');
        } else {
            const recent = this._recentIds().slice(0, 12);
            if (recent.length) params.set('recent', recent.join('|'));
        }
        return params.toString();
    }

    async _fetch(query) {
        if (this._cache.has(query)) {
            return this._cache.get(query);
        }

        this._abort();
        this._request = new AbortController();
        this._layer.classList.add('is-loading');

        try {
            const response = await fetch(`${this.options.url}?${query}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                signal: this._request.signal,
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            const html = await response.text();
            this._cache.set(query, html);
            return html;
        } finally {
            this._layer.classList.remove('is-loading');
        }
    }

    async _load() {
        const query = this._query(this._state);

        try {
            const html = await this._fetch(query);
            // Inzwischen weitergetippt? Dann diese Antwort verwerfen
            if (query !== this._query(this._state)) {
                return;
            }
            const filtersOpen = this._body.querySelector('.nova-search-layout.show-filters') !== null;
            this._body.innerHTML = html;
            if (filtersOpen) {
                this._body.querySelector('.nova-search-layout')?.classList.add('show-filters');
                this._body.querySelector('[data-nova-search-filter-toggle]')?.setAttribute('aria-expanded', 'true');
            }
            this._afterRender();
        } catch (error) {
            if (error.name !== 'AbortError') {
                this._body.innerHTML = '';
            }
        }
    }

    async _loadMore() {
        const grid = this._body.querySelector('[data-nova-search-grid]');
        if (!grid || grid.getAttribute('data-nova-search-has-more') !== '1' || this._loadingMore) {
            return;
        }

        this._loadingMore = true;
        const state = { ...this._state, page: this._state.page + 1 };
        const query = this._query(state);

        try {
            const html = await this._fetch(query);
            if (this._query({ ...this._state, page: state.page }) !== query) {
                return;
            }
            const page = document.createElement('div');
            page.innerHTML = html;
            const list = page.querySelector('.nova-search-page');
            const added = [...list.children];
            grid.append(...added);
            if (added.length) {
                document.dispatchEvent(new CustomEvent('nova:products-rendered', { detail: { container: grid } }));
            }
            grid.setAttribute('data-nova-search-has-more', list.getAttribute('data-nova-search-has-more'));
            this._state.page = state.page;
            if (list.getAttribute('data-nova-search-has-more') !== '1') {
                this._body.querySelector('[data-nova-search-sentinel]')?.remove();
            }
            this._highlight(grid);
        } catch (error) {
            // Nachladen fehlgeschlagen – Button bleibt zum erneuten Versuch stehen
        } finally {
            this._loadingMore = false;
        }
    }

    _afterRender() {
        this._highlight(this._body);
        this._observer?.disconnect();
        const sentinel = this._body.querySelector('[data-nova-search-sentinel]');
        if (sentinel && this._observer) {
            this._observer.observe(sentinel);
        }
        this.$emitter.publish('novaSearch/render', { term: this._state.term, body: this._body });

        if (this._body.querySelector('[data-product-information]')) {
            document.dispatchEvent(new CustomEvent('nova:products-rendered', { detail: { container: this._body } }));
        }

        // „In den Warenkorb“-Formulare mit Shopwares Warenkorb-Plugin verbinden
        if (this._body.querySelector('[data-nova-search-buy]')) {
            try {
                window.PluginManager?.initializePlugins();
            } catch (e) { /* ohne JS-Plugin funktioniert das Formular trotzdem (Seitenwechsel) */ }
        }

        this._renderRecentSearches();

        const results = this._body.querySelector('.nova-search-results');
        if (results && this._state.page === 1 && this._state.term.length >= this.options.minChars) {
            this._scheduleSearchSignal(
                this._state.term,
                Number(results.getAttribute('data-nova-search-total')) || 0,
                results.hasAttribute('data-nova-search-rewritten')
            );
        }
    }

    /* ---------- Lernen: Suche, Klick, Warenkorb ---------- */

    /** Suche erst nach einer Tipp-Pause melden – nicht bei jedem Zwischenstand */
    _scheduleSearchSignal(term, total, rewritten) {
        window.clearTimeout(this._signalTimer);
        this._pendingSignal = { term, total, rewritten };
        this._signalTimer = window.setTimeout(() => this._emitSearchSignal(), this.options.searchSignalDelay);
    }

    _cancelSearchSignal() {
        window.clearTimeout(this._signalTimer);
        this._pendingSignal = null;
    }

    _emitSearchSignal() {
        window.clearTimeout(this._signalTimer);
        const signal = this._pendingSignal;
        this._pendingSignal = null;

        // Je Begriff nur einmal pro geöffnetem Fenster (Filter/Sortierung ändern den Begriff nicht)
        if (!signal || this._signaledTerms?.has(signal.term)) {
            return;
        }
        this._signaledTerms?.add(signal.term);

        // Zwischenstände beim Tippen („ra“, „rah“, „rahmen“) zählen nicht: Die Suche wird zurückgehalten
        // und durch den weitergetippten Begriff ersetzt. Gemeldet wird erst, wenn etwas anderes gesucht,
        // ein Ergebnis angeklickt oder das Fenster geschlossen wird.
        const held = this._heldSearch;
        if (held && NovaSearchPlugin.isTypingStep(held.term, signal.term)) {
            this._heldSearch = { ...signal, clicked: held.clicked };
            return;
        }

        this._releaseHeldSearch();
        // Andere Suche nach einer ohne Klick – vielleicht eine Korrektur („vergser“ → „vergaser“)
        this._refineFrom = held && !held.clicked ? held.term : null;
        this._heldSearch = { ...signal, clicked: false };
    }

    /** Zurückgehaltene Suche melden */
    _releaseHeldSearch() {
        const held = this._heldSearch;
        this._heldSearch = null;
        if (!held) {
            return;
        }

        if (held.total > 0) {
            this._rememberSearch(held.term);
        }
        document.dispatchEvent(new CustomEvent('nova:search', { detail: { term: held.term, total: held.total } }));
        this._tracker.search(held.term, held.total, held.rewritten);
    }

    /** Vor einem Klick: Suche melden und ggf. die Korrektur des vorherigen Begriffs */
    _beforeResultClick() {
        this._emitSearchSignal();
        const held = this._heldSearch;
        if (!held) {
            return;
        }

        held.clicked = true;
        const from = this._refineFrom;
        this._refineFrom = null;
        this._releaseHeldSearch();
        if (from && from !== held.term) {
            this._tracker.refine(from, held.term);
        }
    }

    /** true, wenn b durch Weiter- oder Zurücktippen aus a entsteht (gleicher Anfang) */
    static isTypingStep(a, b) {
        const x = (a || '').trim().toLowerCase();
        const y = (b || '').trim().toLowerCase();

        return x !== '' && y !== '' && (x.startsWith(y) || y.startsWith(x));
    }

    /** Klick auf eine Produktkarte im Suchfenster */
    _onCardClick(event) {
        const card = event.target.closest('.nova-search-card');
        const info = this._productInfo(card);
        const term = this._state.term;
        if (!info || term.length < this.options.minChars) {
            return;
        }

        this._beforeResultClick();
        this._tracker.click(term, info.id);
    }

    /** Suchergebnisseite und „In den Warenkorb“ – überall im Shop */
    _registerPageTracking() {
        if (!this.options.learning) {
            return;
        }

        if (window.activeRoute === 'frontend.search.page') {
            const term = (new URLSearchParams(window.location.search).get('search') || '').trim();
            document.addEventListener('click', (event) => {
                const link = event.target.closest('.product-box a[href]');
                const info = link && !link.closest('form') ? this._productInfo(link) : null;
                if (info && term) {
                    this._tracker.click(term, info.id);
                }
            }, true);
        }

        document.addEventListener('submit', (event) => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement) || !form.matches('[data-add-to-cart]')) {
                return;
            }

            // Direkt aus dem Suchfenster: zählt auch als Klick auf das Ergebnis
            const fromLayer = this._body && this._body.contains(form) && this._state.term.length >= this.options.minChars;
            form.querySelectorAll('input[name^="lineItems["][name$="][id]"]').forEach((input) => {
                if (fromLayer) {
                    this._beforeResultClick();
                    this._tracker.click(this._state.term, input.value);
                }
                const term = this._tracker.termFor(input.value);
                if (term) {
                    this._tracker.cart(term, input.value);
                }
            });
        }, true);
    }

    /** „Teil anfragen“ aus dem Suchfenster senden – ohne das Fenster zu verlassen */
    async _sendRequest(form) {
        const status = form.querySelector('[data-nova-search-request-status]');
        const button = form.querySelector('button[type="submit"]');
        const value = (name) => ((form.elements[name] && form.elements[name].value) || '').trim();
        const show = (text, type) => {
            status.textContent = text;
            status.classList.toggle('is-error', type === 'error');
            status.classList.toggle('is-success', type === 'success');
        };

        if (value('email') === '' && value('phone') === '') {
            show(form.querySelector('.nova-search-request-note').textContent, 'error');
            form.elements.email.focus();
            return;
        }
        const privacy = form.elements.acceptedDataProtection;
        if (privacy && !privacy.checked) {
            privacy.focus();
            privacy.reportValidity();
            return;
        }

        const label = button.textContent;
        button.disabled = true;
        button.textContent = '…';
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            const result = await response.json().catch(() => ({}));
            if (response.ok && result.success) {
                const details = form.closest('.nova-search-request');
                const done = document.createElement('p');
                done.className = 'nova-search-request-status is-success';
                done.setAttribute('role', 'status');
                done.textContent = result.message || '';
                (details || form).replaceWith(done);
                return;
            }
            show(result.message || label, 'error');
        } catch (e) {
            show(form.dataset.errorText || '⚠', 'error');
        } finally {
            if (button.isConnected) {
                button.disabled = false;
                button.textContent = label;
            }
        }
    }

    _productInfo(element) {
        const holder = element && element.closest('[data-product-information]');
        if (!holder) {
            return null;
        }
        try {
            const info = JSON.parse(holder.getAttribute('data-product-information'));
            return info && info.id ? info : null;
        } catch (e) {
            return null;
        }
    }

    /* ---------- Zuletzt gesucht ---------- */

    _recentSearchesAllowed() {
        return this.options.recentSearches && (!this.options.recentlyViewedConsent || hasCookie('nova-recently-viewed'));
    }

    _recentSearches() {
        if (!this._recentSearchesAllowed()) {
            return [];
        }
        try {
            const terms = JSON.parse(window.localStorage.getItem(this.options.recentSearchesKey) || '[]');
            return Array.isArray(terms) ? terms.filter((t) => typeof t === 'string' && t.length > 0 && t.length <= 100) : [];
        } catch (e) {
            return [];
        }
    }

    _saveRecentSearches(terms) {
        try {
            if (terms.length === 0) {
                window.localStorage.removeItem(this.options.recentSearchesKey);
            } else {
                window.localStorage.setItem(this.options.recentSearchesKey, JSON.stringify(terms.slice(0, this.options.recentSearchesMax)));
            }
        } catch (e) { /* Speicher gesperrt */ }
    }

    _rememberSearch(term) {
        const value = (term || '').trim();
        if (!this._recentSearchesAllowed() || value.length < this.options.minChars) {
            return;
        }
        const lower = value.toLowerCase();
        this._saveRecentSearches([value, ...this._recentSearches().filter((t) => t.toLowerCase() !== lower)]);
    }

    _removeRecentSearch(term) {
        this._saveRecentSearches(this._recentSearches().filter((t) => t !== term));
    }

    /** Chips „Zuletzt gesucht“ oben in der Startansicht – nur im Browser gespeichert */
    _renderRecentSearches() {
        const start = this._body.querySelector('.nova-search-start');
        if (!start) {
            return;
        }
        start.querySelector('.nova-search-history')?.remove();

        const terms = this._recentSearches();
        if (terms.length === 0) {
            return;
        }

        const labels = this.options.labels || {};
        const section = document.createElement('section');
        section.className = 'nova-search-history';

        const title = document.createElement('span');
        title.className = 'nova-search-history-title';
        title.textContent = labels.recentSearches || 'Zuletzt gesucht';
        section.append(title);

        terms.forEach((term) => {
            const chip = document.createElement('span');
            chip.className = 'nova-search-history-chip';

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'nova-search-history-term nova-search-focusable';
            button.setAttribute('data-nova-search-term', term);
            button.textContent = term;

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'nova-search-history-remove';
            remove.setAttribute('data-nova-search-history-remove', term);
            remove.setAttribute('aria-label', `${labels.recentSearchesRemove || 'Entfernen'}: ${term}`);
            remove.textContent = '×';

            chip.append(button, remove);
            section.append(chip);
        });

        const clear = document.createElement('button');
        clear.type = 'button';
        clear.className = 'nova-search-history-clear';
        clear.setAttribute('data-nova-search-history-clear', '');
        clear.textContent = labels.recentSearchesClear || 'Alles löschen';
        section.append(clear);

        start.prepend(section);
    }

    /* ---------- Zuletzt angesehen ---------- */

    _recentAllowed() {
        return this.options.recentlyViewed && (!this.options.recentlyViewedConsent || hasCookie('nova-recently-viewed'));
    }

    _recentIds() {
        if (!this._recentAllowed()) {
            return [];
        }
        try {
            const ids = JSON.parse(window.localStorage.getItem(this.options.recentStorageKey) || '[]');
            return Array.isArray(ids) ? ids.filter((id) => /^[0-9a-f]{32}$/.test(id)) : [];
        } catch (e) {
            return [];
        }
    }

    /** Auf der Produktseite das Produkt vorne in die Liste setzen (gleiches Format wie das Nova Theme) */
    _rememberProductView() {
        if (window.activeRoute !== 'frontend.detail.page' || !this._recentAllowed()) {
            return;
        }

        let productId = null;
        try {
            productId = JSON.parse(window.activeRouteParameters || '{}').productId || null;
        } catch (e) {
            productId = null;
        }
        if (!productId || !/^[0-9a-f]{32}$/.test(productId)) {
            return;
        }

        const ids = [productId, ...this._recentIds().filter((id) => id !== productId)].slice(0, this.options.recentMax);
        try {
            window.localStorage.setItem(this.options.recentStorageKey, JSON.stringify(ids));
        } catch (e) {
            // Speicher voll oder gesperrt – dann eben ohne Liste
        }
    }

    _abort() {
        if (this._request) {
            this._request.abort();
            this._request = null;
        }
    }

    /** Suchwörter fett hervorheben (ohne innerHTML – kein XSS-Risiko) */
    _highlight(root) {
        const words = this._state.term
            .split(/\s+/)
            .filter((word) => word.length >= 2)
            .map((word) => word.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
        if (words.length === 0) {
            return;
        }

        const pattern = new RegExp(`(${words.join('|')})`, 'gi');
        root.querySelectorAll('.nova-search-hl:not([data-hl])').forEach((element) => {
            element.setAttribute('data-hl', '');
            const parts = element.textContent.split(pattern);
            if (parts.length === 1) {
                return;
            }
            element.textContent = '';
            parts.forEach((part, index) => {
                if (index % 2 === 1) {
                    const mark = document.createElement('mark');
                    mark.textContent = part;
                    element.append(mark);
                } else if (part) {
                    element.append(part);
                }
            });
        });
    }
}
