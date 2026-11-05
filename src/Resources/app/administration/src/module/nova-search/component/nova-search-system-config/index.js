/**
 * Shopwares sw-system-config (Plugin-Konfiguration) mit Kartenfilter: lädt die komplette config.xml
 * der Domain NovaSearch.config, zeigt aber nur die Karten, deren <name> in `cards` steht.
 *
 * Eine Instanz für alle Unterreiter: Beim Wechsel ändert sich nur `cards`, die geladenen und geänderten
 * Werte (je Verkaufskanal) bleiben erhalten; „Speichern“ speichert alle Änderungen aller Unterreiter.
 * Gespeichert wird wie in der Plugin-Konfiguration in system_config – gleiche Schlüssel, keine Migration.
 */
Shopware.Component.extend('nova-search-system-config', 'sw-system-config', {
    props: {
        cards: {
            type: Array,
            required: false,
            default: () => [],
        },
    },

    data() {
        return {
            novaSearchAllCards: [],
        };
    },

    computed: {
        /** Blendet den Hinweis „Übersichtlicher im Modul“ aus (siehe Override sw-system-config). */
        novaSearchEmbedded() {
            return true;
        },
    },

    watch: {
        cards() {
            this.novaSearchApplyCards();
        },
    },

    methods: {
        async readConfig() {
            await this.$super('readConfig');
            this.novaSearchAllCards = Array.isArray(this.config) ? this.config : [];
            this.novaSearchApplyCards();
        },

        novaSearchApplyCards() {
            const wanted = this.cards || [];
            this.config = this.novaSearchAllCards
                .filter((card) => wanted.includes(card.name))
                .sort((a, b) => wanted.indexOf(a.name) - wanted.indexOf(b.name));
        },

        /** Cache-relevante Felder aus allen Karten, nicht nur den gerade sichtbaren. */
        getCacheRelevantFieldNames() {
            const fieldNames = new Set();
            this.novaSearchAllCards.forEach((card) => {
                (card.elements || []).forEach((element) => {
                    if (element.config?.cacheRelevant === true) {
                        fieldNames.add(element.name);
                    }
                });
            });

            return fieldNames;
        },

        /** Verkaufskanal von außen setzen (Umschalter oben im Reiter). */
        novaSearchSetSalesChannel(salesChannelId) {
            this.onSalesChannelChanged(salesChannelId || null);
        },

        /** Gibt es ungespeicherte Änderungen (alle Unterreiter, alle Verkaufskanäle)? */
        novaSearchHasChanges() {
            return this.hasConfigChanges(this.getChangedConfigData());
        },
    },
});
