<?php

namespace APP\plugins\generic\nastis\classes\services;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Facades\Crypt;

/**
 * Thin transport layer for the VJOL ingest API (spec v1.3).
 *
 * Base URL: https://vjol.vista.gov.vn
 *   POST /api/ingest/v1/articles                          multipart: metadata + file
 *   PUT  /api/ingest/v1/articles/{externalArticleId}       json, partial update
 *   POST /api/ingest/v1/articles/{externalArticleId}/files multipart
 *   GET  /api/ingest/v1/articles/{externalArticleId}/status
 *   GET  /health                                           unauthenticated
 */
class NastisApiClient
{
    public const CODE_CREATED = 'CREATED';
    public const CODE_ALREADY_EXISTS = 'ALREADY_EXISTS';
    public const CODE_UPDATED = 'UPDATED';
    public const CODE_FILE_STORED = 'FILE_STORED';
    public const CODE_PAYLOAD_CONFLICT = 'PAYLOAD_CONFLICT';
    public const CODE_RATE_LIMITED = 'RATE_LIMITED';
    public const CODE_VALIDATION_ERROR = 'VALIDATION_ERROR';
    public const CODE_AUTH_INVALID = 'AUTH_INVALID';
    public const CODE_JOURNAL_MISMATCH = 'JOURNAL_MISMATCH';
    public const CODE_NOT_FOUND = 'NOT_FOUND';
    public const CODE_INTERNAL_ERROR = 'INTERNAL_ERROR';
    public const CODE_TRANSPORT_ERROR = 'TRANSPORT_ERROR';

    /** Spec 3.4: write endpoints allow 10 requests/minute, so writes are paced ≥ 7s apart. */
    private const WRITE_THROTTLE_SECONDS = 7;

    /** Spec 12.3: on RATE_LIMITED, wait and retry rather than failing the sync outright. */
    private const RATE_LIMIT_RETRIES = 2;

    /** Spec 3.5: 50 MB per file. */
    public const MAX_UPLOAD_BYTES = 50 * 1024 * 1024;

    /** Shared across instances so a bulk sync paces itself even though each item builds its own client. */
    private static float $lastWriteAt = 0.0;

    private Client $client;
    private array $headers;

    public function __construct(array $settings)
    {
        $baseUrl = rtrim(trim((string) ($settings['baseUrl'] ?? '')), '/');

        $this->client = new Client([
            'base_uri' => $baseUrl . '/',
            'http_errors' => false,
            'timeout' => 120,
            'connect_timeout' => 15,
        ]);

        $this->headers = [
            'x-client-id' => trim((string) ($settings['clientId'] ?? '')),
            'x-api-key' => self::revealApiKey($settings['apiKey'] ?? ''),
            'Accept' => 'application/json',
        ];
    }

    /**
     * Decrypt a stored API key, tolerating values that were saved before
     * encryption was introduced (or written directly into the settings table).
     */
    public static function revealApiKey(?string $stored): string
    {
        if (!$stored) {
            return '';
        }

        try {
            return trim((string) Crypt::decrypt($stored));
        } catch (\Throwable) {
            return trim($stored);
        }
    }

    /**
     * Spec 11: unauthenticated liveness probe. Never throws — a dead server and a
     * healthy one are both useful answers for the settings screen.
     */
    public function health(): array
    {
        try {
            return $this->request('GET', 'health', [], false, false);
        } catch (NastisApiException $e) {
            return ['statusCode' => $e->getStatusCode(), 'code' => $e->getApiCode(), 'body' => $e->getBody()];
        }
    }

    /**
     * Spec 6: create the article. The endpoint is multipart/form-data — the metadata
     * travels as a JSON *string* part named `metadata`, alongside the article file.
     */
    public function createArticle(array $payload, ?string $filePath = null, ?string $label = null, ?string $locale = null): array
    {
        if ($filePath !== null) {
            $this->assertUploadable($filePath);
        }

        // Built per attempt: Guzzle consumes (and closes) the file stream while
        // writing the body, so a retry must open a fresh one.
        $buildOptions = function () use ($payload, $filePath, $label, $locale) {
            $parts = [
                [
                    'name' => 'metadata',
                    'contents' => $this->encode($payload),
                    'filename' => 'metadata.json',
                    'headers' => ['Content-Type' => 'application/json'],
                ],
            ];

            if ($filePath !== null) {
                $parts = array_merge($parts, $this->fileParts($filePath, $label, $locale));
            }

            return [RequestOptions::MULTIPART => $parts];
        };

        return $this->request('POST', 'api/ingest/v1/articles', $buildOptions, true);
    }

    /**
     * Spec 7: partial metadata update. Only sourceJournal.journalCode and
     * externalArticleId are mandatory in the body.
     */
    public function updateArticle(string $externalArticleId, array $payload): array
    {
        return $this->request(
            'PUT',
            'api/ingest/v1/articles/' . rawurlencode($externalArticleId),
            [
                RequestOptions::HEADERS => ['Content-Type' => 'application/json'],
                RequestOptions::BODY => $this->encode($payload),
            ],
            true
        );
    }

    /** Spec 8: attach an extra file to an article that already exists. */
    public function uploadFile(string $externalArticleId, string $filePath, ?string $label = null, ?string $locale = null): array
    {
        $this->assertUploadable($filePath);

        return $this->request(
            'POST',
            'api/ingest/v1/articles/' . rawurlencode($externalArticleId) . '/files',
            // Rebuilt per attempt so a retry gets an unconsumed file stream.
            fn () => [RequestOptions::MULTIPART => $this->fileParts($filePath, $label, $locale)],
            true
        );
    }

    /** Spec 9: read back the ministry-side status of an article. */
    public function getStatus(string $externalArticleId): array
    {
        return $this->request('GET', 'api/ingest/v1/articles/' . rawurlencode($externalArticleId) . '/status');
    }

    /** Validate the file once, before any attempt opens a stream for it. */
    private function assertUploadable(string $filePath): void
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new NastisApiException(
                __('plugins.generic.nastis.error.fileUnreadable', ['path' => basename($filePath)]),
                self::CODE_VALIDATION_ERROR,
                0
            );
        }

        if (filesize($filePath) > self::MAX_UPLOAD_BYTES) {
            throw new NastisApiException(
                __('plugins.generic.nastis.error.fileTooLarge'),
                self::CODE_VALIDATION_ERROR,
                0
            );
        }
    }

    /**
     * Build the shared file/fileRole/label/locale parts. The stream is handed to
     * Guzzle, which closes it once the request body has been written — so this
     * must be called afresh for each attempt.
     */
    private function fileParts(string $filePath, ?string $label, ?string $locale): array
    {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new NastisApiException(
                __('plugins.generic.nastis.error.fileUnreadable', ['path' => basename($filePath)]),
                self::CODE_VALIDATION_ERROR,
                0
            );
        }

        $parts = [
            [
                'name' => 'file',
                'contents' => $handle,
                'filename' => basename($filePath),
                'headers' => ['Content-Type' => 'application/pdf'],
            ],
            ['name' => 'fileRole', 'contents' => 'article-pdf'],
        ];

        if ($label !== null && $label !== '') {
            $parts[] = ['name' => 'label', 'contents' => $label];
        }

        if ($locale !== null && $locale !== '') {
            $parts[] = ['name' => 'locale', 'contents' => $locale];
        }

        return $parts;
    }

    private function encode(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array|callable $options Request options, or a callable returning them.
     *                                Multipart writes MUST pass a callable: the file
     *                                stream is consumed by each attempt.
     * @param bool           $isWrite Writes are throttled and retried on RATE_LIMITED; reads are not.
     */
    private function request(string $method, string $uri, array|callable $options = [], bool $isWrite = false, bool $authenticated = true): array
    {
        $attempt = 0;

        while (true) {
            $attemptOptions = is_callable($options) ? $options() : $options;

            if ($authenticated) {
                $attemptOptions[RequestOptions::HEADERS] = array_merge(
                    $this->headers,
                    $attemptOptions[RequestOptions::HEADERS] ?? []
                );
            }

            if ($isWrite) {
                $this->throttleWrite();
            }

            try {
                $response = $this->client->request($method, $uri, $attemptOptions);
            } catch (NastisApiException $e) {
                throw $e;
            } catch (\Throwable $e) {
                // Guzzle raises GuzzleException for network faults, but a malformed
                // body (e.g. a consumed stream) surfaces as InvalidArgumentException.
                throw new NastisApiException(
                    __('plugins.generic.nastis.error.transport', ['message' => $e->getMessage()]),
                    self::CODE_TRANSPORT_ERROR,
                    0
                );
            }

            $statusCode = $response->getStatusCode();
            $raw = (string) $response->getBody();
            $decoded = $raw !== '' ? json_decode($raw, true) : [];
            $body = is_array($decoded) ? $decoded : ['raw' => $raw];
            $code = (string) ($body['code'] ?? '');

            // Spec 12.3: wait ≥ 7s and retry instead of surfacing a rate limit to the editor.
            if (($statusCode === 429 || $code === self::CODE_RATE_LIMITED) && $attempt < self::RATE_LIMIT_RETRIES) {
                $attempt++;
                sleep(self::WRITE_THROTTLE_SECONDS);
                continue;
            }

            $result = [
                'statusCode' => $statusCode,
                'code' => $code ?: ($statusCode < 400 ? 'OK' : 'HTTP_' . $statusCode),
                'body' => $body,
            ];

            if ($statusCode >= 400) {
                throw new NastisApiException(
                    $this->describe($body, $statusCode),
                    $result['code'],
                    $statusCode,
                    $body
                );
            }

            return $result;
        }
    }

    /**
     * Keep consecutive write requests at least WRITE_THROTTLE_SECONDS apart so a bulk
     * sync stays inside the 10 writes/minute budget instead of tripping 429 repeatedly.
     */
    private function throttleWrite(): void
    {
        $elapsed = microtime(true) - self::$lastWriteAt;
        if (self::$lastWriteAt > 0.0 && $elapsed < self::WRITE_THROTTLE_SECONDS) {
            usleep((int) round((self::WRITE_THROTTLE_SECONDS - $elapsed) * 1_000_000));
        }

        self::$lastWriteAt = microtime(true);
    }

    /** Prefer the Vietnamese `message` the API returns; fall back to `error`, then the status. */
    private function describe(array $body, int $statusCode): string
    {
        foreach (['message', 'error'] as $key) {
            if (!empty($body[$key]) && is_string($body[$key])) {
                return $body[$key];
            }
        }

        return __('plugins.generic.nastis.error.unexpectedHttp', ['status' => $statusCode]);
    }
}
