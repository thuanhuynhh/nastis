<?php

namespace APP\plugins\generic\nastis\classes\services;

/**
 * Carries the VJOL ingest API error envelope described in section 12 of the
 * integration specification, so callers can branch on `code` instead of
 * re-parsing the HTTP status or the human readable message.
 */
class NastisApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        private string $apiCode,
        private int $statusCode,
        private array $body = []
    ) {
        parent::__construct($message, $statusCode);
    }

    public function getApiCode(): string
    {
        return $this->apiCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getBody(): array
    {
        return $this->body;
    }

    public function isConflict(): bool
    {
        return $this->apiCode === NastisApiClient::CODE_PAYLOAD_CONFLICT;
    }

    public function isNotFound(): bool
    {
        return $this->apiCode === NastisApiClient::CODE_NOT_FOUND;
    }
}
