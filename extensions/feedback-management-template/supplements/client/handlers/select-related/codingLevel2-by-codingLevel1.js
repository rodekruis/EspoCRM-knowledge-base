define(
  'custom:handlers/select-related/codingLevel2-by-codingLevel1',
  ['handlers/select-related'],
  Dep => {
    return class extends Dep {
      getFilters(model) {
        const advanced = {};

        const level1Id = model.get('codingLevel1Id');
        const level1Name = model.get('codingLevel1Name');

        if (level1Id) {
          advanced.codingLevel1 = {
            attribute: 'codingLevel1Id', // on CCodingLevel2
            type: 'equals',
            value: level1Id,
            data: { type: 'is', nameValue: level1Name }
          };
        }

        return Promise.resolve({ advanced });
      }
    };
  }
);

