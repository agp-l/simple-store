#!/usr/bin/env python3
"""Verify that a release cannot contain private hosting configuration or uploads."""

import importlib.util
import json
import os
import subprocess
import sys
import tempfile
from pathlib import Path
from unittest.mock import Mock, patch


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
        "fio-cron.php": "<?php echo 'fio';",
        "mail-cron.php": "<?php echo 'mail';",
        "config/site.php": "<?php return [];",
        "config/installation.example.json": json.dumps({"public_base_url": "https://shop.example.test"}),
        "config/installation.json": json.dumps({"public_base_url": "https://private.example.test"}),
        "config/database.php": "<?php return ['password' => 'private'];",
        "config/.login-throttle.json": '{"private-attempt-state":true}',
        "config/.login-throttle.json.lock": "",
        "database/schema.sql": "CREATE DATABASE test;",
        "tools/fio-worker.php": "<?php echo 'worker';",
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
    assert "fio-cron.php" in manifest["files"]
    assert "mail-cron.php" in manifest["files"]
    assert "vendor/autoload.php" in manifest["files"]
    assert "tools/fio-worker.php" in manifest["files"]
    assert "images/media/.htaccess" in manifest["files"]
    assert manifest["files"]["database/schema.sql"] == manifest["schema_sha256"]
    assert (package / "database/schema.sql").read_bytes() == (root / "database/schema.sql").read_bytes()
    assert "config/installation.example.json" in manifest["files"]
    assert "config/installation.json" not in manifest["files"]
    assert "config/database.php" not in manifest["files"]
    assert "config/.login-throttle.json" not in manifest["files"]
    assert "config/.login-throttle.json.lock" not in manifest["files"]
    assert "database/zaloha.sql" not in manifest["files"]
    assert "images/media/photo.jpg" not in manifest["files"]
    assert not (package / "config/database.php").exists()
    assert not (package / "images/media/photo.jpg").exists()

    # A transient failure before login must recover; credentials are never
    # retried after an authentication failure. No real network or waiting here.
    flaky = Mock()
    flaky.connect.side_effect = [OSError("Network is unreachable"),
                                 deployer.ftplib.error_temp("421 Temporarily unavailable"), None]
    with patch.object(deployer.time, "sleep") as sleep:
        deployer.connect(flaky, "ftp.example.org", 21)
    assert flaky.connect.call_count == 3 and flaky.close.call_count == 2
    assert sleep.call_count == 2
    flaky.login.assert_not_called()

    connection_error = OSError("Network is unreachable")
    unavailable = Mock(sock=None)
    unavailable.connect.side_effect = connection_error
    env = {"FTP_HOST": "ftp.example.org", "FTP_USER": "shop", "FTP_PASSWORD": "example"}
    with patch.dict(os.environ, env), patch.object(deployer.ftplib, "FTP", return_value=unavailable), \
            patch.object(deployer.time, "sleep"):
        try:
            deployer.deploy(package, False)
            raise AssertionError("An unavailable FTP server appeared to deploy.")
        except OSError as error:
            assert error is connection_error  # cleanup must preserve the original failure
    assert unavailable.connect.call_count == 3
    unavailable.login.assert_not_called()
    unavailable.quit.assert_not_called()
    unavailable.storbinary.assert_not_called()

    denied = Mock(sock=object())
    denied.login.side_effect = deployer.ftplib.error_perm("530 Login incorrect")
    with patch.dict(os.environ, env), patch.object(deployer.ftplib, "FTP", return_value=denied), \
            patch.object(deployer.time, "sleep") as sleep:
        try:
            deployer.deploy(package, False)
            raise AssertionError("Rejected credentials appeared to deploy.")
        except deployer.ftplib.error_perm:
            pass
    assert denied.connect.call_count == 1 and denied.login.call_count == 1
    denied.quit.assert_called_once()
    denied.close.assert_called_once()
    denied.storbinary.assert_not_called()
    sleep.assert_not_called()

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

    installation_remote = FakeFTP()
    example = package / "config/installation.example.json"
    deployer.initialize_installation(installation_remote, example, "/site")
    assert installation_remote.files["/site/config/installation.json"] == example.read_bytes()
    custom = b'{"public_base_url":"https://new.example.test/eshop"}'
    installation_remote.files["/site/config/installation.json"] = custom
    deployer.initialize_installation(installation_remote, example, "/site")
    assert installation_remote.files["/site/config/installation.json"] == custom
    assert not any(".upload-" in name for name in installation_remote.files)

    class FailingFTP(FakeFTP):
        def rename(self, source, target):
            if ".upload-" in source:
                raise deployer.ftplib.error_perm("550 Cannot rename uploaded file")
            super().rename(source, target)

    failed_installation = FailingFTP()
    try:
        deployer.initialize_installation(failed_installation, example, "/site")
        raise AssertionError("Installation creation silently failed")
    except deployer.ftplib.error_perm:
        pass
    assert "/site/config/installation.json" not in failed_installation.files
    assert not any(".upload-" in name for name in failed_installation.files)
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
