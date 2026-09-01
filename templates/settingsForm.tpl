{**
 * plugins/generic/nastis/templates/settingsForm.tpl
 *}

<script>
	$(function() {ldelim}
		$('#nastisSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');

		$('#nastisTestConnection').on('click', function() {ldelim}
			var $button = $(this);
			var $result = $('#nastisTestResult');
			var $form = $('#nastisSettingsForm');

			$button.prop('disabled', true);
			$result.removeClass('is-ok is-error').text($button.data('runningLabel'));

			// Test what is currently typed in the form, so credentials can be
			// verified before they are saved.
			$.post($button.data('testUrl'), {ldelim}
				csrfToken: $form.find('input[name="csrfToken"]').val(),
				baseUrl: $form.find('#baseUrl').val(),
				journalCode: $form.find('#journalCode').val(),
				clientId: $form.find('#clientId').val(),
				apiKey: $form.find('#apiKey').val()
			{rdelim}, null, 'json')
				.done(function(response) {ldelim}
					var content = response && response.content;
					if (!content || typeof content !== 'object') {ldelim}
						$result.addClass('is-error').text(String(content || response.status));
						return;
					{rdelim}
					$result
						.addClass(content.ok ? 'is-ok' : 'is-error')
						.text(content.message + (content.code ? ' [' + content.code + ']' : ''));
				{rdelim})
				.fail(function(xhr) {ldelim}
					$result.addClass('is-error').text(xhr.statusText || 'Error');
				{rdelim})
				.always(function() {ldelim}
					$button.prop('disabled', false);
				{rdelim});
		{rdelim});
	{rdelim});
</script>

<style>
	.nastisTestResult {ldelim} margin-top: 0.5rem; font-size: 0.875rem; {rdelim}
	.nastisTestResult.is-ok {ldelim} color: #00703c; {rdelim}
	.nastisTestResult.is-error {ldelim} color: #d00a6c; {rdelim}
</style>

<form
    class="pkp_form"
    id="nastisSettingsForm"
    method="post"
    action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}"
>
	{csrf}

	{include file="common/formErrors.tpl"}

	{fbvFormArea id="nastisSettings"}
		{fbvFormSection title="plugins.generic.nastis.settings.baseUrl"}
			{fbvElement type="text" id="baseUrl" value=$baseUrl required="true" size=$fbvStyles.size.MEDIUM}
		{/fbvFormSection}

		{fbvFormSection title="plugins.generic.nastis.settings.journalCode"}
			{fbvElement type="text" id="journalCode" value=$journalCode required="true" size=$fbvStyles.size.MEDIUM}
		{/fbvFormSection}

		{fbvFormSection title="plugins.generic.nastis.settings.clientId"}
			{fbvElement type="text" id="clientId" value=$clientId required="true" size=$fbvStyles.size.MEDIUM}
		{/fbvFormSection}

		{fbvFormSection title="plugins.generic.nastis.settings.apiKey"}
			{fbvElement type="text" password="true" id="apiKey" value=$apiKey required="true" size=$fbvStyles.size.MEDIUM}
		{/fbvFormSection}

		{fbvFormSection title="plugins.generic.nastis.settings.connection"}
			<button
				type="button"
				id="nastisTestConnection"
				class="pkp_button"
				data-test-url="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="testConnection"}"
				data-running-label="{translate key="plugins.generic.nastis.test.running"}"
			>{translate key="plugins.generic.nastis.test.button"}</button>
			<div id="nastisTestResult" class="nastisTestResult" role="status" aria-live="polite"></div>
		{/fbvFormSection}

		{fbvFormSection list="true" title="plugins.generic.nastis.settings.behavior"}
			{fbvElement type="checkbox" id="autoSyncOnPublish" label="plugins.generic.nastis.settings.autoSyncOnPublish" checked=$autoSyncOnPublish}
			{fbvElement type="checkbox" id="autoSyncOnEdit" label="plugins.generic.nastis.settings.autoSyncOnEdit" checked=$autoSyncOnEdit}
			{fbvElement type="checkbox" id="uploadPdf" label="plugins.generic.nastis.settings.uploadPdf" checked=$uploadPdf}
		{/fbvFormSection}

		{fbvFormSection title="plugins.generic.nastis.settings.pluginInfo"}
			<p><strong>{translate key="plugins.generic.nastis.settings.author"}:</strong> {translate key="plugins.generic.nastis.settings.authorName"}</p>
			<p>{translate key="plugins.generic.nastis.settings.authorAffiliation"}</p>
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormButtons}

	<p>{translate key="plugins.generic.nastis.settings.help"}</p>
	<p><span class="formRequired">{translate key="common.requiredField"}</span></p>
</form>
