#!/usr/bin/env python3
"""Post-process an EspoCRM Entity Manager export into a complete, installable extension.

Administration > Entity Manager > Export produces a package containing only
`custom/Espo/Custom/Resources` (filtered), plus generated controllers. Anything else
your customization depends on is silently left out, which produces a package that
installs cleanly and then misbehaves at runtime:

  * client-side JS under `client/custom/src/` -- referenced from clientDefs by
    `selectHandler` / `view` / `handler`, but never packaged
  * PHP outside `Resources/` (Jobs, Hooks, Services, Classes)
  * `Resources/metadata/app/*` -- dropped by the export's metadata folder whitelist

This script adds those back and then verifies the result: every prefixed client
reference in the package must resolve to a file the package actually ships.

Usage:
    build.py sync  [--container espocrm] [--docker-sudo]
    build.py build --zip <exported.zip> [--out dist]

`sync` refreshes ./supplements from a running instance. `build` is offline and
reproducible, so CI can run it without access to the instance.
"""

from __future__ import annotations

import argparse
import json
import re
import shutil
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
SUPPLEMENTS = HERE / "supplements"

# Paths on the instance that the export omits but the customization needs.
SYNC_PATHS = [
    ("client/custom/src", "client"),
    ("custom/Espo/Custom/Jobs", "jobs"),
    ("custom/Espo/Custom/Resources/metadata/app", "metadata-app"),
]

# Metadata keys whose values point at a client-side JS module.
CLIENT_REF_KEYS = {
    "selectHandler", "handler", "view", "recordViews", "dynamicHandler",
    "initHandler", "editView", "detailView", "listView", "modalView",
    "createHandler", "saveHandler", "colorField", "layoutDefaultSidePanelView",
}

# A module-prefixed reference, e.g. "custom:handlers/select-related/foo".
PREFIXED_REF = re.compile(r"^[a-z][a-z0-9-]*:[A-Za-z0-9/_.-]+$")


def run(cmd: list[str]) -> str:
    result = subprocess.run(cmd, capture_output=True, text=True)
    if result.returncode != 0:
        raise SystemExit(f"command failed: {' '.join(cmd)}\n{result.stderr.strip()}")
    return result.stdout


def cmd_sync(args: argparse.Namespace) -> int:
    docker = (["sudo", "-n"] if args.docker_sudo else []) + ["docker"]
    SUPPLEMENTS.mkdir(parents=True, exist_ok=True)

    for remote, local in SYNC_PATHS:
        target = SUPPLEMENTS / local
        probe = subprocess.run(
            docker + ["exec", args.container, "test", "-e", f"/var/www/html/{remote}"]
        )
        if probe.returncode != 0:
            print(f"  skip  {remote} (not present on instance)")
            continue

        if target.exists():
            shutil.rmtree(target)
        target.mkdir(parents=True)
        run(docker + ["cp", f"{args.container}:/var/www/html/{remote}/.", str(target)])
        count = sum(1 for p in target.rglob("*") if p.is_file())
        print(f"  sync  {remote} -> supplements/{local} ({count} files)")

    print("\nsupplements refreshed; commit them so `build` stays reproducible")
    return 0


def detect_module(root: Path) -> str:
    modules = root / "files" / "custom" / "Espo" / "Modules"
    candidates = [p.name for p in modules.iterdir() if p.is_dir()] if modules.is_dir() else []
    if len(candidates) != 1:
        raise SystemExit(f"expected exactly one module directory, found: {candidates}")
    return candidates[0]


def hyphenate(name: str) -> str:
    return re.sub(r"(?<!^)(?=[A-Z])", "-", name).lower()


def add_client_files(root: Path, report: list[str]) -> None:
    """Ship client JS to `client/custom/src/`.

    The loader hard-codes `custom:` to `client/custom/src/` (client/src/loader.js:
    `if (mod === 'custom') return 'client/custom/src/' + namePart + '.js'`), and that
    branch runs before module resolution. So for a module named `Custom` this is the
    only location the existing `custom:` references can resolve to -- putting the files
    under `client/modules/custom/` or `client/custom/modules/custom/` would not work.
    """
    source = SUPPLEMENTS / "client"
    if not source.is_dir():
        return

    dest = root / "files" / "client" / "custom" / "src"
    dest.mkdir(parents=True, exist_ok=True)
    for item in source.iterdir():
        target = dest / item.name
        if item.is_dir():
            shutil.copytree(item, target, dirs_exist_ok=True)
        else:
            shutil.copy2(item, target)

    count = sum(1 for p in dest.rglob("*.js"))
    report.append(f"client JS         -> files/client/custom/src ({count} files)")


def add_jobs_and_scheduled(root: Path, module: str, report: list[str]) -> None:
    """Move Jobs into the module namespace and re-register them via module metadata.

    Shipping these as `custom/Espo/Custom/...` instead would work, but the package
    would then overwrite the target instance's own `metadata/app/scheduledJobs.json`.
    Module metadata is merged rather than replaced, so scoping to the module is safe.
    """
    jobs_src = SUPPLEMENTS / "jobs"
    if not jobs_src.is_dir():
        return

    module_root = root / "files" / "custom" / "Espo" / "Modules" / module
    jobs_dest = module_root / "Jobs"
    jobs_dest.mkdir(parents=True, exist_ok=True)

    moved = []
    for php in sorted(jobs_src.glob("*.php")):
        text = php.read_text(encoding="utf-8")
        text = text.replace(
            "namespace Espo\\Custom\\Jobs;",
            f"namespace Espo\\Modules\\{module}\\Jobs;",
        )
        (jobs_dest / php.name).write_text(text, encoding="utf-8")
        moved.append(php.stem)

    if moved:
        report.append(f"jobs              -> Modules/{module}/Jobs ({len(moved)}: {', '.join(moved)})")

    app_src = SUPPLEMENTS / "metadata-app" / "scheduledJobs.json"
    if not app_src.is_file():
        return

    defs = json.loads(app_src.read_text(encoding="utf-8"))
    for entry in defs.values():
        if "jobClassName" in entry:
            entry["jobClassName"] = entry["jobClassName"].replace(
                "Espo\\Custom\\Jobs\\", f"Espo\\Modules\\{module}\\Jobs\\"
            )

    app_dest = module_root / "Resources" / "metadata" / "app"
    app_dest.mkdir(parents=True, exist_ok=True)
    (app_dest / "scheduledJobs.json").write_text(
        json.dumps(defs, indent=4) + "\n", encoding="utf-8"
    )
    report.append(f"scheduledJobs     -> Modules/{module}/Resources/metadata/app ({len(defs)} job(s))")


def iter_strings(node, path=""):
    if isinstance(node, dict):
        for key, value in node.items():
            yield from iter_strings(value, f"{path}.{key}" if path else key)
    elif isinstance(node, list):
        for index, value in enumerate(node):
            yield from iter_strings(value, f"{path}[{index}]")
    elif isinstance(node, str):
        yield path, node


def resolve_client_ref(root: Path, ref: str) -> Path:
    prefix, _, name = ref.partition(":")
    if prefix == "custom":
        return root / "files" / "client" / "custom" / "src" / f"{name}.js"
    # Extension modules load from client/custom/modules/<mod>/src/ (loader.js).
    return root / "files" / "client" / "custom" / "modules" / prefix / "src" / f"{name}.js"


def validate(root: Path, module: str) -> list[str]:
    errors: list[str] = []
    shipped_php = {p.stem for p in (root / "files" / "custom" / "Espo" / "Modules" / module / "Jobs").glob("*.php")}

    for json_file in sorted((root / "files").rglob("*.json")):
        try:
            data = json.loads(json_file.read_text(encoding="utf-8"))
        except json.JSONDecodeError as exc:
            errors.append(f"{json_file.relative_to(root)}: invalid JSON ({exc})")
            continue

        rel = json_file.relative_to(root)
        for key_path, value in iter_strings(data):
            leaf = key_path.split(".")[-1].split("[")[0]

            if leaf == "jobClassName":
                if not value.startswith(f"Espo\\Modules\\{module}\\Jobs\\"):
                    errors.append(f"{rel}: jobClassName not module-scoped: {value}")
                elif value.rsplit("\\", 1)[-1] not in shipped_php:
                    errors.append(f"{rel}: jobClassName has no shipped class: {value}")
                continue

            if leaf not in CLIENT_REF_KEYS or not PREFIXED_REF.match(value):
                continue

            if not resolve_client_ref(root, value).is_file():
                errors.append(f"{rel}: {leaf} -> {value} (file not in package)")

    return errors


def repack(root: Path, out_zip: Path) -> None:
    out_zip.parent.mkdir(parents=True, exist_ok=True)
    if out_zip.exists():
        out_zip.unlink()

    # Sorted, forward-slash entries so the archive is byte-stable across machines.
    with zipfile.ZipFile(out_zip, "w", zipfile.ZIP_DEFLATED) as archive:
        for path in sorted(p for p in root.rglob("*") if p.is_file()):
            archive.write(path, path.relative_to(root).as_posix())


def cmd_build(args: argparse.Namespace) -> int:
    source_zip = Path(args.zip).expanduser().resolve()
    if not source_zip.is_file():
        raise SystemExit(f"no such zip: {source_zip}")

    with tempfile.TemporaryDirectory() as tmp:
        root = Path(tmp) / "pkg"
        root.mkdir()
        with zipfile.ZipFile(source_zip) as archive:
            archive.extractall(root)

        manifest_path = root / "manifest.json"
        if not manifest_path.is_file():
            raise SystemExit("not an EspoCRM extension package: manifest.json missing")
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))

        module = detect_module(root)
        print(f"package : {manifest['name']} {manifest['version']}  (module: {module})")
        print(f"source  : {source_zip.name}\n")

        report: list[str] = []
        add_client_files(root, report)
        add_jobs_and_scheduled(root, module, report)

        if report:
            print("added:")
            for line in report:
                print(f"  {line}")
        else:
            print("added: nothing (supplements/ empty -- run `build.py sync` first)")

        print("\nvalidating references...")
        errors = validate(root, module)
        if errors:
            print(f"\nFAILED -- {len(errors)} unresolved reference(s):")
            for error in errors:
                print(f"  {error}")
            print("\nThe package would install cleanly and then break at runtime.")
            return 1
        print("  all prefixed client references and job classes resolve")

        version = manifest.get("version", "0.0.0")
        out_zip = Path(args.out).expanduser().resolve() / f"{hyphenate(module)}-{version}.zip"
        repack(root, out_zip)

    size = out_zip.stat().st_size
    print(f"\nbuilt   : {out_zip}  ({size:,} bytes)")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = parser.add_subparsers(dest="command", required=True)

    sync = sub.add_parser("sync", help="refresh supplements/ from a running instance")
    sync.add_argument("--container", default="espocrm")
    sync.add_argument("--docker-sudo", action="store_true", help="run docker via sudo -n")
    sync.set_defaults(func=cmd_sync)

    build = sub.add_parser("build", help="post-process and validate an exported zip")
    build.add_argument("--zip", required=True, help="zip from Entity Manager > Export")
    build.add_argument("--out", default=str(HERE / "dist"))
    build.set_defaults(func=cmd_build)

    args = parser.parse_args()
    return args.func(args)


if __name__ == "__main__":
    sys.exit(main())
