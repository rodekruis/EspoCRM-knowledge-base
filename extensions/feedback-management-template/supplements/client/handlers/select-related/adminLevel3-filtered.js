define(
  'custom:handlers/select-related/adminLevel3-filtered',
  ['handlers/select-related'],
  Dep => {
    return class extends Dep {
      getFilters(model) {
        const advanced = {};

        const level1Id = model.get('adminLevel1Id');
        const level1Name = model.get('adminLevel1Name');

        const level2Id = model.get('adminLevel2Id');
        const level2Name = model.get('adminLevel2Name');

        // Filter Level3 by Level1 if selected (requires CAdminLevel3.adminLevel1 belongsTo)
        if (level1Id) {
          advanced.adminLevel1 = {
            attribute: 'adminLevel1Id', // attribute on CAdminLevel3
            type: 'equals',
            value: level1Id,
            data: {
              type: 'is',
              nameValue: level1Name
            }
          };
        }

        // Further narrow by Level2 if selected
        if (level2Id) {
          advanced.adminLevel2 = {
            attribute: 'adminLevel2Id', // attribute on CAdminLevel3
            type: 'equals',
            value: level2Id,
            data: {
              type: 'is',
              nameValue: level2Name
            }
          };
        }

        return Promise.resolve({ advanced });
      }
    };
  }
);
