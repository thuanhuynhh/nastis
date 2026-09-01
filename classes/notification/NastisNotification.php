<?php

namespace APP\plugins\generic\nastis\classes\notification;

use APP\core\Application;
use APP\facades\Repo;
use APP\notification\NotificationManager;
use PKP\core\Core;
use PKP\log\event\PKPSubmissionEventLogEntry;
use PKP\notification\Notification;

class NastisNotification
{
    public function notifySuccess($request, $submission, string $messageKey): void
    {
        $this->notify($request, $submission, Notification::NOTIFICATION_TYPE_SUCCESS, $messageKey);
    }

    public function notifyError($request, $submission, string $messageKey, ?string $error = null): void
    {
        if ($error) {
            error_log('Nastis sync failed: ' . $error);
        }

        $this->notify($request, $submission, Notification::NOTIFICATION_TYPE_ERROR, $messageKey, $error);
    }

    private function notify($request, $submission, int $notificationType, string $messageKey, ?string $error = null): void
    {
        $currentUser = $request?->getUser();
        if ($currentUser) {
            $notificationMgr = new NotificationManager();
            $notificationMgr->createTrivialNotification(
                $currentUser->getId(),
                $notificationType,
                ['contents' => __($messageKey)]
            );
        }

        $this->log($request, $submission, $messageKey, $error);
    }

    public function log($request, $submission, string $messageKey, ?string $error = null): void
    {
        $currentUser = $request?->getUser();

        // The eventLog schema has no `reason` property, and EntityDAO drops unknown
        // props during sanitize(), so the detail has to live inside `message`. A
        // message carrying interpolated detail must be marked as already translated.
        $message = $error !== null && $error !== ''
            ? __($messageKey) . ' — ' . $error
            : $messageKey;

        $eventLog = Repo::eventLog()->newDataObject([
            'assocType' => Application::ASSOC_TYPE_SUBMISSION,
            'assocId' => $submission->getId(),
            'eventType' => PKPSubmissionEventLogEntry::SUBMISSION_LOG_CREATE_VERSION,
            'userId' => $currentUser?->getId(),
            'message' => $message,
            'isTranslated' => $error !== null && $error !== '',
            'dateLogged' => Core::getCurrentDate(),
        ]);
        Repo::eventLog()->add($eventLog);
    }
}
