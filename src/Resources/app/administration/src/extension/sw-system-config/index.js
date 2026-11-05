import template from './sw-system-config.html.twig';

/**
 * Plugin-Konfiguration (Erweiterungen → Meine Erweiterungen → Nova Suche → Konfigurieren):
 * Hinweis oben mit Link in den übersichtlicheren Reiter „Einstellungen“ des Nova-Suche-Moduls.
 * Nur für die eigene Domain und nicht im Modul selbst (dort setzt nova-search-system-config
 * `novaSearchEmbedded`).
 */
Shopware.Component.override('sw-system-config', {
    template,

    computed: {
        novaSearchEmbedded() {
            return false;
        },

        novaSearchShowModuleHint() {
            return this.domain === 'NovaSearch.config' && !this.novaSearchEmbedded;
        },
    },
});
