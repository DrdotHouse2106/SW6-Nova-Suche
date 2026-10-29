import initSearchFeedback from './plugin/nova-search-feedback';

/*
 * Nova Suche – Storefront-Einstiegspunkt
 * Das Plugin wird erst geladen, wenn das Suchformular der Sofortsuche existiert.
 */
window.PluginManager.register(
    'NovaSearch',
    () => import('./plugin/nova-search.plugin'),
    '[data-nova-search]'
);

/*
 * „Gefällt Ihnen das Suchergebnis?“ – auf der Suchergebnisseite auch ohne Sofortsuche
 */
initSearchFeedback();

/*
 * „Teil anfragen“: Kontaktformular mit Betreff und Suchbegriff vorausfüllen
 * (Link aus dem Suchfenster mit ?nova-anfrage=…&nova-betreff=…). Bereits ausgefüllte Felder bleiben unverändert.
 */
(() => {
    const params = new URLSearchParams(window.location.search);
    const message = params.get('nova-anfrage');
    if (!message) {
        return;
    }

    const fill = () => {
        const comment = document.querySelector('form textarea[name="comment"]');
        if (!comment) {
            return;
        }
        if (comment.value.trim() === '') {
            comment.value = message.slice(0, 500);
        }
        const subject = comment.form.querySelector('input[name="subject"]');
        const subjectText = params.get('nova-betreff');
        if (subject && subjectText && subject.value.trim() === '') {
            subject.value = subjectText.slice(0, 100);
        }
        comment.form.scrollIntoView({ block: 'start' });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fill);
    } else {
        fill();
    }
})();
