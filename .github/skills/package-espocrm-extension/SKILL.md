---
name: package-espocrm-extension
description: 'Package EspoCRM customizations into a complete, installable extension zip. Use when the user wants to export, package, publish, build or release custom entities/fields/layouts from an EspoCRM instance as a reusable extension, when an installed extension is missing behaviour that worked on the source instance (broken select filters, missing scheduled jobs, handlers not firing), or when auditing what Administration > Entity Manager > Export leaves out. Covers the export step, supplementing the omitted files, validating that every reference resolves, and testing the install.'
argument-hint: 'Path to the exported zip, or the instance to export from — optional'
---

# Package an EspoCRM extension

Administration > Entity Manager > Export turns custom entities into an installable extension. It is genuinely useful, but it packages **less than people assume**, and the shortfall is invisible: the resulting zip installs cleanly and then misbehaves at runtime.

Treat the export as **step one of a build**, never as the deliverable.

## What Export actually packages

Core EspoCRM, `Espo\Tools\ExportCustom` — unchanged from 9.x through 10.x. It copies `custom/Espo/Custom/Resources`, then filters:

- **metadata/** — only these folders survive: `scopes`, `entityDefs`, `clientDefs`, `recordDefs`, `selectDefs`, `aclDefs`, `entityAcl`, `logicDefs`, `formula`
- **layouts/** — only for detected custom entity types (layouts for core entities like `User` are dropped, even though `entityDefs/User.json` is kept)
- **i18n/** — copied wholesale, every locale, unfiltered
- plus generated `Controllers/*.php` stubs, `Resources/module.json`, and `manifest.json` with `acceptableVersions: [">=9.1.0"]` (hardcoded)

## What it silently omits

Everything below is referenced by the packaged metadata but never shipped:

| Omitted | Typical symptom on the target |
|---|---|
| `client/custom/src/**` JS | `selectHandler` / `view` / `handler` refs point at nothing; filters and custom fields silently stop working |
| PHP outside `Resources/` — `Jobs/`, `Hooks/`, `Services/`, `Classes/` | duplicate checks, global filters, scheduled jobs absent |
| `Resources/metadata/app/**` | scheduled jobs never registered |
| non-whitelisted metadata (`fields`, `dashlets`, `pdfDefs`, `streamDefs`, …) | custom field types / dashlets missing |
| layouts for core entities | layout changes to `User` etc. lost |

**Never packaged, by design** — Workflows, BPMN flowcharts, Reports, Roles, Teams, Scheduled Job *records*, Email/PDF templates, dashboards, and all record data. These live in the database. If the extension is meant to reproduce a working setup, say so explicitly; they must be rebuilt or migrated separately.

## Where client files must go — the prefix rule

This determines the whole layout, so get it right before moving files. From `client/src/loader.js`:

```js
if (mod === 'custom') {
    return 'client/custom/src/' + namePart + '.js';
}
```

- `custom:` is **hard-coded** to `client/custom/src/` and short-circuits *before* module resolution.
- Any other prefix `<mod>:` resolves to `client/custom/modules/<mod>/src/` for installed extensions (core modules use `client/modules/<mod>/src/`). The prefix is the module name hyphenated and lowercased — `FeedbackManagement` → `feedback-management:`.

**Consequence:** if the export module is named `Custom`, its prefix collides with the reserved one, so client files can only ship to `files/client/custom/src/` and the existing `custom:` references need no rewriting. If the module has any other name, ship to `files/client/custom/modules/<hyphenated>/src/` **and** rewrite the references to match — moving the files without rewriting the prefix breaks them just as thoroughly as not shipping them.

Prefer a distinctive module name for anything published widely: `client/custom/src/` is the target instance's own customization area, so shipping there can overwrite their files.

## Backend supplements

- **Jobs/Hooks/Services**: move into `files/custom/Espo/Modules/<Module>/...` and rewrite `namespace Espo\Custom\X;` → `namespace Espo\Modules\<Module>\X;`.
- **Scheduled jobs**: add `Resources/metadata/app/scheduledJobs.json` **inside the module**, with `jobClassName` updated to the module namespace. Do not ship it as `custom/Espo/Custom/Resources/metadata/app/scheduledJobs.json` — that path *replaces* the target's file, whereas module metadata is merged.
- Registering a job type only makes it selectable; the target admin still creates the Scheduled Job record.

## Procedure

1. **Export** — Administration > Entity Manager > Export. Name/version/author/module persist in `data/config.php` under `customExportManifest`, so the form is pre-filled next time. Bump the version.
2. **Sync the omitted files** from the instance, and commit them so the build is reproducible without instance access.
3. **Build** — supplement, rewrite namespaces/prefixes, repack.
4. **Validate — the step that matters.** Every prefixed client reference and every `jobClassName` in the package must resolve to a file the package ships. Fail the build otherwise; this is the only check that catches the silent breakage.
5. **Test on a clean instance** — install, then exercise the specific behaviours that depend on supplemented files (the select filters, the scheduled job appearing in the dropdown). Installing without error proves nothing.

A reference implementation of steps 2–4 lives in `extensions/feedback-management-template/build.py` (`sync`, then `build --from-instance` or `--zip <export.zip>`); it exits non-zero with the offending file and reference when validation fails. `--from-instance` pulls the newest export out of the container — the UI stores every export as an Attachment with role `Export File`, so there is no need to download it from the browser and copy it back.

## Decide who owns publishing

Two shapes, and picking the wrong one creates two competing sources of truth for the same artifact.

**The extension has its own repo** (the common case for anything published). That repo usually commits the package **unpacked** and has CI zip it. Then this build should write the unpacked tree over that repo's source directory, not emit a zip — the human step is to review the `git diff` and commit. Two rules make that safe:

- **Scope deletions to the paths the export owns** — `manifest.json`, `files/custom/Espo/Modules/<Module>/`, `files/client/custom/src/`. Anything else in the tree is repo-owned and must survive a rebuild. A real example: `files/custom/Espo/Modules/.htaccess` sits *beside* the module directory, so a naive "replace the tree" wipes it.
- **The repo, not the export, is the authority on `acceptableVersions`.** Carry the target manifest's value forward instead of taking the export's.

**No separate repo** — emit a zip and attach it to a release. Name it after the extension, not the module: the module is often `Custom`, which makes a meaningless and collision-prone asset name.

Whichever shape, run the reference validation in CI so a broken tree can never be published. Put it *first*, before any step that removes or commits an artifact, so a failure doesn't leave the repo without one.

## Gotchas

- **Write zip entries with forward slashes.** PHP's `ZipArchive` on Linux extracts backslash separators as literal filenames, producing a flat directory of oddly-named files. Windows `Compress-Archive` gets this wrong.
- **`acceptableVersions` is hardcoded `>=9.1.0`** regardless of the instance you exported from. A package built on v10 will happily install on 9.1 while possibly containing v10-only metadata keys. Set the floor deliberately in the repo's manifest, and remember that raising it blocks every version below — including the one most of your users are probably on.
- **The instance's version and the repo's published version drift apart.** `customExportManifest` is bumped on the instance every time someone exports, while releases are cut from the repo. Check both before a release and decide which is canonical, rather than assuming the export's number is right.
- **Re-exporting overwrites nothing automatically** — the zip is stored as an Attachment (role `Export File`). Old versions accumulate; find them with `SELECT id, name FROM attachment WHERE role = 'Export File'`.
- **Check the diff between releases**, not just the file count. The export regenerates every file, so a stray metadata change made while testing ends up in the published package.
