{extends file="layouts/backend.tpl"}
{block name="page"}
    <div class="nastisPage">
        <h1 class="app__pageHeading">
            {translate key="plugins.generic.nastis.navigation.nastis"}
        </h1>

        <tabs :track-history="true">
            <tab id="submissions" label="{translate key="plugins.generic.nastis.tab.submissions"}">
                <div class="nastisLayout">
                    <aside class="nastisSidebar pkp_controllers_grid pkp_grid_wrapper">
                        <form id="nastisFilterForm" class="pkp_form" action="{url page="nastis"}#submissions" method="get">
                            <input type="hidden" name="page" value="1">
                            <input type="hidden" name="count" value="{$nastisCount|escape}">
                            <div class="nastisFilters">
                                <div class="nastisSidebar__title">{translate key="common.filter"}</div>

                                <div class="nastisFilters__search">
                                    <label class="nastisFilters__label" for="nastisSearchPhrase">{translate key="common.search"}</label>
                                    <input
                                        id="nastisSearchPhrase"
                                        class="defaultTextField"
                                        type="text"
                                        name="searchPhrase"
                                        value="{$nastisSearchPhrase|escape}"
                                        placeholder="{translate key="common.search"}"
                                    />
                                </div>

                                <div class="nastisFilters__group nastisFilters__group--stack">
                                    <span class="nastisFilters__label">{translate key="plugins.generic.nastis.filter.syncStatus"}</span>
                                    <label class="nastisCheckbox">
                                        <input type="checkbox" name="nastisStatus[]" value="notSynced"{if in_array('notSynced', $nastisSelectedStatuses)} checked{/if}>
                                        <span>{translate key="plugins.generic.nastis.status.notSynced"}</span>
                                    </label>
                                    <label class="nastisCheckbox">
                                        <input type="checkbox" name="nastisStatus[]" value="received"{if in_array('received', $nastisSelectedStatuses)} checked{/if}>
                                        <span>{translate key="plugins.generic.nastis.status.received"}</span>
                                    </label>
                                    <label class="nastisCheckbox">
                                        <input type="checkbox" name="nastisStatus[]" value="needs-correction"{if in_array('needs-correction', $nastisSelectedStatuses)} checked{/if}>
                                        <span>{translate key="plugins.generic.nastis.status.needsCorrection"}</span>
                                    </label>
                                    <label class="nastisCheckbox">
                                        <input type="checkbox" name="nastisStatus[]" value="accepted-by-ministry"{if in_array('accepted-by-ministry', $nastisSelectedStatuses)} checked{/if}>
                                        <span>{translate key="plugins.generic.nastis.status.accepted"}</span>
                                    </label>
                                </div>

                                {if $nastisIssueOptions}
                                    <div class="nastisFilters__search">
                                        <label class="nastisFilters__label" for="nastisIssue">{translate key="issue.issue"}</label>
                                        <select id="nastisIssue" class="selectMenu" name="nastisIssue[]">
                                            <option value="">{translate key="common.all"}</option>
                                            {foreach from=$nastisIssueOptions item=issueOption}
                                                <option value="{$issueOption.value|escape}"{if in_array($issueOption.value, $nastisSelectedIssues)} selected{/if}>
                                                    {$issueOption.label|escape}
                                                </option>
                                            {/foreach}
                                        </select>
                                    </div>
                                {/if}

                                <div class="nastisFilters__actions nastisFilters__actions--stack">
                                    <button class="nastisButton nastisButton--primary" type="submit">{translate key="common.search"}</button>
                                    <a class="nastisButton nastisButton--secondary" href="{url page="nastis"}">{translate key="common.reset"}</a>
                                </div>
                            </div>
                        </form>
                    </aside>

                    <section class="nastisMain">
                        <form id="nastisSyncForm" class="pkp_form" action="{url page="nastis" op="sync"}#submissions" method="post">
                            {csrf}
                            <input type="hidden" name="page" value="{$nastisPage|escape}">
                            <input type="hidden" name="count" value="{$nastisCount|escape}">
                            {if $nastisSearchPhrase}
                                <input type="hidden" name="searchPhrase" value="{$nastisSearchPhrase|escape}">
                            {/if}
                            {foreach from=$nastisSelectedStatuses item=nastisSelectedStatus}
                                <input type="hidden" name="nastisStatus[]" value="{$nastisSelectedStatus|escape}">
                            {/foreach}
                            {foreach from=$nastisSelectedIssues item=nastisSelectedIssue}
                                <input type="hidden" name="nastisIssue[]" value="{$nastisSelectedIssue|escape}">
                            {/foreach}

                            <div class="pkp_controllers_grid pkp_grid_wrapper">
                                <div class="nastisTableHeader">
                                    <div class="nastisTableHeader__title">
                                        {translate key="plugins.generic.nastis.tab.submissions"}
                                        <span class="nastisTableHeader__meta">{$nastisItems|@count} / {$nastisItemsMax|escape}</span>
                                    </div>
                                    <div class="nastisTableHeader__actions">
                                        <button id="nastisSelectAllButton" class="nastisButton nastisButton--secondary" type="button">
                                            {translate key="plugins.generic.nastis.action.selectAll"}
                                        </button>
                                        <button class="nastisButton nastisButton--primary" type="submit">{translate key="plugins.generic.nastis.action.syncSelected"}</button>
                                    </div>
                                </div>

                                <table class="nastisTable w-full max-w-full border-separate border-spacing-0">
                            <thead>
                                <tr class="bg bg-default">
                                    <th scope="col" class="nastisTable__checkboxHeader whitespace-nowrap border-b border-t border-light px-2 py-4 text-start text-base-normal uppercase text-heading first:border-s first:ps-3 last:border-e last:pe-3"></th>
                                    <th scope="col" class="whitespace-nowrap border-b border-t border-light px-2 py-4 text-start text-base-normal uppercase text-heading first:border-s first:ps-3 last:border-e last:pe-3" style="width: 60px;">ID</th>
                                    <th scope="col" class="whitespace-nowrap border-b border-t border-light px-2 py-4 text-start text-base-normal uppercase text-heading first:border-s first:ps-3 last:border-e last:pe-3">{translate key="common.title"}</th>
                                    <th scope="col" class="nastisTable__statusHeader whitespace-nowrap border-b border-t border-light px-2 py-4 text-start text-base-normal uppercase text-heading first:border-s first:ps-3 last:border-e last:pe-3">{translate key="common.status"}</th>
                                    <th scope="col" class="nastisTable__detailsHeader whitespace-nowrap border-b border-t border-light px-2 py-4 text-start text-base-normal uppercase text-heading first:border-s first:ps-3 last:border-e last:pe-3">{translate key="common.details"}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {foreach from=$nastisItems item=item}
                                    {assign var=publication value=null}
                                    {foreach from=$item.publications item=publicationItem}
                                        {if $publicationItem.id == $item.currentPublicationId}
                                            {assign var=publication value=$publicationItem}
                                        {/if}
                                    {/foreach}
                                    {assign var=statusValue value=$item.nastisLastStatus|default:'notSynced'}
                                    {assign var=statusClass value='nastisBadge--neutral'}
                                    {if $statusValue == 'received' || $statusValue == 'accepted-by-ministry'}
                                        {assign var=statusClass value='nastisBadge--success'}
                                    {elseif $statusValue == 'needs-correction'}
                                        {assign var=statusClass value='nastisBadge--warning'}
                                    {/if}
                                    <tr class="nastisTable__row border-separate border border-light even:bg-tertiary">
                                        <td class="border-b border-light px-2 py-2 text-start text-base-normal first:border-s first:ps-3 last:border-e last:pe-3">
                                            <input type="checkbox" name="selectedSubmissions[]" value="{$item.id|escape}">
                                        </td>
                                        <td class="nastisTable__mono border-b border-light px-2 py-2 text-start text-base-normal first:border-s first:ps-3 last:border-e last:pe-3">{$item.id|escape}</td>
                                        <td class="nastisTable__titleCell border-b border-light px-2 py-2 text-start text-base-normal first:border-s first:ps-3 last:border-e last:pe-3">
                                            <a class="nastisTable__title" href="{$item.urlWorkflow|escape}" target="_blank" rel="noopener noreferrer">
                                                {$publication.fullTitle|default:'-'|escape}
                                            </a>
                                            {if $publication.authorsStringShort || $publication.issueLabel}
                                                <div class="nastisTable__metaLine">
                                                    {$publication.authorsStringShort|default:''|escape}
                                                    {if $publication.authorsStringShort && $publication.issueLabel}
                                                        <span class="nastisTable__metaSeparator">&bull;</span>
                                                    {/if}
                                                    {if $publication.issueLabel}
                                                        <span class="nastisTable__metaIssue">{$publication.issueLabel|escape}</span>
                                                    {/if}
                                                </div>
                                            {/if}
                                        </td>
                                        <td class="nastisTable__statusCell border-b border-light px-2 py-2 text-start text-base-normal first:border-s first:ps-3 last:border-e last:pe-3">
                                            <span class="nastisBadge {$statusClass}">
                                                {$item.nastisStatusLabel|default:{translate key="plugins.generic.nastis.status.notSynced"}|escape}
                                            </span>
                                        </td>
                                        <td class="nastisTable__detailsCell border-b border-light px-2 py-2 text-start text-base-normal first:border-s first:ps-3 last:border-e last:pe-3">
                                            <button
                                                class="nastisTable__detailsToggle"
                                                type="button"
                                                data-details-toggle="nastis-details-{$item.id|escape}"
                                                aria-expanded="false"
                                                aria-controls="nastis-details-{$item.id|escape}"
                                            >
                                                {translate key="common.details"}
                                            </button>
                                        </td>
                                    </tr>
                                    <tr id="nastis-details-{$item.id|escape}" class="nastisTable__expandedRow" hidden>
                                        <td colspan="5" class="nastisTable__expandedCell">
                                            <div class="nastisTable__detailsBody listPanel__itemExpanded">
                                                <div class="nastisTable__detailRow">
                                                    <span class="nastisTable__detailLabel">externalArticleId</span>
                                                    <span class="nastisTable__detailValue nastisTable__mono">{$item.nastisExternalArticleId|default:'-'|escape}</span>
                                                </div>
                                                <div class="nastisTable__detailRow">
                                                    <span class="nastisTable__detailLabel">{translate key="plugins.generic.nastis.tab.lastSyncedAt"}</span>
                                                    <span class="nastisTable__detailValue">
                                                        {if $item.nastisLastSyncedAt}
                                                            {$item.nastisLastSyncedAt|date_format:"H:i d/m/Y"}
                                                        {else}
                                                            -
                                                        {/if}
                                                    </span>
                                                </div>
                                                <div class="nastisTable__detailRow">
                                                    <span class="nastisTable__detailLabel">{translate key="common.error"}</span>
                                                    <span class="nastisTable__detailValue nastisTable__error">{$item.nastisLastError|default:'-'|escape}</span>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                {foreachelse}
                                    <tr>
                                        <td colspan="5" class="nastisTable__empty">{translate key="plugins.generic.nastis.search.noResults"}</td>
                                    </tr>
                                {/foreach}
                            </tbody>
                                </table>
                            </div>
                        </form>
                        {if $nastisPageCount > 1}
                            <form id="nastisPaginationForm" class="pkp_form" action="{url page="nastis"}#submissions" method="get">
                                <input id="nastisPageInput" type="hidden" name="page" value="{$nastisPage|escape}">
                                <input type="hidden" name="count" value="{$nastisCount|escape}">
                                {if $nastisSearchPhrase}
                                    <input type="hidden" name="searchPhrase" value="{$nastisSearchPhrase|escape}">
                                {/if}
                                {foreach from=$nastisSelectedStatuses item=nastisSelectedStatus}
                                    <input type="hidden" name="nastisStatus[]" value="{$nastisSelectedStatus|escape}">
                                {/foreach}
                                {foreach from=$nastisSelectedIssues item=nastisSelectedIssue}
                                    <input type="hidden" name="nastisIssue[]" value="{$nastisSelectedIssue|escape}">
                                {/foreach}

                                <div class="nastisTableHeader nastisTableHeader--pagination">
                                    <div class="nastisTableHeader__title">
                                        <span class="nastisTableHeader__meta">
                                            {translate key="plugins.generic.nastis.search.showingResults" shown=$nastisItems|@count total=$nastisItemsMax}
                                        </span>
                                    </div>
                                    <div class="nastisTableHeader__actions">
                                        <button
                                            class="nastisButton nastisButton--secondary"
                                            type="button"
                                            onclick="document.getElementById('nastisPageInput').value='{$nastisPage-1}'; document.getElementById('nastisPaginationForm').submit();"
                                            {if $nastisPage <= 1}disabled{/if}
                                        >
                                            {translate key="plugins.generic.nastis.pagination.prev"}
                                        </button>
                                        {section name=pageLoop start=1 loop=$nastisPageCount+1 step=1}
                                            {assign var=currentPageNumber value=$smarty.section.pageLoop.index}
                                            <button
                                                class="nastisButton {if $currentPageNumber == $nastisPage}nastisButton--primary{else}nastisButton--secondary{/if}"
                                                type="button"
                                                onclick="document.getElementById('nastisPageInput').value='{$currentPageNumber}'; document.getElementById('nastisPaginationForm').submit();"
                                            >
                                                {$currentPageNumber}
                                            </button>
                                        {/section}
                                        <button
                                            class="nastisButton nastisButton--secondary"
                                            type="button"
                                            onclick="document.getElementById('nastisPageInput').value='{$nastisPage+1}'; document.getElementById('nastisPaginationForm').submit();"
                                            {if $nastisPage >= $nastisPageCount}disabled{/if}
                                        >
                                            {translate key="plugins.generic.nastis.pagination.next"}
                                        </button>
                                    </div>
                                </div>
                            </form>
                        {/if}
                    </section>
                </div>
            </tab>
            <tab id="overview" label="{translate key="plugins.generic.nastis.tab.about"}">
                <div class="nastisOverview">
                    <div class="nastisOverview__grid">
                        <div class="nastisOverview__card">
                            <div class="nastisOverview__label">{translate key="plugins.generic.nastis.settings.baseUrl"}</div>
                            <div class="nastisOverview__value">{$nastisBaseUrl|escape}</div>
                        </div>
                        <div class="nastisOverview__card">
                            <div class="nastisOverview__label">{translate key="plugins.generic.nastis.settings.journalCode"}</div>
                            <div class="nastisOverview__value">{$nastisJournalCode|escape}</div>
                        </div>
                        <div class="nastisOverview__card">
                            <div class="nastisOverview__label">{translate key="plugins.generic.nastis.tab.submissions"}</div>
                            <div class="nastisOverview__value">{$nastisItemsMax|escape}</div>
                        </div>
                    </div>

                    <div class="nastisOverview__card">
                        <div class="nastisOverview__label">{translate key="plugins.generic.nastis.settings.author"}</div>
                        <div class="nastisOverview__author">
                            <div class="nastisOverview__authorText">
                                <div class="nastisOverview__value">
                                <span class="inline-block align-middle rtl:scale-x-[-1] h-5 w-5">
                                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M1.087 10.25H10.413L5.75 20.25L1.087 10.25Z" fill="currentColor"/>
                                        <path d="M14.144 2.25H21.75L13.356 20.25H5.75L14.144 2.25Z" fill="currentColor"/>
                                    </svg>
                                </span>
                                {translate key="plugins.generic.nastis.settings.authorName"}</div>
                                <div class="nastisOverview__subvalue">{translate key="plugins.generic.nastis.settings.authorAffiliation"}</div>
                            </div>
                            <a class="nastisOverview__link" href="https://tcsuckhoelaohoa.vn/" target="_blank" rel="noopener noreferrer">
                                <svg class="nastisOverview__linkIcon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                    <path d="M14 3h7v7h-2V6.41l-9.29 9.3-1.42-1.42 9.3-9.29H14V3z"></path>
                                    <path d="M5 5h6v2H7v10h10v-4h2v6H5V5z"></path>
                                </svg>
                                <span>{translate key="plugins.generic.nastis.settings.journalWebsite"}</span>
                            </a>
                        </div>
                    </div>

                    <div class="nastisOverview__card">
                        <div class="nastisOverview__label">{translate key="plugins.generic.nastis.tab.syncBehavior"}</div>
                        <div class="nastisOverview__list">
                            {if $nastisAutoSyncOnPublish}<span class="nastisChip">{translate key="plugins.generic.nastis.settings.autoSyncOnPublish"}</span>{/if}
                            {if $nastisAutoSyncOnEdit}<span class="nastisChip">{translate key="plugins.generic.nastis.settings.autoSyncOnEdit"}</span>{/if}
                            {if $nastisUploadPdf}<span class="nastisChip">{translate key="plugins.generic.nastis.settings.uploadPdf"}</span>{/if}
                        </div>
                    </div>
                </div>

                {if $nastisResults}
                    <div class="pkp_controllers_grid pkp_grid_wrapper" style="margin-top: 1rem;">
                        <table class="pkpTable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>{translate key="common.status"}</th>
                                    <th>{translate key="stageParticipants.notify.message"}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {foreach from=$nastisResults item=result}
                                    <tr>
                                        <td>{$result.submissionId|escape}</td>
                                        <td>{if $result.success}{translate key="common.completed"}{else}{translate key="common.error"}{/if}</td>
                                        <td>{$result.message|escape}</td>
                                    </tr>
                                {/foreach}
                            </tbody>
                        </table>
                    </div>
                {/if}
            </tab>
        </tabs>
    </div>
    {literal}
    <script>
        (function () {
            document.addEventListener('click', function (event) {
                if (!event.target || event.target.id !== 'nastisSelectAllButton') {
                    var toggle = event.target && event.target.closest('[data-details-toggle]');
                    if (!toggle) {
                        return;
                    }

                    var targetId = toggle.getAttribute('data-details-toggle');
                    var expandedRow = document.getElementById(targetId);
                    if (!expandedRow) {
                        return;
                    }

                    var isExpanded = toggle.getAttribute('aria-expanded') === 'true';
                    toggle.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
                    expandedRow.hidden = isExpanded;
                    expandedRow.classList.toggle('is-visible', !isExpanded);
                    return;
                }

                document.querySelectorAll('#nastisSyncForm input[name="selectedSubmissions[]"]').forEach(function (el) {
                    el.checked = true;
                });
            });
        })();
    </script>
    {/literal}
{/block}
