# Duplicate checking for custom fields.
EspoCRM v7 and up

To enable duplication check for custom fields, there are a few steps to take:

Step 1-3 show how to enable duplicate-checks upon record creation, in step 4 it is explained how to turn on this check also at record update.

The process of creating this is best illustrated by an example. In the example below, we will check for a duplicate in the **Contact** entity and we will check against first/lastname and a registration ID.
The files used in this example can be found [here](./custom). Make sure not to overwrite existing folders if you are copying files/folders.

## Step 1: Create Folders

Go to `custom/Espo/Custom/Resources/metadata/` and check if `recordDefs` folder already exists, if not create it:
```
$ sudo mkdir recordDefs
$ sudo chown www-data:www-data recordDefs
```

Go to `custom/Espo/Custom/` and check if `Classes` folder already exists, if not:
```
$ sudo mkdir Classes
$ sudo chown www-data:www-data Classes
$ cd Classes/
$ sudo mkdir DuplicateWhereBuilders
$ sudo chown www-data:www-data DuplicateWhereBuilders
```

## Step 2: Create the class name

Create a classname for your entity.

### Example Class Name

Create `custom/Espo/Custom/Resources/metadata/recordDefs/Contact.json`:

```json
{
    "duplicateWhereBuilderClassName": "Espo\\Custom\\Classes\\DuplicateWhereBuilders\\Contact"
}
```

## Step 3: Create the class

Create a class for your entity.

### Example Class

Create `custom/Espo/Custom/Classes/DuplicateWhereBuilders/Contact.php`:

```php
<?php
namespace Espo\Custom\Classes\DuplicateWhereBuilders;

use Espo\Core\Duplicate\WhereBuilder;

use Espo\ORM\Query\Part\Condition as Cond;
use Espo\ORM\Query\Part\WhereItem;
use Espo\ORM\Query\Part\Where\OrGroup;
use Espo\ORM\Entity;

class Contact implements WhereBuilder
{
    public function build(Entity $entity): ?WhereItem
    {
        $orBuilder = OrGroup::createBuilder();

        $toCheck = false;

        if ($entity->get('firstName') || $entity->get('lastName')) {
            $orBuilder->add(
                Cond::and(
                    Cond::equal(
                        Cond::column('firstName'),
                        $entity->get('firstName')
                    ),
                    Cond::equal(
                        Cond::column('lastName'),
                        $entity->get('lastName')
                    )
                )
            );

            $toCheck = true;
        }
		
        if ($entity->get('registrationID')) {
            $orBuilder->add(
               Cond::equal(
                        Cond::column('registrationID'),
                        $entity->get('registrationID')
                )
            );

            $toCheck = true;
        }

        // Here you can add more conditions.

        if (!$toCheck) {
            return null;
        }

        return $orBuilder->build();
    }
}
```

In this example there are a few things of interest:
- replace `Contact` in line `class Contact implements WhereBuilder` by the name of your class of step 2.
- Conditions and expressions are build in an Object Oriented Programming way, more info [here](https://github.com/espocrm/espocrm/issues/1997). Some examples are:
    - Cond::and
    - Cond::or
    - Cond::equal	
    - Cond::greater
    - Cond::in
    - Cond::notEqual
    - Cond::greaterOrEqual
- A simple example of just checking one field (registrationID in example below) against the same field of that entity (most common usecase) is:
```php
        if ($entity->get('registrationID')) {
            $orBuilder->add(
               Cond::equal(
                        Cond::column('registrationID'),
                        $entity->get('registrationID')
                )
            );

            $toCheck = true;
        }
```

## Step 4: (Optional) Enable duplicate check at record update
If you also want to run the duplicate check while updating a record:

1. Go back to the file created in step 2.
2. Update the file as in tge following example (don't forget the comma)
```json
{
    "duplicateWhereBuilderClassName": "Espo\\Custom\\Classes\\DuplicateWhereBuilders\\Contact",
    "updateDuplicateCheck": true
}
```

### Caution
You should only enable this option if there **cannot** be duplicates. EspoCRM will just show "Error occured" as an errormessage and it will not let you save the contact.

## Step 5: rebuild
Clear cache and rebuild.