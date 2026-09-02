<#
.SYNOPSIS
Sets up monitoring data collection for an EspoCRM VM: the Azure Monitor Agent, the custom
log table, the two Data Collection Rules, and their associations with the VM.

.DESCRIPTION
Creates, in one run:
  1. a custom table <TableName>_CL in the Log Analytics workspace,
  2. the Azure Monitor Agent extension on the VM,
  3. a DCR that tails the EspoCRM application log files into that table,
  4. a DCR that collects the disk performance counters into the built-in Perf table,
  5. the associations that attach both rules to the VM.

Doing this from a script rather than the Azure Portal has three advantages. The Portal
demands a Data Collection Endpoint, which this setup does not need: the agent pushes to
the DCR, nothing pulls. The Portal's 'enable monitoring' blade also creates an action
group 'VMI-ActionGroup-<vm-name>' and a metric alert 'VM Availability - <vm-name>' that
duplicate what we set up by hand, the action group notifying whoever clicked the button,
personally; deploying the agent from a template creates neither. And it keeps every
instance identical, which clicking does not.

.PARAMETER SubscriptionID
The Azure subscription holding the workspace and the VM.

.PARAMETER ResourceGroup
The resource group holding the Log Analytics workspace *and* the VM. The associations are
deployed at resource-group scope, so a VM elsewhere fails with a resource-not-found error
after the rules themselves have already been created.

.PARAMETER LogAnalyticsWorkspaceName
The Log Analytics workspace the data is written to. Create it beforehand.

.PARAMETER VirtualMachineName
The VM running EspoCRM.

.PARAMETER TableName
Base name of the custom log table; Azure appends _CL. Drives the query names used in the
alert rules, so changing it means changing those too.

.PARAMETER AzureRegion
Region for the Data Collection Rules. Must match the workspace's region.

.EXAMPLE
pwsh -ExecutionPolicy Bypass -File ./NewDcrAndTable.ps1 `
  -SubscriptionID 6afb807c-68ed-4a17-8665-78e5ff39bd4f `
  -ResourceGroup espocrm-sandbox `
  -LogAnalyticsWorkspaceName expocrm-sandbox-log-analytics `
  -VirtualMachineName espocrm-sandbox

.NOTES
The column list and the ingestion-time transformation stay inside the script: they are the
same on every instance, and they have to be edited together anyway.

Allowed column data types:
  String, Dynamic (a JSON object or array), Int, Boolean, Datetime, Guid, Long, Real

Prerequisites:
  - the Az PowerShell module: Install-Module Az -Scope CurrentUser
  - a Log Analytics workspace, in -ResourceGroup
  - the agent's user able to read the log files:
      sudo -u azuremonitoragent head -n 1 <log file>
    if that says 'Permission denied', fix it before running this, or nothing will ever be
    ingested and Azure will not tell you why

Not needed, despite what parts of the Azure documentation suggest: a Data Collection
Endpoint, an app registration, or the 'Monitoring Metrics Publisher' role. Those belong to
the Logs Ingestion API, i.e. the other way of getting custom data in, where your own code
POSTs to a DCR endpoint:
https://learn.microsoft.com/en-us/azure/azure-monitor/logs/tutorial-logs-ingestion-api?tabs=dcr
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [string] $SubscriptionID,

    [Parameter(Mandatory)]
    [string] $ResourceGroup,

    [Parameter(Mandatory)]
    [string] $LogAnalyticsWorkspaceName,

    [Parameter(Mandatory)]
    [string] $VirtualMachineName,

    [Parameter(Mandatory)]
    [string] $TenantID,

    [string] $TableName = 'espocrmlogs',

    # Must match the workspace's region.
    [string] $AzureRegion = 'WestEurope',

    # The VM's own region; only differs if the VM sits elsewhere.
    [string] $VirtualMachineRegion = $AzureRegion,

    [int] $TotalRetentionInDays = 30,

    [string] $ApiVersion = '2022-10-01',

    # Pin the agent's major version and let Azure keep the minor current. If the deployment
    # rejects '1.0', put the current minor from the extension versions page here instead:
    # https://learn.microsoft.com/en-us/azure/azure-monitor/agents/azure-monitor-agent-extension-versions
    [string] $AgentTypeHandlerVersion = '1.0',

    [string[]] $LogFilePatterns = @(
        '/var/www/espocrm/data/espocrm/data/logs/espo-*.log'
    ),

    [string[]] $DiskCounterSpecifiers = @(
        'Logical Disk(*)\% Used Space'
        'Logical Disk(*)\Free Megabytes'
    ),

    [string] $LogsDataCollectionRuleName = 'espocrm-applogs-dcr',

    [string] $DiskDataCollectionRuleName = 'espocrm-disk-dcr'
)

$ErrorActionPreference = 'Stop'

# The columns of the custom table. TimeGenerated is mandatory!
# The first four are what the agent delivers; the rest are produced by $TransformKql below,
# so the two lists have to stay in sync - same names, same casing.
$Columns = @(
    @{ 'name' = 'TimeGenerated'; 'type' = 'datetime'; 'description' = 'The time (UTC) at which the data was added to the table.' },
    @{ 'name' = 'RawData';       'type' = 'string';   'description' = 'The raw data of the log entry.' },
    @{ 'name' = 'Computer';      'type' = 'string';   'description' = 'The computer that generated the log entry.' },
    @{ 'name' = 'FilePath';      'type' = 'string';   'description' = 'The file path of the log entry.' },
    @{ 'name' = 'TimeStamp';     'type' = 'datetime'; 'description' = 'The timestamp parsed from the log entry.' },
    @{ 'name' = 'Severity';      'type' = 'string';   'description' = 'The severity level of the log entry.' },
    @{ 'name' = 'ErrorCode';     'type' = 'int';      'description' = 'The HTTP status code of the log entry, when it has one.' },
    @{ 'name' = 'Message';       'type' = 'string';   'description' = 'The message of the log entry.' }
)

# EspoCRM writes    [2026-08-31 10:12:33] ERROR: Before-save formula script failed. ...
# and, for API errors,  [2026-08-31 10:12:33] ERROR: (404) Record does not exist; GET ...
# The status code is therefore optional: Body is split off first, then the code is peeled
# off it if present, so Message is populated either way.
$TransformKql = @'
source
| extend TimeStamp = todatetime(extract(@'^\[([^\]]+)\]', 1, RawData))
| extend Severity = extract(@'^\[[^\]]+\]\s+([A-Za-z]+):', 1, RawData)
| extend Body = extract(@'^\[[^\]]+\]\s+[A-Za-z]+:\s*(.*)$', 1, RawData)
| extend ErrorCode = toint(extract(@'^\((\d+)\)', 1, Body))
| extend Message = extract(@'^(?:\(\d+\)\s*)?(.*)$', 1, Body)
| project TimeGenerated, RawData, Computer, FilePath, TimeStamp, Severity, ErrorCode, Message
'@

# Computed variables - do not edit
$Payload = @{
    'properties' = @{
        'retentionInDays'      = $TotalRetentionInDays
        'totalRetentionInDays' = $TotalRetentionInDays
        'schema'               = @{
            'name'    = '{0}_CL' -f $TableName
            'columns' = $Columns
        }
    }
} | ConvertTo-Json -Depth 10
$WorkspaceResourceId = '/subscriptions/{0}/resourceGroups/{1}/providers/Microsoft.OperationalInsights/workspaces/{2}' -f $SubscriptionID, $ResourceGroup, $LogAnalyticsWorkspaceName
$Path = '{0}/tables/{1}_CL?api-version={2}' -f $WorkspaceResourceId, $TableName, $ApiVersion
$LogsAssociationName = '{0}-association' -f $LogsDataCollectionRuleName
$DiskAssociationName = '{0}-association' -f $DiskDataCollectionRuleName
$StreamName = 'Custom-Text-{0}' -f $TableName
$OutputStreamName = 'Custom-{0}_CL' -f $TableName
$TemplateFilePath = Join-Path -Path (Get-Location) -ChildPath 'espocrm-monitoring-template.json'
$AgentResourceId = "[resourceId('Microsoft.Compute/virtualMachines/extensions', '$VirtualMachineName', 'AzureMonitorLinuxAgent')]"
# The stream holds only what the agent delivers; the other columns appear in the transformation.
$StreamColumns = $Columns |
    Where-Object { $_.name -in @('TimeGenerated', 'RawData', 'Computer', 'FilePath') } |
    ForEach-Object { @{ 'name' = $_.name; 'type' = $_.type } }
$Destinations = @{
    'logAnalytics' = @(
        @{
            'workspaceResourceId' = $WorkspaceResourceId
            'name'                = $LogAnalyticsWorkspaceName
        }
    )
}
$TemplateObject = @{
    '$schema' = "https://schema.management.azure.com/schemas/2019-08-01/deploymentTemplate.json#"
    'contentVersion' = '1.0.0.0'
    'resources' = @(
        # --- Azure Monitor Agent -----------------------------------------------------
        # Deploying the extension directly, rather than through the Portal's 'enable
        # monitoring' blade, is what keeps VMI-ActionGroup and the duplicate availability
        # alert from being created. Host metrics (CPU, memory, VM availability) need no
        # agent at all: Azure measures those from outside the machine.
        @{
            'type' = 'Microsoft.Compute/virtualMachines/extensions'
            'name' = '{0}/AzureMonitorLinuxAgent' -f $VirtualMachineName
            'location' = $VirtualMachineRegion
            'apiVersion' = '2024-07-01'
            'properties' = @{
                'publisher' = 'Microsoft.Azure.Monitor'
                'type' = 'AzureMonitorLinuxAgent'
                'typeHandlerVersion' = $AgentTypeHandlerVersion
                'autoUpgradeMinorVersion' = $true
                'enableAutomaticUpgrade' = $true
            }
        }
        # --- application log files -> <TableName>_CL ---------------------------------
        @{
            'type' = 'Microsoft.Insights/dataCollectionRules'
            'name' = $LogsDataCollectionRuleName
            'location' = $AzureRegion
            'apiVersion' = '2023-03-11'
            'kind' = 'Linux'
            'properties' = @{
                'streamDeclarations' = @{
                    $StreamName = @{ 'columns' = $StreamColumns }
                }
                'dataSources' = @{
                    'logFiles' = @(
                        @{
                            'name' = $StreamName
                            'streams' = @( $StreamName )
                            'filePatterns' = $LogFilePatterns
                            'format' = 'text'
                        }
                    )
                }
                'destinations' = $Destinations
                'dataFlows' = @(
                    @{
                        'streams' = @( $StreamName )
                        'destinations' = @( $LogAnalyticsWorkspaceName )
                        'transformKql' = $TransformKql
                        'outputStream' = $OutputStreamName
                    }
                )
            }
        }
        @{
            'type' = 'Microsoft.Insights/dataCollectionRuleAssociations'
            'name' = $LogsAssociationName
            'apiVersion' = '2023-03-11'
            'scope' = 'Microsoft.Compute/virtualMachines/{0}' -f $VirtualMachineName
            'dependsOn' = @(
                $AgentResourceId
                "[resourceId('Microsoft.Insights/dataCollectionRules', '$LogsDataCollectionRuleName')]"
            )
            'properties' = @{
                'dataCollectionRuleId' = "[resourceId('Microsoft.Insights/dataCollectionRules', '$LogsDataCollectionRuleName')]"
                'description' = 'Associates the application log DCR with the virtual machine.'
            }
        }
        # --- disk performance counters -> Perf ---------------------------------------
        # No custom table and no transformation: Microsoft-Perf lands in the built-in
        # Perf table, which already exists in every workspace.
        @{
            'type' = 'Microsoft.Insights/dataCollectionRules'
            'name' = $DiskDataCollectionRuleName
            'location' = $AzureRegion
            'apiVersion' = '2023-03-11'
            'kind' = 'Linux'
            'properties' = @{
                'dataSources' = @{
                    'performanceCounters' = @(
                        @{
                            'name' = 'diskCounters'
                            'streams' = @( 'Microsoft-Perf' )
                            'samplingFrequencyInSeconds' = 60
                            'counterSpecifiers' = $DiskCounterSpecifiers
                        }
                    )
                }
                'destinations' = $Destinations
                'dataFlows' = @(
                    @{
                        'streams' = @( 'Microsoft-Perf' )
                        'destinations' = @( $LogAnalyticsWorkspaceName )
                    }
                )
            }
        }
        @{
            'type' = 'Microsoft.Insights/dataCollectionRuleAssociations'
            'name' = $DiskAssociationName
            'apiVersion' = '2023-03-11'
            'scope' = 'Microsoft.Compute/virtualMachines/{0}' -f $VirtualMachineName
            'dependsOn' = @(
                $AgentResourceId
                "[resourceId('Microsoft.Insights/dataCollectionRules', '$DiskDataCollectionRuleName')]"
            )
            'properties' = @{
                'dataCollectionRuleId' = "[resourceId('Microsoft.Insights/dataCollectionRules', '$DiskDataCollectionRuleName')]"
                'description' = 'Associates the disk DCR with the virtual machine.'
            }
        }
    )
    'outputs' = @{
        'logsDataCollectionRuleId' = @{
            'type' = 'string'
            'value' = "[resourceId('Microsoft.Insights/dataCollectionRules', '$LogsDataCollectionRuleName')]"
        }
        'diskDataCollectionRuleId' = @{
            'type' = 'string'
            'value' = "[resourceId('Microsoft.Insights/dataCollectionRules', '$DiskDataCollectionRuleName')]"
        }
    }
}

# Connect to Azure
Connect-AzAccount -Tenant $TenantID -Subscription $SubscriptionID

# Give the VM a system-assigned managed identity, which the agent authenticates with.
# The Portal does this silently when you onboard; from a template we have to ask.
# Idempotent: skipped when the VM already has one.
$Vm = Get-AzVM -ResourceGroupName $ResourceGroup -Name $VirtualMachineName
if ($Vm.Identity.Type -notmatch 'SystemAssigned') {
    Write-Host "Adding a system-assigned managed identity to $VirtualMachineName..."
    Update-AzVM -ResourceGroupName $ResourceGroup -VM $Vm -IdentityType SystemAssigned
}

# Create the table
Write-Host "Creating table ${TableName}_CL in $LogAnalyticsWorkspaceName..."
Invoke-AzRestMethod -Path $Path -Method PUT -Payload $Payload

# Install the agent, create the DCRs and attach them to the VM
# -TemplateObject trips over the hashtables, via a JSON file it works fine.
Write-Host "Deploying the agent and the data collection rules..."
$TemplateObject | ConvertTo-Json -Depth 30 | Out-File -FilePath $TemplateFilePath -Encoding utf8
New-AzResourceGroupDeployment -ResourceGroupName $ResourceGroup -TemplateFile $TemplateFilePath
