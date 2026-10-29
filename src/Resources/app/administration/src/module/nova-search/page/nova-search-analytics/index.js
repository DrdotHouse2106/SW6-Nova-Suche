import template from './nova-search-analytics.html.twig';
import './nova-search-analytics.scss';

const { Component, Mixin } = Shopware;

Component.register('nova-search-analytics', {
    template,

    inject: ['loginService', 'acl'],

    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            isLoading: true,
            salesChannelId: null,
            days: 30,
            data: null,
            tab: 'terms',
            importModal: null,
            feedbackToDelete: null,
            isDeleting: false,
        };
    },

    metaInfo() {
        return { title: this.$createTitle() };
    },

    computed: {
        periodOptions() {
            return [7, 30, 90, 365].map((days) => ({
                value: days,
                label: this.$t('nova-search.analytics.period', { days }),
            }));
        },

        kpis() {
            if (!this.data) {
                return [];
            }
            const current = this.data.current;
            const previous = this.data.previous;

            return [
                { key: 'searches', value: current.searches, previous: previous.searches, format: 'number', good: 'up' },
                { key: 'zeroRate', value: current.zeroRate, previous: previous.zeroRate, format: 'percent', good: 'down' },
                { key: 'clickRate', value: current.clickRate, previous: previous.clickRate, format: 'percent', good: 'up' },
                { key: 'carts', value: current.carts, previous: previous.carts, format: 'number', good: 'up' },
                { key: 'orders', value: current.orders, previous: previous.orders, format: 'number', good: 'up' },
                { key: 'terms', value: current.terms, previous: previous.terms, format: 'number', good: 'up' },
            ].map((kpi) => ({ ...kpi, change: this.change(kpi) }));
        },

        seriesMax() {
            return Math.max(1, ...(this.data?.series || []).map((day) => day.searches));
        },

        feedback() {
            return this.data?.feedback || { up: 0, down: 0, texts: 0, terms: [], entries: [] };
        },

        canEdit() {
            return !this.acl || this.acl.can('system_config:update');
        },

        exportType() {
            return { products: 'products', zeroResults: 'rewrites', feedback: 'feedback' }[this.tab] || 'terms';
        },

        claudePercent() {
            const claude = this.data?.claude;
            return claude && claude.limitUsd > 0 ? Math.min(100, Math.round(claude.spentUsd / claude.limitUsd * 100)) : 0;
        },
    },

    watch: {
        salesChannelId() {
            this.load();
        },
        days() {
            this.load();
        },
    },

    created() {
        this.load();
    },

    methods: {
        headers() {
            return {
                Accept: 'application/json',
                Authorization: `Bearer ${this.loginService.getToken()}`,
            };
        },

        params() {
            const params = { days: this.days };
            if (this.salesChannelId) {
                params.salesChannelId = this.salesChannelId;
            }
            return params;
        },

        async load() {
            this.isLoading = true;
            try {
                const httpClient = Shopware.Application.getContainer('init').httpClient;
                const response = await httpClient.get('_action/nova-search/analytics', {
                    params: this.params(),
                    headers: this.headers(),
                });
                this.data = response.data;
            } catch (error) {
                this.createNotificationError({
                    message: error?.response?.data?.message || this.$tc('nova-search.analytics.loadError'),
                });
            } finally {
                this.isLoading = false;
            }
        },

        async download(type) {
            try {
                const httpClient = Shopware.Application.getContainer('init').httpClient;
                const response = await httpClient.get('_action/nova-search/export', {
                    params: { ...this.params(), type },
                    headers: { ...this.headers(), Accept: 'text/csv' },
                    responseType: 'blob',
                });
                const url = window.URL.createObjectURL(response.data);
                const link = document.createElement('a');
                link.href = url;
                link.download = `nova-suche-${type}-${new Date().toISOString().slice(0, 10)}.csv`;
                document.body.append(link);
                link.click();
                link.remove();
                window.URL.revokeObjectURL(url);
            } catch {
                this.createNotificationError({ message: this.$tc('nova-search.analytics.exportError') });
            }
        },

        change(kpi) {
            if (!kpi.previous) {
                return null;
            }
            if (kpi.format === 'percent') {
                return Math.round((kpi.value - kpi.previous) * 10) / 10;
            }
            return Math.round((kpi.value - kpi.previous) / kpi.previous * 1000) / 10;
        },

        changeClass(kpi) {
            if (kpi.change === null || kpi.change === 0) {
                return 'is--neutral';
            }
            const better = kpi.good === 'up' ? kpi.change > 0 : kpi.change < 0;
            return better ? 'is--good' : 'is--bad';
        },

        formatValue(value, format) {
            const number = Number(value || 0).toLocaleString(Shopware.State?.get?.('session')?.currentLocale || undefined, {
                maximumFractionDigits: 1,
            });
            return format === 'percent' ? `${number} %` : number;
        },

        formatChange(kpi) {
            if (kpi.change === null) {
                return this.$tc('nova-search.analytics.noComparison');
            }
            const sign = kpi.change > 0 ? '+' : '';
            const unit = kpi.format === 'percent' ? ' %-Pkt.' : ' %';
            return `${sign}${kpi.change.toLocaleString()}${unit}`;
        },

        barHeight(value) {
            return `${Math.max(2, Math.round(value / this.seriesMax * 100))}%`;
        },

        rewriteLabel(row) {
            if (row.rewriteStatus === 'done' && row.rewrite) {
                return this.$t('nova-search.analytics.rewriteDone', { rewrite: row.rewrite });
            }
            return row.rewriteStatus ? this.$tc(`nova-search.analytics.rewriteStatus.${row.rewriteStatus}`) : '–';
        },

        openImport() {
            this.importModal = { salesChannelId: this.salesChannelId, files: [], results: [], isRunning: false, changed: false };
        },

        closeImport() {
            const changed = this.importModal?.changed;
            this.importModal = null;
            if (changed) {
                this.load();
            }
        },

        async onImportFiles(event) {
            this.importModal.files = await Promise.all(Array.from(event.target.files || []).map(async (file) => ({
                name: file.name,
                text: await file.text(),
            })));
            this.importModal.results = [];
        },

        async runImport() {
            const modal = this.importModal;
            modal.isRunning = true;
            try {
                const httpClient = Shopware.Application.getContainer('init').httpClient;
                const response = await httpClient.post('_action/nova-search/import-stats', {
                    salesChannelId: modal.salesChannelId,
                    files: modal.files,
                }, { headers: this.headers() });
                modal.results = response.data.results;
                modal.changed = true;
                this.createNotificationSuccess({ message: this.$tc('nova-search.import.done') });
            } catch (error) {
                modal.results = error?.response?.data?.results || [];
                modal.changed = modal.results.length > 0;
                this.createNotificationError({ message: error?.response?.data?.message || this.$tc('nova-search.import.error') });
            } finally {
                modal.isRunning = false;
            }
        },

        /** Gespeicherte Umschreibung abschalten – sie wird danach weder angewendet noch neu gelernt */
        async disableRewrite(row) {
            try {
                const httpClient = Shopware.Application.getContainer('init').httpClient;
                await httpClient.post('_action/nova-search/rewrite/disable', {
                    salesChannelId: row.salesChannelId,
                    term: row.term,
                }, { headers: this.headers() });
                row.status = 'disabled';
                this.createNotificationSuccess({ message: this.$tc('nova-search.analytics.rewrites.disabled') });
            } catch (error) {
                this.createNotificationError({
                    message: error?.response?.data?.message || this.$tc('nova-search.analytics.rewrites.error'),
                });
            }
        },

        /** Rückmeldung löschen (z. B. wenn der Text personenbezogene Daten enthält) */
        async deleteFeedback() {
            const entry = this.feedbackToDelete;
            if (!entry) {
                return;
            }
            this.isDeleting = true;
            try {
                const httpClient = Shopware.Application.getContainer('init').httpClient;
                await httpClient.post('_action/nova-search/feedback/delete', { id: entry.id }, { headers: this.headers() });
                this.data.feedback.entries = this.data.feedback.entries.filter((row) => row.id !== entry.id);
                this.createNotificationSuccess({ message: this.$tc('nova-search.analytics.feedback.deleted') });
                this.load();
            } catch (error) {
                this.createNotificationError({
                    message: error?.response?.data?.message || this.$tc('nova-search.analytics.feedback.deleteError'),
                });
            } finally {
                this.isDeleting = false;
                this.feedbackToDelete = null;
            }
        },

        formatDate(value) {
            if (!value) {
                return '–';
            }
            const date = new Date(value.replace(' ', 'T'));
            return Number.isNaN(date.getTime()) ? value : date.toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' });
        },

        /** „Beheben“: Synonym- bzw. Weiterleitungsliste mit vorbelegtem Begriff öffnen */
        fix(list, term) {
            this.$router.push({ name: `nova.search.${list}`, query: { new: term } });
        },

        openSettings() {
            this.$router.push({ name: 'sw.extension.config', params: { namespace: 'NovaSearch' } });
        },
    },
});
