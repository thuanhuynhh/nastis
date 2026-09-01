<?php

namespace APP\plugins\generic\nastis\classes\services;

use APP\facades\Repo;
use APP\publication\Publication;
use APP\submission\Submission;
use PKP\author\Author;
use PKP\db\DAORegistry;
use PKP\decision\Decision;

/**
 * Builds the VJOL ingest payload (spec v1.3 section 6.2) out of an OJS submission.
 */
class NastisMapper
{
    /** Spec 4.1: externalArticleId is capped at 255 characters. */
    private const MAX_EXTERNAL_ARTICLE_ID = 255;

    public function buildPayload(Submission $submission, Publication $publication, array $settings): array
    {
        $contextId = (int) $submission->getData('contextId');
        $context = app()->get('context')->get($contextId);
        $issue = $publication->getData('issueId')
            ? Repo::issue()->get((int) $publication->getData('issueId'))
            : null;

        $locale = $this->shortLocale(
            $publication->getData('locale')
                ?: $submission->getData('locale')
                ?: $context->getPrimaryLocale()
        );

        $authors = $this->mapAuthors($publication);

        $article = [
            'locale' => $locale,
            'title' => $this->normalizeMultilingual($publication->getData('title')),
            'abstract' => $this->normalizeMultilingual($publication->getData('abstract')),
            'publisherId' => $this->stringOrNull($publication->getData('pub-id::publisher-id')),
            'doi' => $this->stringOrNull($publication->getDoi()),
            'dateAcceptedAtSource' => $this->resolveDateAccepted($submission),
            'datePublishedAtSource' => $this->normalizeDate(
                $publication->getData('datePublished') ?: $issue?->getDatePublished()
            ),
            'keywords' => $this->mapKeywords($publication, $locale),
            'pages' => $this->stringOrNull($publication->getData('pages')),
            'citations' => $this->mapCitations($publication),
        ];

        $payload = [
            'sourceJournal' => [
                'journalCode' => trim((string) ($settings['journalCode'] ?? '')),
                'journalName' => $this->resolveJournalName($context, $locale),
            ],
            'externalArticleId' => $this->buildExternalArticleId($submission, (string) ($settings['journalCode'] ?? '')),
            'externalSubmissionId' => (string) $submission->getId(),
            'primaryContactSeq' => $this->resolvePrimaryContactSeq($publication, $authors),
            'article' => $this->pruneEmpty($article),
            'section' => $this->mapSection($publication, $contextId),
            'issue' => $this->mapIssue($issue, $article['datePublishedAtSource']),
            'authors' => $authors,
            'sourceWorkflow' => $this->mapSourceWorkflow($submission),
            'sourceSystem' => 'ojs',
        ];

        return $this->sortRecursive($this->pruneEmpty($payload));
    }

    /**
     * Spec 4.1: the identifier MUST be `{journalCode}-{submissionId}` and MUST start with
     * the journalCode bound to the credential, otherwise the ingest server rejects it.
     */
    public function buildExternalArticleId(Submission $submission, string $journalCode): string
    {
        $id = trim($journalCode) . '-' . $submission->getId();

        return mb_substr($id, 0, self::MAX_EXTERNAL_ARTICLE_ID);
    }

    /**
     * Spec 7: a partial update only needs the journal code, the identifier, and whatever
     * actually changed — but sending the full article/author/issue picture keeps the
     * ministry copy in step with OJS after any edit.
     */
    public function buildUpdatePayload(array $payload): array
    {
        $update = $payload;
        $update['sourceJournal'] = ['journalCode' => $payload['sourceJournal']['journalCode'] ?? ''];

        return $update;
    }

    /**
     * Authors are re-sequenced 1..n in OJS display order. Spec 4.4 requires
     * primaryContactSeq to match one of these values, so both must be derived together.
     */
    private function mapAuthors(Publication $publication): array
    {
        $authorCollection = $publication->getData('authors');
        if (!$authorCollection) {
            return [];
        }

        $ordered = collect($authorCollection)
            ->sortBy(fn (Author $author) => (int) $author->getData('seq'))
            ->values();

        $authors = [];
        $seq = 1;

        foreach ($ordered as $author) {
            /** @var Author $author */
            $givenName = $this->normalizeMultilingual($author->getData('givenName'));
            $familyName = $this->normalizeMultilingual($author->getData('familyName'));
            $preferredPublicName = $this->normalizeMultilingual($author->getData('preferredPublicName'))
                ?: $this->buildPreferredPublicName($givenName, $familyName);

            $optional = $this->pruneEmpty([
                'givenName' => $givenName,
                'familyName' => $familyName,
                'preferredPublicName' => $preferredPublicName,
                'email' => $this->stringOrNull($author->getData('email')),
                'country' => $this->stringOrNull($author->getData('country')),
            ]);

            // seq and includeInBrowse are always sent: false is meaningful for the
            // latter, so neither may be dropped by pruneEmpty().
            $authors[] = $optional + [
                'seq' => $seq,
                'includeInBrowse' => (bool) $author->getData('includeInBrowse'),
            ];

            $seq++;
        }

        return $authors;
    }

    /** Spec 4.4: seq of the contact author, defaulting to the lowest seq when unset. */
    private function resolvePrimaryContactSeq(Publication $publication, array $authors): ?int
    {
        if (!$authors) {
            return null;
        }

        $primaryContactId = (int) $publication->getData('primaryContactId');
        if ($primaryContactId) {
            $ordered = collect($publication->getData('authors') ?: [])
                ->sortBy(fn (Author $author) => (int) $author->getData('seq'))
                ->values();

            foreach ($ordered as $index => $author) {
                if ((int) $author->getId() === $primaryContactId) {
                    return $index + 1;
                }
            }
        }

        return (int) $authors[0]['seq'];
    }

    /**
     * Spec 10: since v1.3 the server resolves the section by title (case-insensitive)
     * and creates it when missing, so the section name is all that needs sending.
     */
    private function mapSection(Publication $publication, int $contextId): ?array
    {
        $sectionId = (int) $publication->getData('sectionId');
        if (!$sectionId) {
            return null;
        }

        $section = Repo::section()->get($sectionId, $contextId);
        if (!$section) {
            return null;
        }

        return $this->pruneEmpty([
            'title' => $this->stringOrNull($section->getLocalizedTitle()),
            'abbrev' => $this->stringOrNull($section->getLocalizedAbbrev()),
        ]) ?: null;
    }

    /** Spec 6.3: volume, number and year are all required for the issue group. */
    private function mapIssue($issue, ?string $datePublished): ?array
    {
        if (!$issue) {
            return null;
        }

        $year = (int) $issue->getYear();
        if (!$year && $datePublished) {
            $year = (int) substr($datePublished, 0, 4);
        }

        $mapped = [
            'volume' => $this->stringOrNull($issue->getVolume()),
            'number' => $this->stringOrNull($issue->getNumber()),
            'year' => $year ?: null,
        ];

        return $this->pruneEmpty($mapped) ?: null;
    }

    /**
     * Spec 6.3 accepts a plain array of strings, which is assigned to the default
     * locale. OJS stores keywords per locale, so the article locale is used.
     */
    private function mapKeywords(Publication $publication, string $locale): array
    {
        $keywords = $publication->getData('keywords');
        if (!is_array($keywords) || !$keywords) {
            return [];
        }

        $byShortLocale = [];
        foreach ($keywords as $keywordLocale => $values) {
            $short = $this->shortLocale((string) $keywordLocale);
            foreach ((array) $values as $value) {
                $value = trim((string) $value);
                if ($value !== '') {
                    $byShortLocale[$short][] = $value;
                }
            }
        }

        if (!$byShortLocale) {
            return [];
        }

        $selected = $byShortLocale[$locale] ?? reset($byShortLocale);

        return array_values(array_unique($selected));
    }

    /** Spec 6.3: one array element per reference; each becomes one citations row. */
    private function mapCitations(Publication $publication): array
    {
        $citations = [];

        try {
            /** @var \PKP\citation\CitationDAO $citationDao */
            $citationDao = DAORegistry::getDAO('CitationDAO');
            foreach ($citationDao->getByPublicationId((int) $publication->getId()) as $citation) {
                $raw = trim((string) $citation->getRawCitation());
                if ($raw !== '') {
                    $citations[] = $raw;
                }
            }
        } catch (\Throwable) {
            // Fall through to the raw citation blob below.
        }

        if ($citations) {
            return $citations;
        }

        $raw = (string) $publication->getData('citationsRaw');
        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/\R+/u', $raw) ?: []),
            fn (string $line) => $line !== ''
        ));
    }

    /**
     * Spec 6.3: provenance about the journal's own review process. Derived from the
     * real editorial record rather than hard-coded, so the ministry copy is accurate.
     */
    private function mapSourceWorkflow(Submission $submission): array
    {
        $accepted = false;

        try {
            $accepted = Repo::decision()->getCollector()
                ->filterBySubmissionIds([(int) $submission->getId()])
                ->filterByDecisionTypes([Decision::ACCEPT, Decision::SEND_TO_PRODUCTION])
                ->getCount() > 0;
        } catch (\Throwable) {
            // Leave $accepted false when the decision history cannot be read.
        }

        $peerReviewCompleted = false;

        try {
            // filterByPublished(), not filterByCompleted(): the latter excludes
            // published submissions outright (Collector::getQueryBuilder adds
            // `s.status <> STATUS_PUBLISHED`), and everything synced here is published.
            $peerReviewCompleted = Repo::reviewAssignment()->getCollector()
                ->filterBySubmissionIds([(int) $submission->getId()])
                ->filterByPublished(true)
                ->getCount() > 0;
        } catch (\Throwable) {
            // Leave $peerReviewCompleted false when review assignments cannot be read.
        }

        return [
            'peerReviewCompleted' => $peerReviewCompleted,
            'editorDecision' => $accepted ? 'accepted' : 'published',
        ];
    }

    /**
     * Prefer the date of the actual accept decision; fall back to the status change
     * that moved the submission out of the queue.
     */
    private function resolveDateAccepted(Submission $submission): ?string
    {
        try {
            $decision = Repo::decision()->getCollector()
                ->filterBySubmissionIds([(int) $submission->getId()])
                ->filterByDecisionTypes([Decision::ACCEPT])
                ->getMany()
                ->sortBy(fn ($decision) => (string) $decision->getData('dateDecided'))
                ->first();

            if ($decision && $decision->getData('dateDecided')) {
                return $this->normalizeDate((string) $decision->getData('dateDecided'));
            }
        } catch (\Throwable) {
            // Fall through to the submission timestamps below.
        }

        return $this->normalizeDate(
            $submission->getData('dateStatusModified') ?: $submission->getData('lastModified')
        );
    }

    private function resolveJournalName($context, string $locale): string
    {
        $name = (string) ($context->getName($locale) ?: '');
        if ($name !== '') {
            return $name;
        }

        return (string) ($context->getLocalizedName() ?: $context->getPath());
    }

    private function buildPreferredPublicName(array $givenName, array $familyName): array
    {
        $locales = array_unique(array_merge(array_keys($givenName), array_keys($familyName)));
        $names = [];

        foreach ($locales as $locale) {
            $name = trim(($givenName[$locale] ?? '') . ' ' . ($familyName[$locale] ?? ''));
            if ($name !== '') {
                $names[$locale] = $name;
            }
        }

        return $names;
    }

    /**
     * Spec 2.3 expects short locale keys such as "vi" and "en"; OJS may hold
     * region-qualified keys like "vi_VN". The first value wins on collision.
     */
    private function normalizeMultilingual($values): array
    {
        if (is_string($values)) {
            $values = trim($values);
            return $values === '' ? [] : ['und' => $values];
        }

        if (!is_array($values)) {
            return [];
        }

        $normalized = [];
        foreach ($values as $locale => $value) {
            if (is_array($value)) {
                continue;
            }

            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            $short = $this->shortLocale((string) $locale);
            if (!array_key_exists($short, $normalized)) {
                $normalized[$short] = $value;
            }
        }

        return $normalized;
    }

    private function shortLocale(string $locale): string
    {
        $locale = trim($locale);
        if ($locale === '') {
            return 'en';
        }

        return strtolower(preg_split('/[_@-]/', $locale)[0]);
    }

    private function normalizeDate(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $date = substr(trim($value), 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
    }

    private function stringOrNull($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** Drop nulls and empty arrays/strings so optional fields are omitted rather than sent as null. */
    private function pruneEmpty(array $data): array
    {
        return array_filter(
            $data,
            fn ($value) => $value !== null && $value !== '' && $value !== []
        );
    }

    private function sortRecursive(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sortRecursive($value);
            }
        }

        if (array_is_list($data)) {
            return $data;
        }

        ksort($data);

        return $data;
    }
}
