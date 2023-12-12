# Create global filters for all users
EspoCRM v7 and up

This developer guide is on how to create a global filter for all users. Video manual [here](https://www.youtube.com/watch?v=BYllS-6_xdE).

The process of creating this is best illustrated by an example. In the example below, we will create a filter for the **GeneralCases** to filter for cases that are open or pending.
The files used in this example can be found [here](./custom). Make sure not to overwrite existing folders if you are copying files/folders.

## Step 1: Create Folders

Go to `custom/Espo/Custom/Resources/metadata/` and check if `clientDefs` & `selectDefs` folders already exists, if not create:
```
$ sudo mkdir clientDefs
$ sudo mkdir selectDefs
```

Go to `custom/Espo/Custom/` and create neccessary folders, omitting the steps for folders that are already there: 
```
$ sudo mkdir Classes
$ cd Classes/
$ sudo mkdir Select
$ cd Select/
$ sudo mkdir GeneralCasesObj
$ cd GeneralCasesObj
$ sudo mkdir PrimaryFilters
```

## Step 2a: Create the class name

Create a classname for your entity.

### Example Class Name

Create `custom/Espo/Custom/Resources/metadata/selectDefs/GeneralCases.json`:

```json
{
    "primaryFilterClassNameMap": {
        "open": "Espo\\Custom\\Classes\\Select\\GeneralCasesObj\\PrimaryFilters\\Open"
    }
}
```

## Step 2b: Create the filter name
Create `custom/Espo/Custom/Resources/metadata/clientDefs/GeneralCases.json`:

```json
{
    "filterList": [
        {
            "name": "open"
        }
    ]
}
```

If this file already exists, find `"filterlist"` and change that part accordingly.

## Step 3: Create the class

Create a class for your entity.

### Example Class

Create `custom/Espo/Custom/Classes/Select/GeneralCasesObj/PrimaryFilters/Open.php`:

```php
<?php

namespace Espo\Custom\Classes\Select\GeneralCasesObj\PrimaryFilters;

use Espo\Core\Select\Primary\Filter;
use Espo\ORM\Query\SelectBuilder;

class Open implements Filter
{
    public function apply(SelectBuilder $queryBuilder): void
    {
        $queryBuilder->where([
            'status!=' => ['closed']
        ]);
    }
}
```

In this example there are a few things of interest:
- replace `Open` in line `class Open implements Filter` by the name of your class of step 2a.
- `$queryBuilder` is the part where the actual filter is created. In this example we filter for cases that have `status` field not equal to `closed`. **Make sure to use fieldnames, not the labels.** Supported comparison operators: `>`, `<`, `>=`, `<=`, `=`, `!=`. More info [here](https://docs.espocrm.com/development/orm/#select-query-parameters).

## Step 4: Change ownership

Change ownership of all files & folders in Custom folder recursively in folder `/custom/Espo/`
```
$ sudo chown -R www-data:www-data Custom
```


## Step 5: Clear Cache
Clear cache: Administration > Clear Cache