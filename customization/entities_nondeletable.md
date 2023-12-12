## Make custom entities and fields "non-deletable"

- to make an Entity non-deletable set `"isCustom": false` in 
`custom/Espo/Custom/Resources/metadata/scopes/<MyEntity>.json`
- to make a Field non-deletable set `"isCustom": false` under `fields/<MyField>` in
`custom/Espo/Custom/Resources/metadata/entityDefs/<MyEntity>.json`
