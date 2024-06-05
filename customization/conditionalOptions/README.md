# conditionalOptions

scripts for advanced conditional options in EspoCRM

## addEnumConditionalField.py
Add conditional options to an enum field (`targetfield`) based on the values of another enum field (`conditionalfield`).

Example: if `conditionalfield` has values X and Y, then `targetfield` can only have values X and Y.

#### Usage
1Install `click` with `pip install click`
1. Download `espocrm/custom/Espo/Custom/Resources/metadata/clientDefs/<MyEntity>.json`; this will be the `clientjson` file
2. Download `espocrm/custom/Espo/Custom/Resources/metadata/entityDefs/<MyEntity>.json`; this will be the `entityjson` file
4. Run as follows:
```
python addEnumConditionalField.py --clientjson <clientjson> --entityjson <entityjson> --targetfield <targetfield> --conditionalfield <conditionalfield>

options:
  --clientjson TEXT        JSON file in espocrm/custom/Espo/Custom/Resources/metadata/clientDefs
  --entityjson TEXT        JSON file in espocrm/custom/Espo/Custom/Resources/metadata/entityDefs
  --targetfield TEXT       Field to add conditional options to
  --conditionalfield TEXT  Field to base conditional options on
  --help                   Show this message and exit.
```
4. Upload the modified `clientjson` file back to `espocrm/custom/Espo/Custom/Resources/metadata/clientDefs`
5. Rebuild espo from the UI (`Administation > Rebuild`) or CLI (`sudo /var/www/espocrm/command.sh rebuild`)


## CascadingSelect.py
Add conditional options based on an input CSV file.

#### Usage
**To efficiently update cascade selections within EspoCRM for your custom entities, follow the detailed steps outlined below:**

#### 1. Access the JSON Configuration

SSH into your server and navigate to the directory containing the relevant JSON file for your entity:

For example:

cd /var/www/espocrm/data/espocrm/custom/Espo/Custom/Resources/metadata/clientDefs/YourEntity.json

Store this JSON file on your local machine.

#### 2. JSON Configuration for Cascade Selection

The JSON structure for implementing cascade selections appears as follows:

![image](https://github.com/rodekruis/EspoCRM-knowledge-base/assets/130642075/9b16a1b8-aa6d-4e99-802a-e8f245dcaeb5)


This configuration links two enum fields, for example, Country and Province. The selections available in Province depend on the option chosen in Country. For instance, selecting 'Hungary' in TEST1 offers 'Budapest', 'Bacs Kiskun', and others in Province.

#### 3. Bulk Updating Options
This method should only be used when there are a large number of options that need to be edited, prepare your data in the following CSV format:

![image](https://github.com/rodekruis/EspoCRM-knowledge-base/assets/130642075/80922e8b-dad0-43d6-bee0-fa244a77e792)

Where the column headers are the internal field names, and the cells refer to the internal options name in EspoCRM.

Make sure these fields already exist in your CRM!

#### 4. Backup and Automation

Create a copy of the json file you took from the VM, and store it as a back up, in case this may be required later.

Store the json file, and the csv you created in the same directory, along with the following [Python script](https://github.com/rodekruis/EspoCRM-knowledge-base/blob/main/customization/CascadingSelect.py).

Make sure to add the names of your JSON files and the CSV at the top of the script. Some adjustments may be required, as this script assumes the cascading select is based on the answer equaling an option in another field, if you need the options to only when it does not equal, or some other option, you will need to adjust the script.

#### 5. Remove the Old JSON File

Access the VM via SSH and delete the original JSON file using the following SSH command:
rm /var/www/espocrm/data/espocrm/custom/Espo/Custom/Resources/metadata/clientDefs/YourEntity.json

**Important**: This action is irreversible, so proceed with caution.

#### 6. Transfer the New JSON File

Copy the updated JSON file from your local machine to the VM using SCP, as direct writing may be restricted, you should use the following workaround, copy the file to a temporary folder on the vm using the following command:

scp C:\path\to\local\file.json username@vm_ip:/var/tmp/


#### 7. Move the File into Place

If you had to place the file in a temporary folder, SSH into the VM and move the file to the final directory using:

sudo mv /var/tmp/your_file.json /var/www/espocrm/data/espocrm/custom/Espo/Custom/Resources/metadata/clientDefs/

#### 8. Final Steps in EspoCRM

Log into the admin panel of EspoCRM.
Clear the cache, rebuild the backend, and refresh the page to apply changes.