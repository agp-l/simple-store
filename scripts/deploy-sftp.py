#!/usr/bin/env python3
"""Upload a tested release through pinned-host-key SFTP without deleting remote data."""

import argparse
import hashlib
import json
import os
import posixpath
import secrets
from pathlib import Path, PurePosixPath

from release_paths import allowed


def configuration() -> tuple[str, str, str, str, str]:
    names = ("SFTP_HOST", "SFTP_USER", "SFTP_PASSWORD", "SFTP_ROOT", "SFTP_KNOWN_HOSTS")
    values = tuple(os.environ.get(name, "") for name in names)
    if not all(values):
        raise ValueError("Set SFTP_HOST, SFTP_USER, SFTP_PASSWORD, SFTP_ROOT and SFTP_KNOWN_HOSTS.")
    host, user, password, root, known_hosts = values
    if root != posixpath.normpath(root) or not root.startswith("/") or "\n" in root:
        raise ValueError("SFTP_ROOT must be a normalized absolute path within the FTP account.")
    if not Path(known_hosts).is_file():
        raise ValueError("SFTP_KNOWN_HOSTS must be a file with the verified server host key.")
    return host, user, password, root, known_hosts


def release(package: Path) -> dict:
    manifest = json.loads((package / "release.json").read_text(encoding="utf-8"))
    if not isinstance(manifest.get("files"), dict) or not manifest["files"]:
        raise ValueError("Release manifest is empty.")
    for name, digest in manifest["files"].items():
        path = PurePosixPath(name)
        if (path.is_absolute() or ".." in path.parts or not allowed(name, vendor=True) or
                len(digest) != 64 or not (package / name).is_file()):
            raise ValueError(f"Invalid release path: {name}")
        if hashlib.sha256((package / name).read_bytes()).hexdigest() != digest:
            raise ValueError(f"Release file has changed since packaging: {name}")
    return manifest


def exists(sftp, path: str) -> bool:
    try:
        sftp.stat(path)
        return True
    except FileNotFoundError:
        return False


def upload(sftp, local: Path, remote: str) -> None:
    temporary = remote + ".upload-" + secrets.token_hex(6)
    backup = remote + ".previous-" + secrets.token_hex(6)
    sftp.put(str(local), temporary)
    try:
        try:
            sftp.posix_rename(temporary, remote)
        except OSError:
            had_previous = exists(sftp, remote)
            if had_previous:
                sftp.rename(remote, backup)
            try:
                sftp.rename(temporary, remote)
            except Exception:
                if had_previous:
                    sftp.rename(backup, remote)
                raise
            if had_previous:
                sftp.remove(backup)
    finally:
        if exists(sftp, temporary):
            sftp.remove(temporary)


def deploy(package: Path, dry_run: bool) -> None:
    import paramiko

    manifest = release(package)
    host, user, password, root, known_hosts = configuration()
    client = paramiko.SSHClient()
    client.load_host_keys(known_hosts)
    client.set_missing_host_key_policy(paramiko.RejectPolicy())
    try:
        client.connect(host, port=222, username=user, password=password, timeout=20,
                       allow_agent=False, look_for_keys=False)
        sftp = client.open_sftp()
        # A missing marker means the supplied path could target a different site.
        for marker in ("index.php", "config/database.php", ".htaccess"):
            if not exists(sftp, posixpath.join(root, marker)):
                raise ValueError(f"The selected SFTP_ROOT is not an installed simple-store ({marker} missing).")

        remote_manifest_path = posixpath.join(root, "config/.deploy-manifest.json")
        try:
            with sftp.open(remote_manifest_path, "r") as stream:
                previous = json.loads(stream.read().decode("utf-8"))
        except FileNotFoundError:
            previous = {}
        old_hashes = previous.get("files", {})
        changed = [name for name, digest in manifest["files"].items()
                   if old_hashes.get(name) != digest]
        print(f"SFTP target verified; {len(changed)} of {len(manifest['files'])} files need upload.")
        if previous.get("schema_sha256") != manifest["schema_sha256"]:
            print("Schema changed: apply it in Administration > Database after upload.")
        if dry_run:
            return

        directories = set()
        for name in changed:
            parent = posixpath.dirname(name)
            while parent and parent != ".":
                directories.add(parent)
                parent = posixpath.dirname(parent)
        for directory in sorted(directories, key=lambda value: (value.count("/"), value)):
            target = posixpath.join(root, directory)
            if not exists(sftp, target):
                sftp.mkdir(target)
        for name in changed:
            upload(sftp, package / name, posixpath.join(root, name))
        marker = package / "release.json"
        upload(sftp, marker, remote_manifest_path)
        print(f"Uploaded release {manifest['commit'][:12]}; remote configuration and media preserved.")
    finally:
        client.close()


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--package", required=True, type=Path)
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()
    try:
        deploy(args.package.resolve(), args.dry_run)
    except (OSError, ValueError) as error:
        parser.exit(1, f"Deployment stopped: {error}\n")
