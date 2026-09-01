<?php

namespace APP\plugins\generic\nastis\classes\handlers\pages;

use APP\core\Application;
use APP\facades\Repo;
use APP\handler\Handler;
use APP\submission\Submission;
use APP\plugins\generic\nastis\classes\services\NastisSyncService;
use APP\template\TemplateManager;
use Illuminate\Database\Query\Builder;
use PKP\facades\Locale;
use PKP\core\JSONMessage;
use PKP\db\DAORegistry;
use PKP\plugins\PluginRegistry;
use PKP\security\authorization\PKPSiteAccessPolicy;
use PKP\security\Role;
use PKP\userGroup\UserGroup;

class NastisHandler extends Handler
{
    public $_isBackendPage = true;

    public function __construct()
    {
        parent::__construct();
        $this->addRoleAssignment(
            [Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_MANAGER],
            ['index', 'search', 'sync']
        );
    }

    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new PKPSiteAccessPolicy($request, null, $roleAssignments));
        return parent::authorize($request, $args, $roleAssignments);
    }

    public function initialize($request, $args = null)
    {
        $this->setupTemplate($request);
        parent::initialize($request, $args);
    }

    public function index($args, $request)
    {
        return $this->renderPage($request);
    }

    public function sync($args, $request)
    {
        $selectedSubmissionIds = array_map('intval', (array) $request->getUserVar('selectedSubmissions'));
        $results = [];

        if ($selectedSubmissionIds) {
            $service = new NastisSyncService(PluginRegistry::getPlugin('generic', 'nastisplugin'));
            foreach ($selectedSubmissionIds as $submissionId) {
                try {
                    $service->syncBySubmissionId($submissionId, false);
                    $results[] = [
                        'submissionId' => $submissionId,
                        'success' => true,
                        'message' => __('plugins.generic.nastis.sync.success'),
                    ];
                } catch (\Throwable $e) {
                    $results[] = [
                        'submissionId' => $submissionId,
                        'success' => false,
                        'message' => $e->getMessage(),
                    ];
                }
            }
        }

        if ((bool) $request->getUserVar('json')) {
            $listData = $this->getListData($request);
            return new JSONMessage(true, [
                'results' => $results,
                'items' => $listData['items'],
                'itemsMax' => $listData['itemsMax'],
            ]);
        }

        return $this->renderPage($request, $results);
    }

    public function search($args, $request)
    {
        return new JSONMessage(true, $this->getListData($request));
    }

    private function renderPage($request, array $results = [])
    {
        $context = $request->getContext();
        $plugin = PluginRegistry::getPlugin('generic', 'nastisplugin');
        $templateMgr = TemplateManager::getManager($request);
        $listData = $this->getListData($request);
        $searchPhrase = (string) $request->getUserVar('searchPhrase');
        $selectedStatuses = array_values(array_filter(array_map(
            'strval',
            (array) $request->getUserVar('nastisStatus')
        )));
        $selectedIssues = array_values(array_filter(array_map(
            'intval',
            (array) $request->getUserVar('nastisIssue')
        )));
        $count = max(1, min(100, (int) $request->getUserVar('count') ?: 30));
        $page = max(1, (int) $request->getUserVar('page') ?: 1);
        $pageCount = max(1, (int) ceil($listData['itemsMax'] / $count));

        if ($page > $pageCount) {
            $page = $pageCount;
        }

        $templateMgr->assign([
            'nastisBaseUrl' => $plugin->getSetting($context->getId(), 'baseUrl'),
            'nastisJournalCode' => $plugin->getSetting($context->getId(), 'journalCode'),
            'nastisAutoSyncOnPublish' => $plugin->getSetting($context->getId(), 'autoSyncOnPublish') === '1',
            'nastisAutoSyncOnEdit' => $plugin->getSetting($context->getId(), 'autoSyncOnEdit') === '1',
            'nastisUploadPdf' => $plugin->getSetting($context->getId(), 'uploadPdf') !== '0',
            'nastisIssueOptions' => $this->getIssueOptions($context->getId()),
            'nastisItems' => $listData['items'],
            'nastisItemsMax' => $listData['itemsMax'],
            'nastisSearchPhrase' => $searchPhrase,
            'nastisSelectedStatuses' => $selectedStatuses,
            'nastisSelectedIssues' => $selectedIssues,
            'nastisCount' => $count,
            'nastisPage' => $page,
            'nastisPageCount' => $pageCount,
            'nastisResults' => $results,
        ]);

        return $templateMgr->display($plugin->getTemplateResource('nastis/index.tpl'));
    }

    private function getListData($request): array
    {
        $context = $request->getContext();
        $count = max(1, min(100, (int) $request->getUserVar('count') ?: 30));
        $page = max(1, (int) $request->getUserVar('page') ?: 1);
        $offset = max(0, (int) $request->getUserVar('offset'));
        if (!$offset) {
            $offset = ($page - 1) * $count;
        }
        $searchPhrase = trim((string) $request->getUserVar('searchPhrase'));
        $nastisStatuses = array_values(array_filter(array_map(
            'strval',
            (array) $request->getUserVar('nastisStatus')
        )));
        $nastisIssueIds = array_values(array_filter(array_map(
            'intval',
            (array) $request->getUserVar('nastisIssue')
        )));

        $collector = Repo::submission()
            ->getCollector()
            ->filterByContextIds([$context->getId()])
            ->filterByStatus([Submission::STATUS_PUBLISHED])
            ->orderBy('id', Repo::submission()->getCollector()::ORDER_DIR_DESC);

        if ($nastisIssueIds) {
            $collector->filterByIssueIds($nastisIssueIds);
        }

        if ($searchPhrase !== '') {
            $collector->searchPhrase($searchPhrase);
        }

        $query = $collector->getQueryBuilder();
        $this->applyNastisStatusFilter($query, $nastisStatuses);

        $itemsMax = (clone $query)
            ->distinct()
            ->count('s.submission_id');

        $submissionIds = (clone $query)
            ->select('s.submission_id')
            ->distinct()
            ->limit($count)
            ->offset($offset)
            ->pluck('s.submission_id')
            ->map(fn ($submissionId) => (int) $submissionId)
            ->all();

        if (empty($submissionIds)) {
            return [
                'items' => [],
                'itemsMax' => $itemsMax,
            ];
        }

        $submissions = Repo::submission()
            ->getCollector()
            ->filterByContextIds([$context->getId()])
            ->filterBySubmissionIds($submissionIds)
            ->getMany()
            ->remember();

        $submissionOrder = array_flip($submissionIds);
        $submissions = $submissions->sortBy(
            fn ($submission) => $submissionOrder[$submission->getId()] ?? PHP_INT_MAX
        );

        $userGroups = UserGroup::withContextIds($context->getId())->get();
        $genreDao = DAORegistry::getDAO('GenreDAO');
        $genres = $genreDao->getByContextId($context->getId())->toArray();

        $items = Repo::submission()->getSchemaMap()
            ->mapManyToSubmissionsList(
                $submissions,
                $userGroups,
                $genres,
                $this->getAuthorizedContextObject(Application::ASSOC_TYPE_USER_ROLES)
            )
            ->values()
            ->map(function ($item) {
                static $submissionIssueLabels = [];

                $item['nastisStatusLabel'] = $item['nastisLastStatus'] ?: __('plugins.generic.nastis.status.notSynced');
                $item['publications'] = collect($item['publications'] ?? [])
                    ->map(function ($publication) {
                        static $issueLabels = [];

                        if (isset($publication['fullTitle']) && is_array($publication['fullTitle'])) {
                            $publication['fullTitle'] = $this->getLocalizedValue(
                                $publication['fullTitle'],
                                $publication['locale'] ?? null
                            );
                        }

                        if (isset($publication['authorsStringShort']) && is_array($publication['authorsStringShort'])) {
                            $publication['authorsStringShort'] = $this->getLocalizedValue(
                                $publication['authorsStringShort'],
                                $publication['locale'] ?? null
                            );
                        }

                        $issueId = (int) ($publication['issueId'] ?? 0);
                        if ($issueId > 0) {
                            if (!array_key_exists($issueId, $issueLabels)) {
                                $issueLabels[$issueId] = Repo::issue()->get($issueId)?->getIssueIdentification() ?? '';
                            }
                            $publication['issueLabel'] = $issueLabels[$issueId];
                        } else {
                            $publication['issueLabel'] = '';
                        }

                        return $publication;
                    })
                    ->values()
                    ->all();

                $currentPublicationIndex = collect($item['publications'])
                    ->search(fn ($publication) => (int) ($publication['id'] ?? 0) === (int) ($item['currentPublicationId'] ?? 0));

                if ($currentPublicationIndex !== false && empty($item['publications'][$currentPublicationIndex]['issueLabel'])) {
                    $submissionId = (int) ($item['id'] ?? 0);
                    if ($submissionId > 0) {
                        if (!array_key_exists($submissionId, $submissionIssueLabels)) {
                            $submissionIssueLabels[$submissionId] = Repo::issue()->getBySubmissionId($submissionId)?->getIssueIdentification() ?? '';
                        }
                        $item['publications'][$currentPublicationIndex]['issueLabel'] = $submissionIssueLabels[$submissionId];
                    }
                }

                return $item;
            })
            ->values();

        return [
            'items' => $items->all(),
            'itemsMax' => $itemsMax,
        ];
    }

    private function applyNastisStatusFilter(Builder $query, array $nastisStatuses): void
    {
        if (empty($nastisStatuses)) {
            return;
        }

        $query->leftJoin('submission_settings as nastis_status', function ($join) {
            $join->on('nastis_status.submission_id', '=', 's.submission_id')
                ->where('nastis_status.setting_name', '=', 'nastisLastStatus');
        });

        $query->where(function (Builder $filterQuery) use ($nastisStatuses) {
            $selectedStatuses = array_values(array_unique($nastisStatuses));
            $nonDefaultStatuses = array_values(array_filter(
                $selectedStatuses,
                fn (string $status) => $status !== 'notSynced'
            ));

            if (in_array('notSynced', $selectedStatuses, true)) {
                $filterQuery
                    ->whereNull('nastis_status.setting_value')
                    ->orWhere('nastis_status.setting_value', '');
            }

            if (!empty($nonDefaultStatuses)) {
                $filterQuery->orWhereIn('nastis_status.setting_value', $nonDefaultStatuses);
            }
        });
    }

    private function getIssueOptions(int $contextId): array
    {
        return Repo::issue()->getCollector()
            ->filterByContextIds([$contextId])
            ->filterByPublished(true)
            ->orderBy(\APP\issue\Collector::ORDERBY_PUBLISHED_ISSUES)
            ->getMany()
            ->map(fn ($issue) => [
                'value' => (int) $issue->getId(),
                'label' => $issue->getIssueIdentification(),
            ])
            ->values()
            ->all();
    }

    private function getLocalizedValue(array|string|null $value, ?string $preferredLocale = null): string
    {
        if (!is_array($value)) {
            return (string) ($value ?? '');
        }

        $request = Application::get()->getRequest();
        $localePrecedence = array_unique(array_filter([
            $preferredLocale,
            Locale::getLocale(),
            $request->getContext()?->getPrimaryLocale(),
            $request->getSite()?->getPrimaryLocale(),
        ]));

        foreach ($localePrecedence as $locale) {
            if (!empty($value[$locale])) {
                return (string) $value[$locale];
            }
        }

        foreach ($value as $localizedValue) {
            if (!empty($localizedValue)) {
                return (string) $localizedValue;
            }
        }

        return '';
    }
}
