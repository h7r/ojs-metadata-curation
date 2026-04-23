<script>
	$(function() {ldelim}
		$('#nvMetadataCurationSettings').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form
	class="pkp_form"
	id="nvMetadataCurationSettings"
	method="post"
	action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}"
>
	{csrf}

	{fbvFormArea id="nvSettingsThesauri" title="plugins.generic.nvMetadataCuration.settings.thesauri"}
		{fbvFormSection description="plugins.generic.nvMetadataCuration.settings.thesauriDescription" list="true"}
			{foreach from=$validThesauri item=t}
				{assign var="checked" value=false}
				{if is_array($thesauri) && in_array($t, $thesauri)}
					{assign var="checked" value=true}
				{/if}
				{fbvElement
					type="checkbox"
					id="thesauri[]"
					name="thesauri[]"
					value=$t
					checked=$checked
					label="plugins.generic.nvMetadataCuration.thesaurus.$t"
				}
			{/foreach}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormButtons submitText="common.save"}
</form>
