define(
  'custom:handlers/select-related/adminLevel2-by-adminLevel1',
  ['handlers/select-related'],
  Dep => {
    return class extends Dep {
      getFilters(model) {
        const advanced = {};

        const parentId = model.get('adminLevel1Id');
        const parentName = model.get('adminLevel1Name');

        if (parentId) {
          advanced.adminLevel1 = {
            attribute: 'adminLevel1Id',
            type: 'equals',
            value: parentId,
            data: {
              type: 'is',
              nameValue: parentName
            }
          };
        }

        return Promise.resolve({ advanced });
      }
    };
  }
);
