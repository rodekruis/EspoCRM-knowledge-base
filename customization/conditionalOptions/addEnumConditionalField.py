import itertools
import click
import json


@click.command()
@click.option('--clientjson', default='CPersonAffected.json', help='JSON file in espocrm/custom/Espo/Custom/Resources/metadata/clientDefs')
@click.option('--entityjson', default='CPersonAffected.json', help='JSON file in espocrm/custom/Espo/Custom/Resources/metadata/entityDefs')
@click.option('--targetfield', default='preferredLanguage', help='Field to add conditional options to')
@click.option('--conditionalfield', default='languagesSpoken', help='Field to base conditional options on')
def addEnumConditionalField(clientjson, entityjson, targetfield, conditionalfield):
    """
    Add conditional options to an enum field (targetfield) based on the values of another enum field (conditionalfield).
    Example: if conditionalfield has values X and Y, then targetfield can only have values X and Y.
    """

    with open(entityjson, 'r') as entityFile:
        dataEntity = json.load(entityFile)
        options = dataEntity["fields"][conditionalfield]["options"]

    option_combinations = []
    for i in range(1, len(options) + 1):
        for combo in list(itertools.combinations(options, i)):
            option_combinations.append(list(combo))
    print(option_combinations)

    with open(clientjson, 'r') as clientFile:
        dataClient = json.load(clientFile)

    conditionalOptions = []
    for combo in option_combinations:
        case = {}
        case["optionList"] = combo
        case["conditionGroup"] = []
        for option in options:
            if option in combo:
                conditionGroup = {
                    "type": "has",
                    "attribute": conditionalfield,
                    "value": option
                }
            else:
                conditionGroup = {
                    "type": "notHas",
                    "attribute": conditionalfield,
                    "value": option
                }
            case["conditionGroup"].append(conditionGroup)
        conditionalOptions.append(case)
    print(conditionalOptions)

    dataClient["dynamicLogic"]["options"][targetfield] = conditionalOptions
    
    with open(clientjson, 'w') as clientFile:
        json.dump(dataClient, clientFile, indent=4)
    
    
if __name__ == '__main__':
    addEnumConditionalField()
