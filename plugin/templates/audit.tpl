{**
 * templates/audit.tpl
 *
 * Copyright (c) 2026 Ne Varietur
 * Distributed under the GNU GPL v3.
 *
 * Backoffice audit view — metadata conformity dashboard.
 *}

{include file="common/header.tpl" pageTitle=$pageTitle}

<div class="pkp_page_content" id="nv-audit-dashboard">
	<p class="nv-audit-description">{translate key="plugins.generic.nvMetadataCuration.audit.description"}</p>

	{if empty($auditData)}
		<p>{translate key="plugins.generic.nvMetadataCuration.audit.noSubmissions"}</p>
	{else}
		<table class="pkp_table nv-audit-table" aria-label="{translate key="plugins.generic.nvMetadataCuration.audit.tableLabel"}">
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
						<td class="nv-audit-cell nv-audit-{$row.fields.kwd_curated.status}">
							<span class="nv-audit-badge" aria-label="{$row.fields.kwd_curated.status}">
								{if $row.fields.kwd_curated.status == 'complete'}&#10003; {$row.fields.kwd_curated.count}
								{elseif $row.fields.kwd_curated.status == 'partial'}&#9679;
								{else}&#10007;{/if}
							</span>
						</td>
						<td class="nv-audit-cell nv-audit-{$row.fields.kwd_ojs.status}">
							<span class="nv-audit-badge" aria-label="{$row.fields.kwd_ojs.status}">
								{if $row.fields.kwd_ojs.status == 'complete'}&#10003;{else}&#10007;{/if}
							</span>
						</td>
						<td class="nv-audit-cell nv-audit-{$row.fields.orcid.status}">
							<span class="nv-audit-badge" aria-label="{$row.fields.orcid.status}">
								{if $row.fields.orcid.status == 'complete'}&#10003;
								{elseif $row.fields.orcid.status == 'partial'}&#9679;{else}&#10007;{/if}
								{$row.fields.orcid.ratio}
							</span>
						</td>
						<td class="nv-audit-cell nv-audit-{$row.fields.affiliation.status}">
							<span class="nv-audit-badge" aria-label="{$row.fields.affiliation.status}">
								{if $row.fields.affiliation.status == 'complete'}&#10003;
								{elseif $row.fields.affiliation.status == 'partial'}&#9679;{else}&#10007;{/if}
								{$row.fields.affiliation.ratio}
							</span>
						</td>
					</tr>
				{/foreach}
			</tbody>
		</table>
	{/if}
</div>

{include file="common/footer.tpl"}
