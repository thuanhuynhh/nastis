<?php

namespace APP\plugins\generic\nastis\classes\schema;

class NastisSchema
{
    /**
     * Per-property `apiSummary`: the identifier, status, error and timestamp are small
     * enough to travel with every submission list, but nastisLastResponse holds the full
     * ingest envelope (up to 65 KB) and is only fetched on demand.
     */
    private const PROPERTIES = [
        'nastisExternalArticleId' => true,
        'nastisLastHash' => false,
        'nastisLastStatus' => true,
        'nastisLastResponse' => false,
        'nastisLastError' => true,
        'nastisLastSyncedAt' => true,
        'nastisLastFileRef' => false,
    ];

    public function addToSubmissionSchema($hookName, $args)
    {
        $schema = &$args[0];

        foreach (self::PROPERTIES as $name => $apiSummary) {
            $schema->properties->{$name} = (object) [
                'type' => 'string',
                'apiSummary' => $apiSummary,
                'validation' => ['nullable'],
            ];
        }

        return false;
    }

    public function addToSubmissionsListProps($hookName, $args)
    {
        $props = &$args[0];

        foreach ([
            'nastisExternalArticleId',
            'nastisLastStatus',
            'nastisLastError',
            'nastisLastSyncedAt',
        ] as $prop) {
            $props[] = $prop;
        }

        return false;
    }
}
