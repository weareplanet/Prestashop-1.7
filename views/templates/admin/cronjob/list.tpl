{**
 * WeArePlanet Prestashop
 *
 * This Prestashop module enables to process payments with WeArePlanet (https://www.weareplanet.com/).
 *
 * @author customweb GmbH (http://www.customweb.com/)
 * @copyright 2017 - 2025 customweb GmbH
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache Software License (ASL 2.0)
 *}
<div class="panel col-lg-12">	
	<div class="panel-heading">
		<i class="icon-list-ul"></i>
		WeArePlanet {l s='CronJobs' mod='weareplanet'}
	</div>
		<div class="table-responsive-row clearfix">
			<table class="table">
				<thead>
					<tr class="nodrag nodrop">
						<th class="fixed-width-xs text-center">
							<span class="title_box">{l s='ID' mod='weareplanet'}</span>
						</th>
						<th class="fixed-width-s text-center">
							<span class="title_box">{l s='State' mod='weareplanet'}</span>
						</th>
						<th class="fixed-width-m text-center">
							<span class="title_box">{l s='Scheduled' mod='weareplanet'}</span>
						</th>
						<th class="fixed-width-m text-center">
							<span class="title_box">{l s='Started' mod='weareplanet'}</span>
						</th>
						<th class="fixed-width-m text-center">
							<span class="title_box">{l s='Finished' mod='weareplanet'}</span>
						</th>
						<th class="fixed-width-l center">
							<span class="title_box">{l s='Message' mod='weareplanet'}</span>
						</th>
					</tr>
				
				</thead>
				<tbody>
				{if isset($jobs) && count($jobs) > 0 }
					{foreach from=$jobs item=job}
						<tr class="">
							<td class=" fixed-width-xs text-center">{$job.id_cron_job|escape:'html':'UTF-8'}</td>
							<td class=" fixed-width-s text-center">{$job.state|escape:'html':'UTF-8'}</td>
							<td class=" fixed-width-m text-center">{$job.date_scheduled|date_format:'%Y-%m-%d %H:%M:%S'|escape:'html':'UTF-8'}</td>
							<td class=" fixed-width-m text-center">
								{if !empty($job.date_started) }
									{$job.date_started|date_format:'%Y-%m-%d %H:%M:%S'|escape:'html':'UTF-8'}
								{else}
								 	--
								{/if}								
							</td>
							<td class=" fixed-width-m text-center">
								{if !empty($job.date_finished) }
									{$job.date_finished|date_format:'%Y-%m-%d %H:%M:%S'|escape:'html':'UTF-8'}
								{else}
								 	--
								{/if}								
							</td>
							<td class=" fixed-width-l text-center">
								{if !empty($job.error_msg) }
									{$job.error_msg|escape:'html':'UTF-8'}
								{else}
								 	--
								{/if}
							</td>
							
						</tr>
					{/foreach}
				{else}
					<tr>
						<td class="text-center" colspan="6">
							{l s='No cron available yet.' mod='weareplanet'}
						</td>
					</tr>
				{/if}
				</tbody>
			</table>
		</div>
	</div>
</div>
