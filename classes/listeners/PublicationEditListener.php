<?php

namespace APP\plugins\generic\nastis\classes\listeners;

use APP\facades\Repo;
use APP\plugins\generic\nastis\NastisPlugin;
use APP\plugins\generic\nastis\classes\services\NastisSyncService;

class PublicationEditListener
{
    public function __construct(private NastisPlugin $plugin)
    {
    }

    public function syncEditedSubmission($hookName, $args)
    {
        // Publication::edit fires BEFORE the DAO writes the row, so the edited
        // values exist only in $args[0]. Re-reading the publication from the
        // database here would ship the pre-edit metadata and then store its hash,
        // leaving the real edit unsynced until some later change.
        $newPublication = $args[0];
        $submission = Repo::submission()->get((int) $newPublication->getData('submissionId'));

        if (!$submission) {
            return false;
        }

        if ($this->plugin->getSetting($submission->getData('contextId'), 'autoSyncOnEdit') !== '1') {
            return false;
        }

        // Only mirror edits for articles the ministry already holds; a first delivery
        // is the publish listener's job (or an explicit sync).
        if (!$submission->getData('nastisExternalArticleId')) {
            return false;
        }

        // Editing an older version must not overwrite the ministry copy, which
        // always tracks the current publication.
        if ((int) $newPublication->getId() !== (int) $submission->getData('currentPublicationId')) {
            return false;
        }

        try {
            (new NastisSyncService($this->plugin))->syncBySubmissionId(
                (int) $submission->getId(),
                false,
                $newPublication
            );
        } catch (\Throwable $e) {
            // Editing must succeed even when VJOL is unreachable. The failure is already
            // recorded on the submission and in the event log by the service.
            error_log('Nastis auto-sync on edit failed for submission ' . $submission->getId() . ': ' . $e->getMessage());
        }

        return false;
    }
}
