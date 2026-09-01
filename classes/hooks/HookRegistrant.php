<?php

namespace APP\plugins\generic\nastis\classes\hooks;

use APP\plugins\generic\nastis\NastisPlugin;
use APP\plugins\generic\nastis\classes\api\NastisEndpoint;
use APP\plugins\generic\nastis\classes\listeners\PublicationEditListener;
use APP\plugins\generic\nastis\classes\listeners\PublicationPublishListener;
use APP\plugins\generic\nastis\classes\schema\NastisSchema;
use APP\plugins\generic\nastis\classes\templateFilters\NastisSectionTemplateFilter;
use PKP\plugins\Hook;

class HookRegistrant
{
    public function __construct(private NastisPlugin $plugin)
    {
    }

    public function register(): void
    {
        $schema = new NastisSchema();
        Hook::add('Schema::get::submission', $schema->addToSubmissionSchema(...));
        Hook::add('Submission::getSubmissionsListProps', $schema->addToSubmissionsListProps(...));

        $publishListener = new PublicationPublishListener($this->plugin);
        Hook::add('Publication::publish', $publishListener->syncPublishedSubmission(...));

        $editListener = new PublicationEditListener($this->plugin);
        Hook::add('Publication::edit', $editListener->syncEditedSubmission(...));

        Hook::add('APIHandler::endpoints::_submissions', (new NastisEndpoint($this->plugin))->addEndpoints(...));

        $menuHandler = new NastisMenuHandler();
        $pageHandler = new NastisPageHandler($this->plugin);
        Hook::add('TemplateManager::display', $menuHandler->addMenu(...));
        Hook::add('LoadHandler', $pageHandler->addHandlers(...));

        Hook::add('TemplateManager::display', $this->addScripts(...));
    }

    public function addScripts($hookName, $args): void
    {
        $templateMgr = $args[0];
        $template = $args[1];
        $request = \APP\core\Application::get()->getRequest();

        $filter = new NastisSectionTemplateFilter();
        $filter->addJavaScriptData($request, $templateMgr, $template);
        $filter->addJavaScript($request, $templateMgr, $this->plugin);
        $filter->addStyleSheet($request, $templateMgr, $this->plugin);
    }
}
