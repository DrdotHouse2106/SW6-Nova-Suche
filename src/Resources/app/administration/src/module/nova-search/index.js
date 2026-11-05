import './component/nova-search-tabs';
import './component/nova-search-system-config';
import './page/nova-search-analytics';
import './page/nova-search-rules';
import './page/nova-search-settings';
import './nova-search.scss';

/**
 * Erweiterungen → Nova Suche: Auswertung, Synonyme, Weiterleitungen, Hinweise und Einstellungen (gleiche
 * Werte wie die Plugin-Konfiguration, nach Themen auf Unterreiter verteilt).
 */
Shopware.Module.register('nova-search', {
    type: 'plugin',
    name: 'nova-search',
    title: 'nova-search.general.title',
    description: 'nova-search.general.description',
    color: '#189eff',
    icon: 'regular-search',

    routes: {
        index: {
            component: 'nova-search-analytics',
            path: 'index',
        },
        synonyms: {
            component: 'nova-search-rules',
            path: 'synonyms',
            meta: { ruleType: 'synonym' },
        },
        redirects: {
            component: 'nova-search-rules',
            path: 'redirects',
            meta: { ruleType: 'redirect' },
        },
        hints: {
            component: 'nova-search-rules',
            path: 'hints',
            meta: { ruleType: 'hint' },
        },
        settings: {
            component: 'nova-search-settings',
            path: 'settings/:section?',
        },
    },

    navigation: [{
        id: 'nova-search',
        label: 'nova-search.general.title',
        path: 'nova.search.index',
        parent: 'sw-extension',
        position: 100,
    }],
});
