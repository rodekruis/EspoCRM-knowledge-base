#!/usr/bin/env bash
#
# Sets up monitoring data collection for an EspoCRM VM:
#   1. a system-assigned managed identity on the VM (what the agent authenticates with)
#   2. a custom table <table-name>_CL in the Log Analytics workspace
#   3. the Azure Monitor Agent, two Data Collection Rules and their associations,
#      deployed from espocrm-monitoring.json next to this script
#
# Runs in Azure Cloud Shell as-is (bash, already signed in), or anywhere with the Azure
# CLI installed. Only core `az` commands are used - no extensions to install.
#
# Doing this from a script rather than the Azure Portal has three advantages. The Portal
# demands a Data Collection Endpoint, which this setup does not need: the agent pushes to
# the DCR, nothing pulls. The Portal's 'enable monitoring' blade also creates an action
# group 'VMI-ActionGroup-<vm-name>' and a metric alert 'VM Availability - <vm-name>' that
# duplicate the alert rules we set up by hand, the action group notifying whoever clicked
# the button, personally; deploying the agent from a template creates neither. And it
# keeps every instance identical, which clicking does not.
#
# Before running, check the log files are readable by the agent's unprivileged user.
# On the VM:
#     sudo find /var/www/espocrm -type d -path '*/data/logs'
#     namei -l <that directory>/espo-$(date +%F).log
# Every directory needs o+x, the log directory itself o+rx (the agent lists it to resolve
# the wildcard), and the files o+r. If not, nothing will ever be ingested and Azure will
# not tell you why.

set -euo pipefail

TABLE_NAME='espocrmlogs'
RETENTION_DAYS=30
DEPLOYMENT_NAME='espocrm-monitoring'
TEMPLATE_FILE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/espocrm-monitoring.json"
SUBSCRIPTION_ID=''
RESOURCE_GROUP=''
WORKSPACE_NAME=''
VM_NAME=''

usage() {
    cat <<'EOF'
Usage: ./new-dcr-and-table.sh \
         --subscription <subscription-id> \
         --resource-group <resource-group> \
         --workspace <log-analytics-workspace-name> \
         --vm <vm-name> \
         [--table-name espocrmlogs] [--retention-days 30] [--template <path>]

The workspace and the VM must both be in --resource-group: the rule associations are
deployed at resource-group scope.

Anything else the template exposes - the log file patterns, the disk counters, the rule
names, the agent version - can be overridden by editing espocrm-monitoring.json or by
passing extra --parameters to the az deployment command at the bottom of this script.
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --subscription)    SUBSCRIPTION_ID="$2"; shift 2 ;;
        --resource-group)  RESOURCE_GROUP="$2";  shift 2 ;;
        --workspace)       WORKSPACE_NAME="$2";  shift 2 ;;
        --vm)              VM_NAME="$2";         shift 2 ;;
        --table-name)      TABLE_NAME="$2";      shift 2 ;;
        --retention-days)  RETENTION_DAYS="$2";  shift 2 ;;
        --template)        TEMPLATE_FILE="$2";   shift 2 ;;
        -h|--help)         usage; exit 0 ;;
        *) echo "Unknown option: $1" >&2; usage >&2; exit 1 ;;
    esac
done

for required in SUBSCRIPTION_ID RESOURCE_GROUP WORKSPACE_NAME VM_NAME; do
    if [[ -z "${!required}" ]]; then
        echo "Missing required option for ${required}." >&2
        usage >&2
        exit 1
    fi
done

if [[ ! -f "$TEMPLATE_FILE" ]]; then
    echo "Template not found: $TEMPLATE_FILE" >&2
    echo "It should sit next to this script; pass --template to point elsewhere." >&2
    exit 1
fi

az account set --subscription "$SUBSCRIPTION_ID"

echo "==> Ensuring $VM_NAME has a system-assigned managed identity"
# Idempotent: returns the existing identity if there already is one.
az vm identity assign \
    --resource-group "$RESOURCE_GROUP" \
    --name "$VM_NAME" \
    --output none

echo "==> Creating table ${TABLE_NAME}_CL in $WORKSPACE_NAME"
# The first four columns are what the agent delivers; the rest are produced by the
# transformation in the template, so the two have to stay in sync - same names, same
# casing. TimeGenerated is mandatory.
COLUMNS=(
    TimeGenerated=datetime
    RawData=string
    Computer=string
    FilePath=string
    TimeStamp=datetime
    Severity=string
    ErrorCode=int
    Message=string
)
if az monitor log-analytics workspace table show \
        --resource-group "$RESOURCE_GROUP" \
        --workspace-name "$WORKSPACE_NAME" \
        --name "${TABLE_NAME}_CL" \
        --output none 2>/dev/null; then
    echo "    table exists, updating"
    TABLE_VERB=update
else
    TABLE_VERB=create
fi
# A table can gain columns but cannot lose or retype them; undoing a wrong type means
# deleting the table and losing what is in it.
az monitor log-analytics workspace table "$TABLE_VERB" \
    --resource-group "$RESOURCE_GROUP" \
    --workspace-name "$WORKSPACE_NAME" \
    --name "${TABLE_NAME}_CL" \
    --retention-time "$RETENTION_DAYS" \
    --total-retention-time "$RETENTION_DAYS" \
    --columns "${COLUMNS[@]}" \
    --output none

echo "==> Deploying the agent, the data collection rules and their associations"
echo "    (this waits for the agent to install, so it takes a few minutes)"
az deployment group create \
    --resource-group "$RESOURCE_GROUP" \
    --name "$DEPLOYMENT_NAME" \
    --template-file "$TEMPLATE_FILE" \
    --parameters \
        vmName="$VM_NAME" \
        workspaceName="$WORKSPACE_NAME" \
        tableName="$TABLE_NAME" \
    --query 'properties.provisioningState' \
    --output tsv

cat <<EOF

Done. Give it about 15 minutes, then check the data is arriving - from the Azure Portal >
Log Analytics workspace > Logs:

    Perf
    | where ObjectName == "Logical Disk" and CounterName == "% Used Space"
    | summarize arg_max(TimeGenerated, CounterValue) by InstanceName

    ${TABLE_NAME}_CL
    | project TimeGenerated, TimeStamp, Severity, ErrorCode, Message
    | order by TimeGenerated desc
    | take 20

Only lines written after the rules were associated are collected - the agent never
backfills - so trigger something that logs rather than waiting for yesterday's errors.
EOF
