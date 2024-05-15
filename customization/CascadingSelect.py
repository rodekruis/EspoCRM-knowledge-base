import json
import pandas as pd

json_file_name = 'YourEntity.json' 
csv_file_name = 'YourCSV.csv'        
# Load the JSON file
def load_json(filename):
    with open(filename, 'r') as file:
        data = json.load(file)
    return data

# Load the CSV file using pandas
def load_csv(filename):
    return pd.read_csv(filename)

def transform_data(df):
    attribute_column, value_column = df.columns[0], df.columns[1]
    output = {value_column: []}
    grouped = df.groupby(attribute_column)[value_column].apply(list)
    for attribute_value, values in grouped.items():
        option_section = {
            "optionList": values,
            "conditionGroup": [
                {
                    "type": "equals",
                    "attribute": attribute_column,
                    "value": attribute_value
                }
            ]
        }
        output[value_column].append(option_section)
    return output

def merge_data(original_data, new_data):
    key = list(new_data.keys())[0]  
    if 'dynamicLogic' not in original_data:
        original_data['dynamicLogic'] = {}
    if 'options' not in original_data['dynamicLogic']:
        original_data['dynamicLogic']['options'] = {}
    original_data['dynamicLogic']['options'].update(new_data)

    return original_data


def save_json(data, filename):
    with open(filename, 'w') as file:
        json.dump(data, file, indent=4)

existing_data = load_json(json_file_name)
csv_data = load_csv(csv_file_name)
new_data = transform_data(csv_data)
updated_data = merge_data(existing_data, new_data)
save_json(updated_data, json_file_name)
