<?php

namespace APP\plugins\generic\nastis\classes\templateFilters;

use PKP\core\PKPApplication;
use PKP\template\PKPTemplateManager;

class NastisSectionTemplateFilter
{
    public function addJavaScriptData($request, $templateMgr, $template)
    {
        if ($template != 'dashboard/editors.tpl') {
            return false;
        }

        $syncUrl = $request->getDispatcher()->url(
            $request,
            PKPApplication::ROUTE_API,
            $request->getContext()->getData('urlPath'),
            '_submissions/__submissionId__/nastis/sync'
        );
        $statusUrl = $request->getDispatcher()->url(
            $request,
            PKPApplication::ROUTE_API,
            $request->getContext()->getData('urlPath'),
            '_submissions/__submissionId__/nastis/status'
        );
        $pageUrl = $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, 'nastis');

        $data = [
            'syncUrl' => $syncUrl,
            'statusUrl' => $statusUrl,
            'pageUrl' => $pageUrl,
        ];

        $output = 'pkp.plugins = pkp.plugins || {};';
        $output .= 'pkp.plugins.generic = pkp.plugins.generic || {};';
        $output .= 'pkp.plugins.generic.nastis = pkp.plugins.generic.nastis || {};';
        $output .= 'pkp.plugins.generic.nastis.workflow = ' . json_encode($data) . ';';

        $templateMgr->addJavaScript(
            'nastisWorkflowData',
            $output,
            [
                'inline' => true,
                'contexts' => 'backend',
            ]
        );
    }

    public function addJavaScript($request, $templateMgr, $plugin)
    {
        $templateMgr->addJavaScript(
            'nastisWorkflow',
            $request->getBaseUrl() . '/' . $plugin->getPluginPath() . '/js/NastisWorkflow.js',
            [
                'contexts' => ['backend'],
                'priority' => PKPTemplateManager::STYLE_SEQUENCE_LAST,
            ]
        );
    }

    public function addStyleSheet($request, $templateMgr, $plugin)
    {
        $templateMgr->addStyleSheet(
            'nastisWorkflowStyle',
            $request->getBaseUrl() . '/' . $plugin->getPluginPath() . '/js/NastisWorkflow.css',
            ['contexts' => ['backend']]
        );
    }
}
