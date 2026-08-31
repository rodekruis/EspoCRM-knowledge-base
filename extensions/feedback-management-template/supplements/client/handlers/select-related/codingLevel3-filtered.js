define(
  'custom:handlers/select-related/codingLevel3-filtered',
  ['handlers/select-related'],
  Dep => {
    return class extends Dep {
      getFilters(model) {
        const advanced = {};

        const level1Id = model.get('codingLevel1Id');
        const level1Name = model.get('codingLevel1Name');
        const level2Id = model.get('codingLevel2Id');
        const level2Name = model.get('codingLevel2Name');

        if (level1Id) {
          advanced.codingLevel1 = {
            attribute: 'codingLevel1Id', // on CCodingLevel3
            type: 'equals',
            value: level1Id,
            data: { type: 'is', nameValue: level1Name }
          };
        }

        if (level2Id) {
          advanced.codingLevel2 = {
            attribute: 'codingLevel2Id', // on CCodingLevel3
            type: 'equals',
            value: level2Id,
            data: { type: 'is', nameValue: level2Name }
          };
        }

        return Promise.resolve({ advanced });
      }
    };
  }
);
