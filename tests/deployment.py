#!/usr/bin/env python3
"""Verify that a release cannot contain private hosting configuration or uploads."""

import importlib.util
import json
import subprocess
import sys
import tempfile
from pathlib import Path


SCRIPTS = Path(__file__).resolve().parents[1] / "scripts"
sys.path.insert(0, str(SCRIPTS))


def load(name: str, filename: str):
    spec = importlib.util.spec_from_file_location(name, SCRIPTS / filename)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


builder = load("builder", "build-release.py")
deployer = load("deployer", "deploy-sftp.py")


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

    class FakeSFTP:
        def __init__(self):
            self.files = {"/site/index.php": b"old code", "/site/config/database.php": b"private"}

        def put(self, source, target):
            self.files[target] = Path(source).read_bytes()

        def posix_rename(self, source, target):
            raise OSError("extension unavailable")

        def stat(self, target):
            if target not in self.files:
                raise FileNotFoundError(target)

        def rename(self, source, target):
            self.files[target] = self.files.pop(source)

        def remove(self, target):
            del self.files[target]

    remote = FakeSFTP()
    deployer.upload(remote, package / "index.php", "/site/index.php")
    assert remote.files == {"/site/index.php": b"<?php echo 'store';",
                            "/site/config/database.php": b"private"}

print("Deployment packaging tests passed.")
