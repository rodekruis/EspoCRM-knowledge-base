# Duplicate checking for custom fields.
EspoCRM v7 and up

To enable regex field validation on specific fields. This is best illustrated with an example. In this example we will validate the **phoneNumber** of the **Contact** entity.

## Step 1: Create Folders
Go to `custom/Espo/Custom/Resources/metadata/` and check if `entityDefs` folder already exists, if not create:
```
$ sudo mkdir entityDefs
```

Go to `client/custom` and check if `src` folder already exists, if not create it:
```
$ sudo mkdir src
$ cd src/
$ sudo mkdir views
$ cd views/
$ sudo mkdir fields
$ cd fields/
```

## Step 2: Create your custom field view

Create a view name for your entity.

### Example view Name

Create or open `custom/Espo/Custom/Resources/metadata/entityDefs/Contact.json` and add the view under the `phoneNumber` field in the following structure

```json
{
    "fields": {
        "phoneNumber": {
            "edit": "custom:views/fields/phone"
        }  
    }
}
```

## Step 3: Create the field view

Create your own phone.js under `client/custom/src/views/fields/` which should extend the parent `phone.js`

### Example field view

```js
define('custom:views/fields/phone', 'views/fields/phone', function (Dep) {
    return Dep.extend({

    setup: function () {
        Dep.prototype.setup.call(this);
    },

    validatePhoneData: function () {
	  var data = this.model.get(this.dataFieldName);

	  if (!data || !data.length) return;
	  var numberList = [];
	  var notValid = false;

	  data.forEach(function (row, i) {
		var number = row.phoneNumber;
		// Add validation for the Phone number
		if (!(/^[\+]{1}[0-9]{11,12}$/.test(number))) {
		  var msg = this.translate('The phone format is wrong!').replace('{field}', this.getLabelText());
		  this.showValidationMessage(msg);
		  notValid = true;
		  return true;
		}

		var numberClean = String(number).replace(/[\s\+]/g, '');
		if (~numberList.indexOf(numberClean)) {
		   var msg = this.translate('fieldValueDuplicate', 'messages').replace('{field}', this.getLabelText());
		   this.showValidationMessage(msg, 'div.phone-number-block:nth-child(' + (i + 1).toString() + ') input');
		   notValid = true;
		   return;
		}
		numberList.push(numberClean);
		}, this);

		if (notValid) {
		  return true;
		}
	}
    });
});
```

In this example there are a few things of interest:
- in the `number` function, it uses regex to check the validity: `^[\+]{1}[0-9]{11,12}$`. You can check your regex function [here](https://regex101.com/).
- in the `numberClean` function, the phone number is cleaned. Because of the the way the regex is formulated in this example it is not relevant, but depending on what your regex looks like, this cleans up the user-input.
- after cleaning it pushes the number to the existing number-list

## Step 5: Clear Cache
Clear cache through Administration >> Clear Cache.