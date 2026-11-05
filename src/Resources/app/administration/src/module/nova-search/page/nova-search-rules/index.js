import template from './nova-search-rules.html.twig';
import './nova-search-rules.scss';

const { Component, Mixin } = Shopware;

/**
 * Liste und Bearbeitung von Synonymen, Weiterleitungen bzw. Hinweisen (Typ aus der Route).
 */
Component.register('nova-search-rules', {
    template,

    inject: ['loginService', 'repositoryFactory', 'cacheApiService'],

    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            isLoading: true,
            items: [],
            search: '',
            salesChannels: {},
            editItem: null,
            isSaving: false,
            deleteItem: null,
            changed: false,
        };
    },

    metaInfo() {
        return { title: this.$createTitle() };
    },

    computed: {
        type() {
            return ['redirect', 'hint'].includes(this.$route.meta.ruleType) ? this.$route.meta.ruleType : 'synonym';
        },

        isSynonym() {
            return this.type === 'synonym';
        },

        isHint() {
            return this.type === 'hint';
        },

        prefix() {
            return { synonym: 'nova-search.synonyms', redirect: 'nova-search.redirects', hint: 'nova-search.hints' }[this.type];
        },

        /** Wie im Backend: getrimmt, klein geschrieben, ohne Doppelte (Synonym-Begriffe bzw. Hinweis-Wörter) */
        editTerms() {
            const terms = (this.editItem?.termsText || '').split(',').map((term) => term.trim().toLowerCase()).filter(Boolean);
            return [...new Set(terms)];
        },

        canSave() {
            if (!this.editItem || this.isSaving) {
                return false;
            }
            if (this.isHint) {
                return this.editTerms.length >= 1 && this.editItem.text.trim() !== '';
            }
            return this.isSynonym
                ? this.editTerms.length >= 2
                : this.editItem.term.trim() !== '' && this.editItem.target.trim() !== '';
        },
    },

    watch: {
        type() {
            this.search = '';
            this.load();
        },
    },

    created() {
        this.loadSalesChannels();
        this.load().then(() => this.openFromQuery());
    },

    methods: {
        http() {
            return Shopware.Application.getContainer('init').httpClient;
        },

        headers() {
            return { Accept: 'application/json', Authorization: `Bearer ${this.loginService.getToken()}` };
        },

        notifyError(error, fallbackKey = 'nova-search.rules.saveError') {
            this.createNotificationError({ message: error?.response?.data?.message || this.$tc(fallbackKey) });
        },

        async load() {
            this.isLoading = true;
            try {
                const response = await this.http().get(`_action/nova-search/rules/${this.type}`, {
                    params: { search: this.search },
                    headers: this.headers(),
                });
                this.items = response.data.items;
            } catch (error) {
                this.notifyError(error, 'nova-search.rules.loadError');
            } finally {
                this.isLoading = false;
            }
        },

        async loadSalesChannels() {
            try {
                const result = await this.repositoryFactory.create('sales_channel').search(new Shopware.Data.Criteria(1, 100));
                const names = {};
                result.forEach((channel) => { names[channel.id] = channel.translated?.name || channel.name; });
                this.salesChannels = names;
            } catch {
                this.salesChannels = {};
            }
        },

        salesChannelName(id) {
            return id ? (this.salesChannels[id] || id) : this.$tc('nova-search.rules.allSalesChannels');
        },

        onSearch() {
            window.clearTimeout(this.searchTimer);
            this.searchTimer = window.setTimeout(() => this.load(), 300);
        },

        /** Aus der Auswertung („Ohne Treffer“ → Synonym/Weiterleitung) mit vorbelegtem Begriff */
        openFromQuery() {
            const term = this.$route.query?.new;
            if (typeof term === 'string' && term !== '') {
                this.openEdit(null, term);
            }
        },

        openEdit(item = null, presetTerm = '') {
            this.editItem = item
                ? {
                    id: item.id,
                    salesChannelId: item.salesChannelId,
                    active: item.active,
                    termsText: (item.terms || item.words || []).join(', '),
                    term: item.term || '',
                    target: item.target || '',
                    text: item.text || '',
                    url: item.url || '',
                }
                : {
                    id: null,
                    salesChannelId: null,
                    active: true,
                    termsText: presetTerm ? `${presetTerm}, ` : '',
                    term: presetTerm,
                    target: '',
                    text: '',
                    url: '',
                };
        },

        async save() {
            if (!this.canSave) {
                return;
            }
            this.isSaving = true;
            const body = {
                id: this.editItem.id,
                salesChannelId: this.editItem.salesChannelId || null,
                active: this.editItem.active,
            };
            if (this.isHint) {
                body.words = this.editTerms;
                body.text = this.editItem.text;
                body.url = this.editItem.url;
            } else if (this.isSynonym) {
                body.terms = this.editTerms;
            } else {
                body.term = this.editItem.term;
                body.target = this.editItem.target;
            }

            try {
                await this.http().post(`_action/nova-search/rules/${this.type}`, body, { headers: this.headers() });
                this.editItem = null;
                this.changed = true;
                this.createNotificationSuccess({ message: this.$tc(`${this.prefix}.saved`) });
                await this.load();
            } catch (error) {
                this.notifyError(error);
            } finally {
                this.isSaving = false;
            }
        },

        async toggleActive(item) {
            const body = { id: item.id, salesChannelId: item.salesChannelId, active: !item.active };
            if (this.isHint) {
                body.words = item.words;
                body.text = item.text;
                body.url = item.url;
            } else if (this.isSynonym) {
                body.terms = item.terms;
            } else {
                body.term = item.term;
                body.target = item.target;
            }
            try {
                await this.http().post(`_action/nova-search/rules/${this.type}`, body, { headers: this.headers() });
                item.active = !item.active;
                this.changed = true;
            } catch (error) {
                this.notifyError(error);
            }
        },

        async confirmDelete() {
            const item = this.deleteItem;
            this.deleteItem = null;
            try {
                await this.http().delete(`_action/nova-search/rules/${this.type}/${item.id}`, { headers: this.headers() });
                this.changed = true;
                await this.load();
            } catch (error) {
                this.notifyError(error);
            }
        },

        pickImport() {
            this.$refs.importFile.click();
        },

        async onImportFile(event) {
            const file = event.target.files?.[0];
            event.target.value = '';
            if (!file) {
                return;
            }
            try {
                const text = await file.text();
                const response = await this.http().post(`_action/nova-search/rules/${this.type}/import`, { text }, { headers: this.headers() });
                this.changed = true;
                this.createNotificationSuccess({ message: this.$t('nova-search.rules.imported', response.data) });
                await this.load();
            } catch (error) {
                this.notifyError(error);
            }
        },

        async exportCsv() {
            try {
                const response = await this.http().get(`_action/nova-search/rules/${this.type}/export`, {
                    headers: { ...this.headers(), Accept: 'text/csv' },
                    responseType: 'blob',
                });
                const url = window.URL.createObjectURL(response.data);
                const link = document.createElement('a');
                link.href = url;
                link.download = `nova-suche-${this.isSynonym ? 'synonyme' : 'weiterleitungen'}.csv`;
                document.body.append(link);
                link.click();
                link.remove();
                window.URL.revokeObjectURL(url);
            } catch (error) {
                this.notifyError(error, 'nova-search.analytics.exportError');
            }
        },

        async clearCache() {
            try {
                await this.cacheApiService.clear();
                this.changed = false;
                this.createNotificationSuccess({ message: this.$tc('nova-search.rules.cacheCleared') });
            } catch (error) {
                this.notifyError(error);
            }
        },

        openSettings() {
            this.$router.push({ name: 'nova.search.settings' });
        },
    },
});
