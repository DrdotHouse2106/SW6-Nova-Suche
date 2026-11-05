/**
 * Reiter oben auf allen Seiten von Erweiterungen → Nova Suche.
 */
Shopware.Component.register('nova-search-tabs', {
    template: `
        <nav class="nova-search-tabs">
            <router-link
                v-for="tab in tabs"
                :key="tab.route"
                :to="{ name: tab.route }"
                class="nova-search-tabs__tab"
                :class="{ 'is--active': $route.name === tab.route }"
            >{{ $tc(tab.label) }}</router-link>
        </nav>
    `,

    computed: {
        tabs() {
            return [
                { route: 'nova.search.index', label: 'nova-search.tabs.analytics' },
                { route: 'nova.search.synonyms', label: 'nova-search.tabs.synonyms' },
                { route: 'nova.search.redirects', label: 'nova-search.tabs.redirects' },
                { route: 'nova.search.hints', label: 'nova-search.tabs.hints' },
                { route: 'nova.search.settings', label: 'nova-search.tabs.settings' },
            ];
        },
    },
});
