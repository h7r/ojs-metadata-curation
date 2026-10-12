{**
 * templates/audit.tpl
 *
 * Backoffice audit view — metadata conformity dashboard.
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
	<h1 class="app__pageHeading">{translate key="plugins.generic.nvMetadataCuration.audit.title"}</h1>

	<div class="pkp_page_content" id="nv-audit-dashboard">
		<p class="nv-audit-description">{translate key="plugins.generic.nvMetadataCuration.audit.description"}</p>

		{if empty($auditData)}
			<p>{translate key="plugins.generic.nvMetadataCuration.audit.noSubmissions"}</p>
		{else}
			<table class="pkp_table nv-audit-table" aria-label="{translate|escape key="plugins.generic.nvMetadataCuration.audit.tableLabel"}">
				<thead>
					<tr>
						<th scope="col">{translate key="plugins.generic.nvMetadataCuration.audit.colTitle"}</th>
						<th scope="col">{translate key="plugins.generic.nvMetadataCuration.audit.colKwdCurated"}</th>
						<th scope="col">{translate key="plugins.generic.nvMetadataCuration.audit.colKwdOjs"}</th>
						<th scope="col">{translate key="plugins.generic.nvMetadataCuration.audit.colOrcid"}</th>
						<th scope="col">{translate key="plugins.generic.nvMetadataCuration.audit.colAffiliation"}</th>
					</tr>
				</thead>
				<tbody>
					{foreach from=$auditData item=row}
						<tr>
							<td>
								<a href="{url page="workflow" op="access" path=$row.submissionId}">
									{$row.title|escape}
								</a>
							</td>
							<td class="nv-audit-cell nv-audit-{$row.fields.kwd_curated.status|escape}">
								<span class="nv-audit-badge" aria-label="{$row.fields.kwd_curated.status|escape}">
									{if $row.fields.kwd_curated.status == 'complete'}&#10003; {$row.fields.kwd_curated.count|escape}
									{elseif $row.fields.kwd_curated.status == 'partial'}&#9679;
									{else}&#10007;{/if}
								</span>
							</td>
							<td class="nv-audit-cell nv-audit-{$row.fields.kwd_ojs.status|escape}">
								<span class="nv-audit-badge" aria-label="{$row.fields.kwd_ojs.status|escape}">
									{if $row.fields.kwd_ojs.status == 'complete'}&#10003;{else}&#10007;{/if}
								</span>
							</td>
							<td class="nv-audit-cell nv-audit-{$row.fields.orcid.status|escape}">
								<span class="nv-audit-badge" aria-label="{$row.fields.orcid.status|escape}">
									{if $row.fields.orcid.status == 'complete'}&#10003;
									{elseif $row.fields.orcid.status == 'partial'}&#9679;{else}&#10007;{/if}
									{$row.fields.orcid.ratio|escape}
								</span>
							</td>
							<td class="nv-audit-cell nv-audit-{$row.fields.affiliation.status|escape}">
								<span class="nv-audit-badge" aria-label="{$row.fields.affiliation.status|escape}">
									{if $row.fields.affiliation.status == 'complete'}&#10003;
									{elseif $row.fields.affiliation.status == 'partial'}&#9679;{else}&#10007;{/if}
									{$row.fields.affiliation.ratio|escape}
								</span>
							</td>
						</tr>
					{/foreach}
				</tbody>
			</table>
		{/if}
	</div>
{/block}
