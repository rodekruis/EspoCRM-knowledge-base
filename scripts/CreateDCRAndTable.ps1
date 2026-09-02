<#
.SYNOPSIS
Sets up log and metric collection for an EspoCRM VM: the custom log table, the two Data
Collection Rules, and their associations with the VM.

.DESCRIPTION
Creates, in one run:
  1. a custom table <TableName>_CL in the Log Analytics workspace,
  2. a DCR that tails the EspoCRM application log files into that table,
  3. a DCR that collects the disk performance counters into the built-in Perf table,
  4. the associations that attach both rules to the VM.

Doing this from a script rather than the Azure Portal avoids the Portal's demand for a
Data Collection Endpoint, which this setup does not need: the agent pushes to the DCR,
nothing pulls. It also keeps every instance identical, which clicking does not.

.NOTES
Adjust the variables as needed. Given the shape of the $Columns variable, a parameter set
would be more trouble than it is worth here.

Allowed column data types:
  String, Dynamic (a JSON object or array), Int, Boolean, Datetime, Guid, Long, Real

Prerequisites:
  - the Az PowerShell module
  - a Log Analytics workspace
  - the Azure Monitor Agent installed on the VM (VM > Extensions + applications >
    AzureMonitorLinuxAgent); enabling VM monitoring installs it
  - the VM in $ResourceGroup, and $AzureRegion equal to the workspace's region
  - the agent's user able to read the log files:
      sudo -u azuremonitoragent head -n 1 <log file>

This is the agent path, where a DCR is a configuration the agent reads. It needs no Data
Collection Endpoint, no app registration and no 'Monitoring Metrics Publisher' role. Those
belong to the Logs Ingestion API, i.e. the other way of getting custom data in, where your
own code POSTs to a DCR endpoint:
https://learn.microsoft.com/en-us/azure/azure-monitor/logs/tutorial-logs-ingestion-api?tabs=dcr
#>

# Set the variables
$TableName = 'espocrmlogs'
$Columns = @(
    # TimeGenerated is mandatory!
    @{ 'name' = 'TimeGenerated'; 'type' = 'datetime'; 'description' = 'The time (UTC) at which the data was added to the table.' },
    @{ 'name' = 'RawData';       'type' = 'string';   'description' = 'The raw data of the log entry.' },
    @{ 'name' = 'Computer';      'type' = 'string';   'description' = 'The computer that generated the log entry.' },
    @{ 'name' = 'FilePath';      'type' = 'string';   'description' = 'The file path of the log entry.' },
    @{ 'name' = 'TimeStamp';     'type' = 'datetime'; 'description' = 'The timestamp parsed from the log entry.' },
    @{ 'name' = 'Severity';      'type' = 'string';   'description' = 'The severity level of the log entry.' },
    @{ 'name' = 'ErrorCode';     'type' = 'int';      'description' = 'The HTTP status code of the log entry, when it has one.' },
    @{ 'name' = 'Message';       'type' = 'string';   'description' = 'The message of the log entry.' }
)
$TenantID                  = '<tenant-id>'                            # Netherlands Red Cross
$SubscriptionID            = '<subscription-id>'
$ResourceGroup             = '<resource-group>'                       # holds the workspace *and* the VM
$LogAnalyticsWorkspaceName = '<log-analytics-workspace-name>'
$VirtualMachineName        = '<vm-name>'
$TotalRetentionInDays      = 30
$ApiVersion                = '2022-10-01'
$AzureRegion               = 'WestEurope'                             # must match the workspace's region
$LogFilePatterns = @(
    '/var/www/espocrm/data/espocrm/data/logs/espo-*.log'
)
$DiskCounterSpecifiers = @(
    'Logical Disk(*)\% Used Space'
    'Logical Disk(*)\Free Megabytes'
)
$LogsDataCollectionRuleName = 'espocrm-applogs-dcr'
$DiskDataCollectionRuleName = 'espocrm-disk-dcr'

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
# The stream holds only what the agent delivers; the other columns appear in the transformation.
$StreamColumns = $Columns |
    Where-Object { $_.name -in @('TimeGenerated', 'RawData', 'Computer', 'FilePath') } |
    ForEach-Object { @{ 'name' = $_.name; 'type' = $_.type } }
# Column names in the query must match $Columns exactly (case-sensitive).
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

# Create the table
Invoke-AzRestMethod -Path $Path -Method PUT -Payload $Payload

# Create the DCRs and attach them to the VM
# -TemplateObject trips over the hashtables, via a JSON file it works fine.
$TemplateObject | ConvertTo-Json -Depth 30 | Out-File -FilePath $TemplateFilePath -Encoding utf8
New-AzResourceGroupDeployment -ResourceGroupName $ResourceGroup -TemplateFile $TemplateFilePath
