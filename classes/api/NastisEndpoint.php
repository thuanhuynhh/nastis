<?php

namespace APP\plugins\generic\nastis\classes\api;

use APP\facades\Repo;
use APP\plugins\generic\nastis\NastisPlugin;
use APP\plugins\generic\nastis\classes\services\NastisSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as IlluminateRequest;
use Illuminate\Http\Response;
use PKP\core\PKPBaseController;
use PKP\handler\APIHandler;
use PKP\security\Role;

class NastisEndpoint
{
    public function __construct(private NastisPlugin $plugin)
    {
    }

    public function addEndpoints(string $hookName, PKPBaseController $apiController, APIHandler $apiHandler): bool
    {
        $roles = [
            Role::ROLE_ID_SITE_ADMIN,
            Role::ROLE_ID_MANAGER,
            Role::ROLE_ID_SUB_EDITOR,
            Role::ROLE_ID_ASSISTANT,
        ];

        $apiHandler->addRoute('PUT', '{submissionId}/nastis/sync', $this->sync(...), 'nastis.sync', $roles);
        $apiHandler->addRoute('GET', '{submissionId}/nastis/status', $this->status(...), 'nastis.status', $roles);

        return false;
    }

    public function sync(IlluminateRequest $request): JsonResponse
    {
        $submissionId = (int) $request->route('submissionId');
        $submission = Repo::submission()->get($submissionId);

        if (!$submission) {
            return response()->json(['error' => __('api.404.resourceNotFound')], Response::HTTP_NOT_FOUND);
        }

        try {
            $result = (new NastisSyncService($this->plugin))->syncBySubmissionId($submissionId, true);
            return response()->json($result, Response::HTTP_OK);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    public function status(IlluminateRequest $request): JsonResponse
    {
        $submissionId = (int) $request->route('submissionId');
        $submission = Repo::submission()->get($submissionId);

        if (!$submission) {
            return response()->json(['error' => __('api.404.resourceNotFound')], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'submissionId' => $submission->getId(),
            'externalArticleId' => $submission->getData('nastisExternalArticleId'),
            'lastStatus' => $submission->getData('nastisLastStatus'),
            'lastError' => $submission->getData('nastisLastError'),
            'lastSyncedAt' => $submission->getData('nastisLastSyncedAt'),
            'lastResponse' => $submission->getData('nastisLastResponse'),
        ], Response::HTTP_OK);
    }
}
