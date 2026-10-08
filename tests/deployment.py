#!/usr/bin/env python3
"""Verify that a release cannot contain private hosting configuration or uploads."""

import importlib.util
import json
import os
import subprocess
import sys
import tempfile
from pathlib import Path
from unittest.mock import patch


SCRIPTS = Path(__file__).resolve().parents[1] / "scripts"
sys.path.insert(0, str(SCRIPTS))


def load(name: str, filename: str):
    spec = importlib.util.spec_from_file_location(name, SCRIPTS / filename)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


builder = load("builder", "build-release.py")
deployer = load("deployer", "deploy-ftp.py")

with patch.dict(os.environ, {"FTP_HOST": "ftp.example.org", "FTP_USER": "shop",
                           "FTP_PASSWORD": "example", "FTP_ROOT": "/unrelated"}):
    assert deployer.configuration() == ("ftp.example.org", 21, "shop", "example", "/", "ftp")


with tempfile.TemporaryDirectory() as directory:
    root = Path(directory)
    contents = {
        ".htaccess": "deny secrets",
        "index.php": "<?php echo 'store';",
        "config/site.php": "<?php return [];",
        "config/database.php": "<?php return ['password' => 'private'];",
        "database/schema.sql": "CREATE DATABASE test;",
        "database/zaloha.sql": "private database dump",
        "images/media/photo.jpg": "private uploaded photo",
        "images/media/.htaccess": "deny scripts",
        "vendor/autoload.php": "<?php",
    }
    for name, body in contents.items():
        file = root / name
        file.parent.mkdir(parents=True, exist_ok=True)
        file.write_text(body, encoding="utf-8")
    subprocess.run(["git", "init", "-q", str(root)], check=True)
    subprocess.run(["git", "add", "."], cwd=root, check=True)
    subprocess.run(["git", "-c", "user.name=Test", "-c", "user.email=test@example.org",
                    "commit", "-qm", "test"], cwd=root, check=True)
    builder.ROOT = root
    package = root / "release"
    builder.build(package)
    manifest = deployer.release(package)
    assert "index.php" in manifest["files"]
    assert "vendor/autoload.php" in manifest["files"]
    assert "images/media/.htaccess" in manifest["files"]
    assert "config/database.php" not in manifest["files"]
    assert "database/zaloha.sql" not in manifest["files"]
    assert "images/media/photo.jpg" not in manifest["files"]
    assert not (package / "config/database.php").exists()
    assert not (package / "images/media/photo.jpg").exists()

    manifest["files"]["config/database.php"] = "0" * 64
    (package / "config/database.php").write_text("secret", encoding="utf-8")
    (package / "release.json").write_text(json.dumps(manifest), encoding="utf-8")
    try:
        deployer.release(package)
        raise AssertionError("A private database config entered the release.")
    except ValueError:
        pass

    class FakeFTP:
        def __init__(self):
            self.files = {"/site/index.php": b"old code", "/site/config/database.php": b"private"}

        def storbinary(self, command, source):
            self.files[command.removeprefix("STOR ")] = source.read()

        def size(self, target):
            if target not in self.files:
                raise deployer.ftplib.error_perm("550 Not found")
            return len(self.files[target])

        def rename(self, source, target):
            self.files[target] = self.files.pop(source)

        def delete(self, target):
            del self.files[target]

    remote = FakeFTP()
    deployer.upload(remote, package / "index.php", "/site/index.php")
    assert remote.files == {"/site/index.php": b"<?php echo 'store';",
                            "/site/config/database.php": b"private"}

    class FailingFTP(FakeFTP):
        def rename(self, source, target):
            if ".upload-" in source:
                raise deployer.ftplib.error_perm("550 Cannot rename uploaded file")
            super().rename(source, target)

    failing = FailingFTP()
    try:
        deployer.upload(failing, package / "index.php", "/site/index.php")
        raise AssertionError("A failed FTP rename appeared to succeed.")
    except deployer.ftplib.error_perm:
        assert failing.files == {"/site/index.php": b"old code",
                                 "/site/config/database.php": b"private"}

    assert deployer.missing(deployer.ftplib.error_perm("550 Not found"))
    assert not deployer.missing(deployer.ftplib.error_perm("530 Login incorrect"))

print("Deployment packaging tests passed.")
