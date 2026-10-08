#!/usr/bin/env python3
"""Build a deployable snapshot from tracked application files and Composer vendors."""

import argparse
import hashlib
import json
import shutil
import subprocess
from pathlib import Path

from release_paths import allowed


ROOT = Path(__file__).resolve().parents[1]


def tracked_files() -> list[str]:
    output = subprocess.check_output(["git", "ls-files", "-z"], cwd=ROOT)
    paths = [name.decode("utf-8") for name in output.split(b"\0") if name]
    return sorted(name for name in paths if allowed(name))


def build(destination: Path) -> None:
    if destination.exists() and any(destination.iterdir()):
        raise ValueError("Release directory must be empty.")
    destination.mkdir(parents=True, exist_ok=True)
    vendor = ROOT / "vendor"
    if not (vendor / "autoload.php").is_file():
        raise ValueError("Run composer install before building a release.")

    paths = tracked_files()
    for source in vendor.rglob("*"):
        if source.is_symlink():
            raise ValueError("Composer vendor contains a symlink; inspect it before deployment.")
        if source.is_file():
            paths.append(source.relative_to(ROOT).as_posix())

    hashes = {}
    for name in sorted(paths):
        source = ROOT / name
        if not source.is_file() or source.is_symlink():
            raise ValueError(f"Invalid release file: {name}")
        target = destination / name
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(source, target)
        hashes[name] = hashlib.sha256(target.read_bytes()).hexdigest()

    commit = subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT, text=True).strip()
    manifest = {"commit": commit, "schema_sha256": hashes["database/schema.sql"], "files": hashes}
    (destination / "release.json").write_text(json.dumps(manifest, sort_keys=True) + "\n",
                                                 encoding="utf-8")
    print(f"Built {len(hashes)} application files from {commit[:12]}.")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    build(args.output.resolve())
