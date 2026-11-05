import template from './nova-search-settings.html.twig';
import './nova-search-settings.scss';

const { Component, Mixin } = Shopware;

/**
 * Unterreiter der Einstellungen. `cards` = Namen der Karten aus config.xml (<name>), die der
 * Unterreiter zeigt – in dieser Reihenfolge.
 */
export const SECTIONS = [
    { key: 'layer', cards: ['instantSearch', 'startView', 'results'] },
    { key: 'learning', cards: ['learning', 'feedback', 'claude'] },
    { key: 'matching', cards: ['wordParts', 'numberSearch', 'excluded'] },
    { key: 'alsoBought', cards: ['alsoBought'] },
];

/**
 * Erweiterungen → Nova Suche → Einstellungen: dieselben Werte wie die Plugin-Konfiguration
 * (Domain NovaSearch.config), nach Themen auf Unterreiter verteilt, mit Verkaufskanal-Umschalter und
 * Vererbung. Der Unterreiter steht in der Adresse: /nova/search/settings/<unterreiter>.
 */
Component.register('nova-search-settings', {
    template,

    inject: ['acl'],

    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            isSaving: false,
        };
    },

    metaInfo() {
        return { title: this.$createTitle() };
    },

    computed: {
        sections() {
            return SECTIONS;
        },

        section() {
            const wanted = this.$route.params.section;
            return SECTIONS.some((s) => s.key === wanted) ? wanted : SECTIONS[0].key;
        },

        currentCards() {
            return SECTIONS.find((s) => s.key === this.section).cards;
        },

        canEdit() {
            return !this.acl || this.acl.can('system_config:update');
        },
    },

    watch: {
        '$route.params.section': {
            handler() {
                this.normalizeRoute();
            },
            immediate: true,
        },
    },

    methods: {
        sectionRoute(section) {
            return { name: 'nova.search.settings', params: { section } };
        },

        /** Adresse ohne/mit unbekanntem Unterreiter auf den gezeigten Unterreiter umschreiben. */
        normalizeRoute() {
            if (this.$route.params.section !== this.section) {
                this.$router.replace(this.sectionRoute(this.section));
            }
        },

        onSalesChannelChanged(salesChannelId) {
            if (this.$refs.systemConfig) {
                this.$refs.systemConfig.novaSearchSetSalesChannel(salesChannelId);
            }
        },

        async save() {
            if (!this.$refs.systemConfig) {
                return;
            }
            this.isSaving = true;
            try {
                await this.$refs.systemConfig.saveAll();
                this.createNotificationSuccess({ message: this.$tc('nova-search.settings.saved') });
            } catch (error) {
                const errors = error?.response?.data?.errors;
                const detail = Array.isArray(errors) ? errors.map((e) => e.detail || '').join(' ') : '';
                this.createNotificationError({ message: `${this.$tc('nova-search.settings.saveError')} ${detail}`.trim() });
            } finally {
                this.isSaving = false;
            }
        },
    },
});
