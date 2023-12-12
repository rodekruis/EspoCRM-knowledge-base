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
          if (!(/^[\+]?[1]?[\s]?[(]?[0-9]{3}[)]?[-\s\.]?[0-9]{3}[-\s\.]?[0-9]{4}$/.test(number))) {
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