# Nastis

[Tiếng Việt](README.vi.md)

An OJS 3.5 generic plugin that sends published article metadata and PDF galleys
to the Vietnam Journals Online (VJOL) ingest API at
`https://vjol.vista.gov.vn`.

## Install

Copy this folder to `plugins/generic/nastis` in your OJS installation, then
enable **Nastis** under Settings > Website > Plugins.

## Settings

Open the plugin settings and fill in:

- Base URL of the ingest server
- Journal code issued by the ministry, which also prefixes every article id
- Client ID, sent as `x-client-id`
- API key, sent as `x-api-key` and stored encrypted
- whether to sync automatically on publish and on edit, and whether to upload the PDF

Press **Test connection** before saving. It calls `GET /health` and then an
authenticated read, so it tells you whether the server is unreachable, the
credentials were rejected (`AUTH_INVALID`), or the credentials do not cover the
journal code you entered (`JOURNAL_MISMATCH`).

## What it does

The plugin adds a **Nastis** page to the editorial menu that lists published
submissions with their sync status and lets you sync them one by one, and it
shows the same status inside the submission workflow.

Requests it makes to the ingest API:

| Event | Request |
| --- | --- |
| First delivery | `POST /api/ingest/v1/articles` as `multipart/form-data`, metadata as a JSON part plus the file |
| Metadata changed later | `PUT /api/ingest/v1/articles/{externalArticleId}` |
| File added or replaced | `POST /api/ingest/v1/articles/{externalArticleId}/files` |
| Status read back | `GET /api/ingest/v1/articles/{externalArticleId}/status` |

It also exposes two OJS endpoints for managers, sub-editors and assistants:
`PUT /api/v1/submissions/{submissionId}/nastis/sync` and
`GET /api/v1/submissions/{submissionId}/nastis/status`.

If a create returns `409 PAYLOAD_CONFLICT`, the plugin resends the payload as a
`PUT` (spec 12.3).

A failed automatic sync never blocks publishing or editing. The plugin records
the error on the submission (`nastisLastError`), in the event log, and in the
PHP error log.

## Limits

The API accepts 10 write requests per minute, so the client waits at least 7
seconds between writes and retries a `429 RATE_LIMITED` twice. Syncing many
submissions takes a while.

Files must be PDF and no larger than 50 MB (spec 3.5).

## Article ids

Spec 4.1 requires the external id to be `{journalCode}-{submissionId}` and to
start with the journal code bound to the credential, for example:

```
vjol-121-tap-chi-suc-khoe-va-lao-hoa-155
```

Once the server accepts an id it never changes. The plugin reuses the stored
value, and regenerates it only when it no longer matches the configured journal
code, because the server could not have accepted an id with the wrong prefix.

## Requirements

- OJS 3.5.0-x
- PHP able to verify the ingest server's TLS chain, with `curl.cainfo` or
  `openssl.cafile` pointing at a current CA bundle. Without it every sync fails
  with `TRANSPORT_ERROR` and `cURL error 60`.
