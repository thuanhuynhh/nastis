<?php

namespace APP\plugins\generic\nastis\classes\services;

use APP\core\Application;
use APP\facades\Repo;
use APP\publication\Publication;
use APP\plugins\generic\nastis\NastisPlugin;
use APP\plugins\generic\nastis\classes\notification\NastisNotification;
use PKP\config\Config;
use PKP\submissionFile\SubmissionFile;

class NastisSyncService
{
    public function __construct(private NastisPlugin $plugin)
    {
    }

    /**
     * @param ?Publication $publication Publication to send instead of the submission's
     *                                  current one. Needed by the Publication::edit
     *                                  listener, which runs before the edit is written
     *                                  and so holds values the database does not yet have.
     */
    public function syncBySubmissionId(int $submissionId, bool $forceNotification = false, ?Publication $publication = null): array
    {
        $submission = Repo::submission()->get($submissionId);
        if (!$submission) {
            throw new \RuntimeException(__('api.404.resourceNotFound'));
        }

        $publication ??= $submission->getCurrentPublication();
        if (!$publication) {
            throw new \RuntimeException(__('plugins.generic.nastis.error.noPublication'));
        }

        $settings = $this->getSettings((int) $submission->getData('contextId'));
        $this->assertConfigured($settings);

        $request = Application::get()->getRequest();
        $notification = new NastisNotification();
        $mapper = new NastisMapper();
        $client = new NastisApiClient($settings);

        $journalCode = trim((string) $settings['journalCode']);
        $externalArticleId = $this->resolveExternalArticleId($submission, $mapper, $journalCode);

        $payload = $mapper->buildPayload($submission, $publication, $settings);
        $payload['externalArticleId'] = $externalArticleId;
        $payloadHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $uploadPdf = ($settings['uploadPdf'] ?? '1') === '1';
        $file = $uploadPdf ? $this->findPdfFile((int) $publication->getId()) : null;
        $filePath = $file ? $this->resolveFilePath($file) : null;

        if ($file && $filePath === null) {
            // A galley points at a file that is not on disk. The sync continues so
            // metadata still reaches VJOL, but this must not pass unnoticed.
            error_log(sprintf(
                'Nastis: PDF galley file for submission %d is missing from files_dir (submissionFileId %d); syncing metadata only.',
                $submissionId,
                $file->getId()
            ));
        }

        $fileLabel = $filePath !== null
            ? ($file->getLocalizedData('name') ?: basename($filePath))
            : null;
        $fileLocale = $payload['article']['locale'] ?? null;
        $candidateFileRef = $filePath !== null ? $file->getId() . ':' . $file->getData('fileId') : null;

        // Track whether the ministry copy has ever been confirmed, so a failed first
        // attempt does not leave the submission believing it was created.
        $alreadyIngested = (string) $submission->getData('nastisExternalArticleId') === $externalArticleId;
        $storedFileRef = (string) $submission->getData('nastisLastFileRef');
        $fileRef = $storedFileRef ?: null;

        try {
            $metadataResult = null;
            $fileResult = null;

            if (!$alreadyIngested) {
                // Spec 6: POST /articles is multipart — metadata and the article file
                // are submitted together, and the article is published on success.
                try {
                    $metadataResult = $client->createArticle($payload, $filePath, $fileLabel, $fileLocale);

                    // Only CREATED means the server actually ingested the multipart
                    // file. ALREADY_EXISTS (spec 6.5) reports the identical payload was
                    // discarded, so the file still has to go through /files below.
                    if ($filePath !== null && ($metadataResult['code'] ?? '') === NastisApiClient::CODE_CREATED) {
                        $fileRef = $candidateFileRef;
                    }
                } catch (NastisApiException $e) {
                    if (!$e->isConflict()) {
                        throw $e;
                    }

                    // Spec 12.3: PAYLOAD_CONFLICT means the id exists with different
                    // content — the documented recovery is to update it via PUT.
                    $metadataResult = $client->updateArticle(
                        $externalArticleId,
                        $mapper->buildUpdatePayload($payload)
                    );

                    // The remote copy differs from ours, so re-deliver the file — but
                    // only when there is one, otherwise a known ref would be discarded.
                    if ($filePath !== null) {
                        $fileRef = null;
                    }
                }
            } elseif ((string) $submission->getData('nastisLastHash') !== $payloadHash) {
                $metadataResult = $client->updateArticle(
                    $externalArticleId,
                    $mapper->buildUpdatePayload($payload)
                );
            }

            // Spec 8: any file change after the article exists goes through /files.
            if ($filePath !== null && $fileRef !== $candidateFileRef) {
                $fileResult = $client->uploadFile($externalArticleId, $filePath, $fileLabel, $fileLocale);
                $fileRef = $candidateFileRef;
            }

            $statusResult = $client->getStatus($externalArticleId);

            $summary = [
                'externalArticleId' => $externalArticleId,
                'metadata' => $metadataResult,
                'file' => $fileResult,
                'status' => $statusResult,
            ];

            Repo::submission()->edit($submission, [
                'nastisExternalArticleId' => $externalArticleId,
                'nastisLastHash' => $payloadHash,
                'nastisLastStatus' => $statusResult['body']['status'] ?? 'published',
                'nastisLastResponse' => $this->truncate(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                'nastisLastError' => null,
                'nastisLastSyncedAt' => date('c'),
                'nastisLastFileRef' => $fileRef,
            ]);

            if ($forceNotification) {
                $notification->notifySuccess($request, $submission, 'plugins.generic.nastis.sync.success');
            } else {
                $notification->log($request, $submission, 'plugins.generic.nastis.sync.success');
            }

            return $summary;
        } catch (\Throwable $e) {
            $error = $e instanceof NastisApiException
                ? '[' . $e->getApiCode() . '] ' . $e->getMessage()
                : $e->getMessage();

            // Re-read before writing: Repo::submission()->edit() merges into the
            // passed object's _data, so reusing the stale $submission captured above
            // would undo a success-path write that a later step then threw over.
            $current = Repo::submission()->get($submissionId) ?: $submission;

            Repo::submission()->edit($current, [
                'nastisLastError' => $this->truncate($error),
                'nastisLastSyncedAt' => date('c'),
            ]);

            if ($forceNotification) {
                $notification->notifyError($request, $submission, 'plugins.generic.nastis.sync.error', $error);
            } else {
                $notification->log($request, $submission, 'plugins.generic.nastis.sync.error', $error);
            }

            throw $e;
        }
    }

    /**
     * Probe the configured credentials without touching any submission: /health proves
     * the server is up, then an authenticated read proves the credentials are accepted.
     */
    public function testConnection(int $contextId, array $overrides = []): array
    {
        // Overrides let the settings form validate credentials the editor has typed
        // but not yet saved. NastisApiClient accepts a plaintext or encrypted key.
        $settings = array_merge(
            $this->getSettings($contextId),
            array_filter($overrides, fn ($value) => is_string($value) && trim($value) !== '')
        );
        $this->assertConfigured($settings);

        $client = new NastisApiClient($settings);
        $health = $client->health();
        $healthy = ($health['statusCode'] ?? 0) === 200;

        // A probe id in the credential's own namespace: valid credentials answer
        // NOT_FOUND, invalid ones answer AUTH_INVALID or JOURNAL_MISMATCH.
        $probeId = trim((string) $settings['journalCode']) . '-nastis-connection-test';

        try {
            $client->getStatus($probeId);

            return [
                'ok' => true,
                'healthy' => $healthy,
                'code' => NastisApiClient::CODE_NOT_FOUND,
                'message' => __('plugins.generic.nastis.test.success'),
            ];
        } catch (NastisApiException $e) {
            if ($e->isNotFound()) {
                return [
                    'ok' => true,
                    'healthy' => $healthy,
                    'code' => $e->getApiCode(),
                    'message' => __('plugins.generic.nastis.test.success'),
                ];
            }

            return [
                'ok' => false,
                'healthy' => $healthy,
                'code' => $e->getApiCode(),
                'message' => $this->describeConnectionFailure($e),
            ];
        }
    }

    private function describeConnectionFailure(NastisApiException $e): string
    {
        $key = match ($e->getApiCode()) {
            NastisApiClient::CODE_AUTH_INVALID => 'plugins.generic.nastis.test.authInvalid',
            NastisApiClient::CODE_JOURNAL_MISMATCH => 'plugins.generic.nastis.test.journalMismatch',
            NastisApiClient::CODE_TRANSPORT_ERROR => 'plugins.generic.nastis.test.unreachable',
            default => null,
        };

        return $key
            ? __($key) . ' (' . $e->getMessage() . ')'
            : '[' . $e->getApiCode() . '] ' . $e->getMessage();
    }

    /**
     * Spec 4.1 makes externalArticleId immutable, so a stored value is reused — unless
     * it predates this plugin's `{journalCode}-{submissionId}` format or the journal
     * code has since changed, in which case it could never have been accepted anyway.
     */
    private function resolveExternalArticleId($submission, NastisMapper $mapper, string $journalCode): string
    {
        $stored = trim((string) $submission->getData('nastisExternalArticleId'));
        if ($stored !== '' && $journalCode !== '' && str_starts_with($stored, $journalCode . '-')) {
            return $stored;
        }

        return $mapper->buildExternalArticleId($submission, $journalCode);
    }

    private function getSettings(int $contextId): array
    {
        return [
            'baseUrl' => $this->plugin->getSetting($contextId, 'baseUrl'),
            'journalCode' => $this->plugin->getSetting($contextId, 'journalCode'),
            'clientId' => $this->plugin->getSetting($contextId, 'clientId'),
            'apiKey' => $this->plugin->getSetting($contextId, 'apiKey'),
            'uploadPdf' => $this->plugin->getSetting($contextId, 'uploadPdf') ?: '1',
        ];
    }

    private function assertConfigured(array $settings): void
    {
        foreach (['baseUrl', 'journalCode', 'clientId', 'apiKey'] as $required) {
            if (empty($settings[$required])) {
                throw new \RuntimeException(__('plugins.generic.nastis.error.notConfigured'));
            }
        }
    }

    private function findPdfFile(int $publicationId): ?SubmissionFile
    {
        $galleys = Repo::galley()->getCollector()
            ->filterByPublicationIds([$publicationId])
            ->getMany();

        foreach ($galleys as $galley) {
            $submissionFileId = (int) $galley->getData('submissionFileId');
            if (!$submissionFileId) {
                continue;
            }

            $file = Repo::submissionFile()->get($submissionFileId);
            if ($file && $file->getData('mimetype') === 'application/pdf') {
                return $file;
            }
        }

        return null;
    }

    private function resolveFilePath(SubmissionFile $file): ?string
    {
        $path = (string) $file->getData('path');
        if ($path === '') {
            return null;
        }

        $fullPath = rtrim((string) Config::getVar('files', 'files_dir'), '/\\')
            . DIRECTORY_SEPARATOR
            . ltrim($path, '/\\');

        return is_file($fullPath) ? $fullPath : null;
    }

    private function truncate(?string $value, int $limit = 65000): ?string
    {
        if ($value === null) {
            return null;
        }

        return strlen($value) > $limit ? substr($value, 0, $limit) : $value;
    }
}
