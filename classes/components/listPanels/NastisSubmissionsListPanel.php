<?php

namespace APP\plugins\generic\nastis\classes\components\listPanels;

use APP\components\listPanels\SubmissionsListPanel;
use APP\core\Application;
use APP\submission\Submission;

class NastisSubmissionsListPanel extends SubmissionsListPanel
{
    public $count = 30;

    public $includeCategoriesFilter = true;

    public $includeIssuesFilter = true;

    public $includeActiveSectionFiltersOnly = true;

    public $isSidebarVisible = true;

    public function getConfig()
    {
        $config = parent::getConfig();
        $request = Application::get()->getRequest();

        $config['csrfToken'] = $request->getSession()->token();
        $config['allowSubmissions'] = false;
        $config['filters'][] = [
            'heading' => __('common.status'),
            'filters' => [
                [
                    'param' => 'status',
                    'value' => Submission::STATUS_PUBLISHED,
                    'title' => __('publication.status.published'),
                ],
            ],
        ];

        $config['filters'] = array_values(array_filter(
            $config['filters'],
            fn ($filter) => !empty($filter['filters'])
        ));

        return $config;
    }

    public function getWorkflowStages()
    {
        return [];
    }
}
