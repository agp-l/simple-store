#!/usr/bin/env python3
"""Upload a tested release over FTP without touching hosting configuration or media."""

import argparse
import ftplib
import hashlib
import io
import json
import os
import posixpath
import secrets
from pathlib import Path, PurePosixPath

from release_paths import allowed


def configuration() -> tuple[str, int, str, str, str, str]:
    names = ("FTP_HOST", "FTP_USER", "FTP_PASSWORD")
    host, user, password = (os.environ.get(name, "") for name in names)
    if not all((host, user, password)):
        raise ValueError("Set FTP_HOST, FTP_USER and FTP_PASSWORD.")
    if any(char in host for char in "/:@\r\n "):
        raise ValueError("FTP_HOST must be a hostname, without a URL or port.")
    security = os.environ.get("FTP_SECURITY", "ftp").lower()
    if security not in ("ftp", "ftps"):
        raise ValueError("FTP_SECURITY must be ftp or ftps.")
    port_text = os.environ.get("FTP_PORT", "21")
    if not port_text.isdecimal() or not 1 <= int(port_text) <= 65535:
        raise ValueError("FTP_PORT must be a valid port number.")
    return host, int(port_text), user, password, "/", security


def release(package: Path) -> dict:
    manifest = json.loads((package / "release.json").read_text(encoding="utf-8"))
    if not isinstance(manifest.get("files"), dict) or not manifest["files"]:
        raise ValueError("Release manifest is empty.")
    for name, digest in manifest["files"].items():
        path = PurePosixPath(name)
        if (path.is_absolute() or ".." in path.parts or not allowed(name, vendor=True) or
                not isinstance(digest, str) or len(digest) != 64 or
                not (package / name).is_file()):
            raise ValueError(f"Invalid release path: {name}")
        if hashlib.sha256((package / name).read_bytes()).hexdigest() != digest:
            raise ValueError(f"Release file has changed since packaging: {name}")
    return manifest


def missing(error: ftplib.error_perm) -> bool:
    return str(error).startswith("550")


def directory_exists(ftp: ftplib.FTP, path: str) -> bool:
    previous = ftp.pwd()
    try:
        ftp.cwd(path)
        return True
    except ftplib.error_perm as error:
        if missing(error):
            return False
        raise
    finally:
        ftp.cwd(previous)


def file_exists(ftp: ftplib.FTP, path: str) -> bool:
    try:
        return ftp.size(path) is not None
    except ftplib.error_perm as error:
        if missing(error):
            return False
        raise


def upload(ftp: ftplib.FTP, local: Path, remote: str) -> None:
    temporary = remote + ".upload-" + secrets.token_hex(6)
    backup = remote + ".previous-" + secrets.token_hex(6)
    with local.open("rb") as source:
        ftp.storbinary("STOR " + temporary, source)
    try:
        # Some FTP servers cannot rename over a file. Preserve the old file
        # until the new one is in place and restore it if the rename fails.
        if file_exists(ftp, remote):
            ftp.rename(remote, backup)
            try:
                ftp.rename(temporary, remote)
            except Exception:
                ftp.rename(backup, remote)
                raise
            ftp.delete(backup)
        else:
            ftp.rename(temporary, remote)
    finally:
        if file_exists(ftp, temporary):
            ftp.delete(temporary)


def deploy(package: Path, dry_run: bool) -> None:
    manifest = release(package)
    host, port, user, password, root, security = configuration()
    ftp = ftplib.FTP_TLS(timeout=30) if security == "ftps" else ftplib.FTP(timeout=30)
    try:
        ftp.connect(host, port)
        ftp.login(user, password)
        if security == "ftps":
            ftp.prot_p()
        ftp.set_pasv(True)
        ftp.cwd(root)
        ftp.voidcmd("TYPE I")
        # Refuse to deploy into another site's FTP directory.
        for marker in ("index.php", "config/database.php", ".htaccess"):
            if not file_exists(ftp, posixpath.join(root, marker)):
                raise ValueError(f"The selected FTP_ROOT is not an installed simple-store ({marker} missing).")

        remote_manifest_path = posixpath.join(root, "config/.deploy-manifest.json")
        data = io.BytesIO()
        try:
            ftp.retrbinary("RETR " + remote_manifest_path, data.write)
            previous = json.loads(data.getvalue().decode("utf-8"))
        except ftplib.error_perm as error:
            if not missing(error):
                raise
            previous = {}
        old_hashes = previous.get("files", {})
        changed = [name for name, digest in manifest["files"].items()
                   if old_hashes.get(name) != digest]
        print(f"FTP target verified; {len(changed)} of {len(manifest['files'])} files need upload.")
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
            if not directory_exists(ftp, target):
                ftp.mkd(target)
        for name in changed:
            upload(ftp, package / name, posixpath.join(root, name))
        upload(ftp, package / "release.json", remote_manifest_path)
        print(f"Uploaded release {manifest['commit'][:12]}; remote configuration and media preserved.")
    finally:
        try:
            ftp.quit()
        except (OSError, EOFError, ftplib.Error):
            ftp.close()


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--package", required=True, type=Path)
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()
    try:
        deploy(args.package.resolve(), args.dry_run)
    except (OSError, ValueError, ftplib.Error) as error:
        parser.exit(1, f"Deployment stopped: {error}\n")
