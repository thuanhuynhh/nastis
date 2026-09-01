<?php

namespace APP\plugins\generic\nastis\classes\listeners;

use APP\plugins\generic\nastis\NastisPlugin;
use APP\plugins\generic\nastis\classes\services\NastisSyncService;

class PublicationPublishListener
{
    public function __construct(private NastisPlugin $plugin)
    {
    }

    public function syncPublishedSubmission($hookName, $args)
    {
        $submission = $args[2];

        if ($this->plugin->getSetting($submission->getData('contextId'), 'autoSyncOnPublish') !== '1') {
            return false;
        }

        try {
            (new NastisSyncService($this->plugin))->syncBySubmissionId((int) $submission->getId());
        } catch (\Throwable $e) {
            // Publishing must succeed even when VJOL is unreachable. The failure is
            // already recorded on the submission and in the event log by the service.
            error_log('Nastis auto-sync on publish failed for submission ' . $submission->getId() . ': ' . $e->getMessage());
        }

        return false;
    }
}
