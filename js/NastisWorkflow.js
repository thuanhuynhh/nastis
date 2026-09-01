(function () {
    if (typeof pkp === 'undefined' || !pkp.modules || !pkp.modules.vue || !pkp.registry) {
        return;
    }

    const {
        h,
        ref,
        computed,
        onMounted
    } = pkp.modules.vue;

    const NastisSection = {
        name: 'NastisSection',
        props: {
            submission: { type: Object, required: true },
            syncUrl: { type: String, required: true },
            statusUrl: { type: String, required: true },
            pageUrl: { type: String, required: true }
        },
        setup(props) {
            const { useLocalize, useDataChanged } = pkp.modules;
            const { t } = useLocalize();
            const { triggerDataChange } = useDataChanged();
            const status = ref(props.submission.nastisLastStatus || '');
            const error = ref(props.submission.nastisLastError || '');
            const syncedAt = ref(props.submission.nastisLastSyncedAt || '');
            const loading = ref(false);

            const badgeClass = computed(() => {
                if (error.value) return 'nastisBadge nastisBadge--error';
                if (!status.value) return 'nastisBadge nastisBadge--neutral';
                if (status.value === 'needs-correction') return 'nastisBadge nastisBadge--warning';
                if (status.value === 'accepted-by-ministry' || status.value === 'received') return 'nastisBadge nastisBadge--success';
                return 'nastisBadge nastisBadge--neutral';
            });

            const statusLabel = computed(() => {
                return status.value || t('plugins.generic.nastis.status.notSynced');
            });

            const fetchStatus = () => {
                if (!props.submission.nastisExternalArticleId) {
                    return;
                }
                $.ajax({
                    method: 'GET',
                    url: props.statusUrl,
                    headers: { 'X-Csrf-Token': pkp.currentUser.csrfToken },
                    success(response) {
                        status.value = response.lastStatus || response.status || '';
                        error.value = response.lastError || '';
                        syncedAt.value = response.lastSyncedAt || '';
                    }
                });
            };

            const syncNow = () => {
                loading.value = true;
                $.ajax({
                    method: 'PUT',
                    url: props.syncUrl,
                    headers: { 'X-Csrf-Token': pkp.currentUser.csrfToken },
                    success() {
                        pkp.eventBus.$emit('notify', t('plugins.generic.nastis.sync.success'), 'success');
                        triggerDataChange();
                        fetchStatus();
                    },
                    error(xhr) {
                        const message = xhr?.responseJSON?.error || t('plugins.generic.nastis.sync.error');
                        error.value = message;
                        pkp.eventBus.$emit('notify', message, 'warning');
                    },
                    complete() {
                        loading.value = false;
                    }
                });
            };

            onMounted(fetchStatus);

            return () => h('div', { class: 'nastisWorkflowSection' }, [
                h('div', { class: 'nastisWorkflowSection__row' }, [
                    h('span', { class: 'nastisWorkflowSection__label' }, t('plugins.generic.nastis.navigation.nastis') + ':'),
                    h('span', { class: badgeClass.value }, statusLabel.value)
                ]),
                props.submission.nastisExternalArticleId ? h('div', { class: 'nastisWorkflowSection__meta' }, 'externalArticleId: ' + props.submission.nastisExternalArticleId) : null,
                syncedAt.value ? h('div', { class: 'nastisWorkflowSection__meta' }, t('plugins.generic.nastis.tab.lastSyncedAt') + ': ' + syncedAt.value) : null,
                error.value ? h('div', { class: 'nastisWorkflowSection__error' }, error.value) : null,
                h('div', { class: 'nastisWorkflowSection__actions' }, [
                    h('button', {
                        class: 'pkpButton',
                        type: 'button',
                        disabled: loading.value,
                        onClick: syncNow
                    }, loading.value ? '...' : t('plugins.generic.nastis.action.syncNow')),
                    h('a', {
                        class: 'pkpButton pkpButton--isLink',
                        href: props.pageUrl
                    }, t('plugins.generic.nastis.action.openPanel'))
                ])
            ]);
        }
    };

    pkp.registry.registerComponent('NastisSection', NastisSection);
    pkp.registry.storeExtend('workflow', (store) => {
        store.store.extender.extendFn('getPrimaryControlsLeft', (controls, ctx) => {
            if (ctx?.selectedMenuState?.primaryMenuItem !== 'publication') {
                return controls;
            }

            const submission = ctx.submission;
            if (!submission) {
                return controls;
            }

            const workflow = (((pkp.plugins || {}).generic || {}).nastis || {}).workflow || {};
            const syncUrl = (workflow.syncUrl || '').replace('__submissionId__', submission.id);
            const statusUrl = (workflow.statusUrl || '').replace('__submissionId__', submission.id);

            return [
                ...controls,
                {
                    component: 'NastisSection',
                    props: {
                        submission,
                        syncUrl,
                        statusUrl,
                        pageUrl: workflow.pageUrl || '#'
                    }
                }
            ];
        });
    });
}());
