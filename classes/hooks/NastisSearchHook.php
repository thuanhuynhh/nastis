<?php

namespace APP\plugins\generic\nastis\classes\hooks;

use APP\core\Application;
use Illuminate\Database\Query\Builder;

class NastisSearchHook
{
    public function filterSubmissionQuery(string $hookName, array $args): void
    {
        /** @var Builder $query */
        $query = $args[0];
        $collector = $args[1];
        $request = Application::get()->getRequest();

        if (!$request->getContext() || !$request->getUserVar('nastisSearch')) {
            return;
        }

        $searchPhrase = trim((string) ($collector->searchPhrase ?? ''));
        if ($searchPhrase === '') {
            return;
        }

        $keywords = preg_split('/\s+/u', mb_strtolower($searchPhrase, 'UTF-8'));
        $keywords = array_values(array_filter($keywords, fn ($keyword) => $keyword !== ''));

        if (!$keywords) {
            return;
        }

        $query->whereExists(function (Builder $subquery) use ($keywords) {
            $subquery->selectRaw('1')
                ->from('publications as nastis_publication')
                ->join('publication_settings as nastis_title', function ($join) {
                    $join->on('nastis_publication.publication_id', '=', 'nastis_title.publication_id')
                        ->where('nastis_title.setting_name', '=', 'title');
                })
                ->whereColumn('nastis_publication.publication_id', 's.current_publication_id');

            foreach ($keywords as $keyword) {
                $subquery->whereRaw('LOWER(COALESCE(nastis_title.setting_value, \'\')) LIKE ?', ['%' . $keyword . '%']);
            }
        });
    }
}
