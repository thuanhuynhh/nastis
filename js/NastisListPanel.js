(function () {
    const localeTools = typeof pkp !== 'undefined'
        && pkp.modules
        && typeof pkp.modules.useLocalize === 'function'
        ? pkp.modules.useLocalize()
        : {};

    function translate(key, params) {
        let text = (pkp && pkp.localeKeys && pkp.localeKeys[key]) ? pkp.localeKeys[key] : key;
        if (params) {
            Object.keys(params).forEach((param) => {
                text = text.replace(new RegExp('\\{\\$' + param + '\\}', 'g'), params[param]);
            });
        }
        return text;
    }

    function localizeValue(value, preferredLocale) {
        if (localeTools.localize) {
            return localeTools.localize(value, preferredLocale);
        }
        if (typeof value === 'string') {
            return value;
        }
        if (!value || typeof value !== 'object') {
            return '';
        }
        if (preferredLocale && value[preferredLocale]) {
            return value[preferredLocale];
        }
        const locale = (pkp && pkp.currentLocale) || (pkp && pkp.locale) || Object.keys(value)[0];
        return value[locale] || value[Object.keys(value)[0]] || '';
    }

    const component = {
        props: {
            count: {
                type: Number,
                default: 30,
            },
            filters: {
                type: Array,
                default: () => [],
            },
            id: {
                type: String,
                required: true,
            },
            items: {
                type: Array,
                default: () => [],
            },
            itemsMax: {
                type: Number,
                default: 0,
            },
            searchUrl: {
                type: String,
                required: true,
            },
            statusApiUrl: {
                type: String,
                required: true,
            },
            title: {
                type: String,
                default: '',
            },
        },
        emits: ['set'],
        data() {
            return {
                activeFilters: {
                    nastisStatus: [],
                },
                expandedIds: [],
                isLoading: false,
                itemsState: this.items,
                itemsStateMax: this.itemsMax,
                searchPhrase: '',
                searchTimeout: null,
                selectedIds: [],
            };
        },
        computed: {
            hasSelections() {
                return this.selectedIds.length > 0;
            },
        },
        mounted() {
            this.emitState();
        },
        methods: {
            addFilter(param, value) {
                if (param !== 'nastisStatus') {
                    return;
                }
                if (!this.activeFilters.nastisStatus.includes(value)) {
                    this.activeFilters.nastisStatus = [...this.activeFilters.nastisStatus, value];
                    this.loadItems();
                }
            },
            emitState() {
                this.$emit('set', this.id, {
                    items: this.itemsState,
                    itemsMax: this.itemsStateMax,
                });
            },
            getAuthors(item) {
                const publication = this.getCurrentPublication(item);
                return publication && publication.authorsStringShort ? publication.authorsStringShort : '';
            },
            getBadgeClass(item) {
                const status = this.getStatusValue(item);
                return {
                    'pkpBadge--isPrimary': status === 'accepted-by-ministry' || status === 'received',
                    'pkpBadge--isWarnable': status === 'needs-correction',
                    'pkpBadge--isDisabled': status === 'notSynced',
                };
            },
            getCurrentPublication(item) {
                return (item.publications || []).find((publication) => publication.id === item.currentPublicationId) || null;
            },
            getStatusKey(item) {
                const status = this.getStatusValue(item);
                if (status === 'accepted-by-ministry') {
                    return 'plugins.generic.nastis.status.accepted';
                }
                if (status === 'needs-correction') {
                    return 'plugins.generic.nastis.status.needsCorrection';
                }
                if (status === 'received') {
                    return 'plugins.generic.nastis.status.received';
                }
                return 'plugins.generic.nastis.status.notSynced';
            },
            getStatusUrl(item) {
                return this.statusApiUrl.replace('__submissionId__', item.id);
            },
            getStatusValue(item) {
                return item.nastisLastStatus || 'notSynced';
            },
            getTitle(item) {
                const publication = this.getCurrentPublication(item);
                if (!publication) {
                    return '';
                }
                return this.localize(publication.fullTitle, publication.locale);
            },
            isExpanded(id) {
                return this.expandedIds.includes(id);
            },
            isFilterActive(param, value) {
                if (param === 'status') {
                    return true;
                }
                if (param !== 'nastisStatus') {
                    return false;
                }
                return this.activeFilters.nastisStatus.includes(value);
            },
            isSelected(id) {
                return this.selectedIds.includes(id);
            },
            loadItems() {
                this.isLoading = true;
                $.ajax({
                    data: {
                        searchPhrase: this.searchPhrase || undefined,
                        nastisStatus: this.activeFilters.nastisStatus,
                    },
                    dataType: 'json',
                    method: 'GET',
                    url: this.searchUrl,
                    success: (response) => {
                        const content = response && response.content ? response.content : response;
                        this.itemsState = content.items || [];
                        this.itemsStateMax = content.itemsMax || 0;
                        const allowedIds = new Set(this.itemsState.map((item) => item.id));
                        this.selectedIds = this.selectedIds.filter((id) => allowedIds.has(id));
                        this.emitState();
                    },
                    complete: () => {
                        this.isLoading = false;
                    },
                });
            },
            removeFilter(param, value) {
                if (param !== 'nastisStatus') {
                    return;
                }
                this.activeFilters.nastisStatus = this.activeFilters.nastisStatus.filter((item) => item !== value);
                this.loadItems();
            },
            selectAll() {
                this.selectedIds = this.itemsState.map((item) => item.id);
            },
            selectNone() {
                this.selectedIds = [];
            },
            setSearch(searchPhrase) {
                this.searchPhrase = searchPhrase;
                if (this.searchTimeout) {
                    window.clearTimeout(this.searchTimeout);
                }
                this.searchTimeout = window.setTimeout(() => {
                    this.loadItems();
                }, 250);
            },
            toggleExpanded(id) {
                if (this.isExpanded(id)) {
                    this.expandedIds = this.expandedIds.filter((itemId) => itemId !== id);
                    return;
                }
                this.expandedIds = [...this.expandedIds, id];
            },
            toggleSelection(id) {
                if (this.isSelected(id)) {
                    this.selectedIds = this.selectedIds.filter((itemId) => itemId !== id);
                    return;
                }
                this.selectedIds = [...this.selectedIds, id];
            },
            t(key, params) {
                return localeTools.t ? localeTools.t(key, params) : translate(key, params);
            },
            localize(value, preferredLocale) {
                return localizeValue(value, preferredLocale);
            },
        },
        template: `
            <list-panel class="listPanel--nastis" :items="itemsState" :is-sidebar-visible="true">
                <template #header>
                    <pkp-header>
                        <h1>{{ title }}</h1>
                        <template #actions>
                            <search
                                :search-label="t('common.search')"
                                :search-phrase="searchPhrase"
                                @search-phrase-changed="setSearch"
                            ></search>
                            <span style="font-weight:600;">{{ t('plugins.generic.nastis.action.bulkActions') }}</span>
                            <pkp-button @click="selectAll" type="button">
                                {{ t('plugins.generic.nastis.action.selectAll') }}
                            </pkp-button>
                            <pkp-button @click="selectNone" type="button">
                                {{ t('plugins.generic.nastis.action.selectNone') }}
                            </pkp-button>
                            <pkp-button :is-disabled="!hasSelections" type="submit">
                                {{ t('plugins.generic.nastis.action.syncSelected') }}
                            </pkp-button>
                        </template>
                    </pkp-header>
                </template>
                <template #sidebar>
                    <div class="listPanel__block">
                        <pkp-header>
                            <h2>
                                <icon icon="Filter" class="h-4 w-4" :inline="true"></icon>
                                {{ t('common.filter') }}
                            </h2>
                        </pkp-header>
                    </div>
                    <div
                        v-for="(filterGroup, filterGroupIndex) in filters"
                        :key="'filter-group-' + filterGroupIndex"
                        class="listPanel__block"
                    >
                        <pkp-header v-if="filterGroup.heading">
                            <h3>{{ filterGroup.heading }}</h3>
                        </pkp-header>
                        <pkp-filter
                            v-for="filter in filterGroup.filters"
                            :key="filter.param + '-' + filter.value"
                            v-bind="filter"
                            :is-filter-active="isFilterActive(filter.param, filter.value)"
                            @add-filter="addFilter"
                            @remove-filter="removeFilter"
                        ></pkp-filter>
                    </div>
                </template>
                <template #item="{ item }">
                    <div :id="'list-item-submission-' + item.id" class="listPanel__item--thoth">
                        <div class="listPanel__itemSummary">
                            <label class="doiListItem__selectWrapper">
                                <div class="doiListItem__selector">
                                    <input
                                        type="checkbox"
                                        name="selectedSubmissions[]"
                                        :value="item.id"
                                        :checked="isSelected(item.id)"
                                        @change="toggleSelection(item.id)"
                                    />
                                </div>
                                <div class="listPanel__itemIdentity">
                                    <div class="listPanel__itemTitle doiListItem__itemTitle">
                                        <span v-if="getAuthors(item)" class="listPanel__item--submission__author">
                                            {{ getAuthors(item) }}
                                        </span>
                                    </div>
                                    <div class="listPanel__itemSubtitle">
                                        <a :href="item.urlWorkflow" target="_blank" rel="noopener noreferrer">
                                            {{ getTitle(item) }}
                                        </a>
                                    </div>
                                </div>
                            </label>
                            <div class="listPanel__itemActions">
                                <span>{{ item.id }}</span>
                                <div class="doiListItem__itemMetadata">
                                    <pkp-badge
                                        class="doiListItem__itemMetadata--badge"
                                        :class="getBadgeClass(item)"
                                    >
                                        {{ t(getStatusKey(item)) }}
                                    </pkp-badge>
                                </div>
                                <button class="expander" type="button" @click="toggleExpanded(item.id)">
                                    <icon :icon="isExpanded(item.id) ? 'ChevronUp' : 'ChevronDown'" :inline="true"></icon>
                                    <span class="-screenReader">
                                        {{ isExpanded(item.id)
                                            ? t('list.viewLess', { name: item.id.toString() })
                                            : t('list.viewMore', { name: item.id.toString() }) }}
                                    </span>
                                </button>
                            </div>
                        </div>
                        <div v-if="isExpanded(item.id)" class="listPanel__itemExpanded listPanel__itemExpanded--thoth">
                            <div class="nastisListItem__detailRow">
                                <strong>externalArticleId:</strong> {{ item.nastisExternalArticleId || '-' }}
                            </div>
                            <div v-if="item.nastisLastSyncedAt" class="nastisListItem__detailRow">
                                <strong>{{ t('plugins.generic.nastis.tab.lastSyncedAt') }}:</strong> {{ item.nastisLastSyncedAt }}
                            </div>
                            <div v-if="item.nastisLastError" class="nastisListItem__detailRow nastisListItem__detailRow--error">
                                <strong>{{ t('common.error') }}:</strong> {{ item.nastisLastError }}
                            </div>
                            <div class="nastisListItem__actions">
                                <pkp-button element="a" :href="item.urlWorkflow">
                                    {{ t('common.view') }}
                                </pkp-button>
                                <pkp-button element="a" :href="getStatusUrl(item)">
                                    {{ t('plugins.generic.nastis.action.apiStatus') }}
                                </pkp-button>
                            </div>
                        </div>
                    </div>
                </template>
                <template #footer>
                    <div v-if="itemsStateMax > count" class="listPanel__itemsSummary">
                        {{ t('plugins.generic.nastis.search.showingResults', { shown: itemsState.length, total: itemsStateMax }) }}
                    </div>
                </template>
                <template v-if="isLoading" #itemsEmpty>
                    {{ t('common.loading') }}
                </template>
                <template v-else-if="!itemsState.length" #itemsEmpty>
                    {{ t('plugins.generic.nastis.search.noResults') }}
                </template>
            </list-panel>
        `,
    };

    pkp.registry.registerComponent('NastisListPanel', component);
})();
