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
TBI