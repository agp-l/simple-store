#!/usr/bin/env python3
"""Run the bounded shop worker using the existing deployment connection.

The generated credential authorizes queue ticks only. It stays in protected PHP
configuration on the same shop; it is never committed or printed.
"""
import ftplib
import importlib.util
import io
import json
import re
import secrets
import time
import urllib.error
import urllib.request
from email.utils import parsedate_to_datetime
from pathlib import Path
from installation_config import public_base_url

spec = importlib.util.spec_from_file_location("shop_deploy", Path(__file__).with_name("deploy-ftp.py"))
deploy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(deploy)
KEY_PATH = "/config/supplier-worker.php"


def worker_configuration():
    host, port, user, password, root, security = deploy.configuration()
    ftp = ftplib.FTP_TLS(timeout=30) if security == "ftps" else ftplib.FTP(timeout=30)
    try:
        deploy.connect(ftp, host, port)
        ftp.login(user, password)
        if security == "ftps":
            ftp.prot_p()
        ftp.set_pasv(True)
        ftp.cwd(root)
        ftp.voidcmd("TYPE I")
        for marker in ("index.php", "config/database.php", "config/.htaccess", "supplier-price-cron.php"):
            if not deploy.file_exists(ftp, "/" + marker):
                raise ValueError("Target is not the installed shop.")
        configuration_data = io.BytesIO()
        def receive_config(chunk):
            if configuration_data.tell() + len(chunk) > 16384:
                raise ValueError("Installation configuration is too large.")
            configuration_data.write(chunk)
        try:
            ftp.retrbinary("RETR /config/installation.json", receive_config)
        except ftplib.error_perm as error:
            if not deploy.missing(error):
                raise
            configuration_data = io.BytesIO()
            ftp.retrbinary("RETR /config/installation.example.json", receive_config)
        base = public_base_url(configuration_data.getvalue())
        if not base.startswith("https://"):
            raise ValueError("The hosted worker requires an HTTPS installation.")
        endpoint = base + "/supplier-price-cron.php"
        data = io.BytesIO()
        try:
            ftp.retrbinary("RETR " + KEY_PATH, data.write)
        except ftplib.error_perm as error:
            if not deploy.missing(error):
                raise
            token = secrets.token_hex(32)
            content = ("<?php\nreturn '" + token + "';\n").encode()
            # Atomic create inside protected config; never replace an existing credential.
            temporary = KEY_PATH + ".upload-" + secrets.token_hex(8)
            ftp.storbinary("STOR " + temporary, io.BytesIO(content))
            if deploy.file_exists(ftp, KEY_PATH):
                ftp.delete(temporary)
                ftp.retrbinary("RETR " + KEY_PATH, data.write)
            else:
                ftp.rename(temporary, KEY_PATH)
                data.write(content)
        match = re.fullmatch(rb"<\?php\s+return '([a-f0-9]{64})';\s*", data.getvalue())
        if not match:
            raise ValueError("Invalid worker configuration; no credential changed.")
        return match[1].decode(), endpoint
    finally:
        deploy.disconnect(ftp)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None  # Never forward the credential elsewhere.


def http_diagnostic(error):
    """Only print bounded machine fields from our authenticated error response."""
    fields = []
    try:
        result = json.loads(error.read(4096))
        for name in ("phase", "kind", "code"):
            value = str(result.get(name, ""))
            if re.fullmatch(r"[A-Za-z0-9_\\-]{1,80}", value):
                fields.append(f"{name}={value}")
    except (ValueError, AttributeError, OSError):
        pass
    return f"HTTP {error.code}" + ("; " + "; ".join(fields) if fields else "")


def retry_delay(error, failures):
    delay = 15 * failures
    if isinstance(error, urllib.error.HTTPError):
        header = error.headers.get("Retry-After", "") if error.headers else ""
        try:
            requested = float(header) if header.isdigit() else parsedate_to_datetime(header).timestamp() - time.time()
            delay = max(delay, requested)
        except (ValueError, TypeError, OverflowError):
            pass
    return delay


def run():
    print("Supplier worker: loading private configuration.", flush=True)
    key, endpoint = worker_configuration()
    print("Supplier worker: configuration loaded; processing queue.", flush=True)
    opener = urllib.request.build_opener(NoRedirect)
    deadline = time.monotonic() + 240
    ticks = 0
    failures = 0
    while time.monotonic() < deadline:
        request = urllib.request.Request(endpoint, data=b"", method="POST",
            headers={"X-Supplier-Worker-Key": key, "Accept": "application/json",
                     "User-Agent": "DobrodruziSupplierWorker/1.0"})
        try:
            with opener.open(request, timeout=min(25, max(1, deadline-time.monotonic()))) as response:
                result = json.loads(response.read(4096))
        except (urllib.error.URLError, TimeoutError, ConnectionError) as error:
            failures += 1
            transient = not isinstance(error, urllib.error.HTTPError) or error.code in (408, 429, 502, 503, 504)
            diagnostic = http_diagnostic(error) if isinstance(error, urllib.error.HTTPError) else type(error).__name__
            print(f"Supplier worker request failed: {diagnostic}; consecutive failures={failures}.", flush=True)
            delay = retry_delay(error, failures)
            if not transient or failures > 2 or time.monotonic() + delay + 1 >= deadline:
                raise
            print(f"Supplier worker: retry in {delay:g}s.", flush=True)
            time.sleep(delay)
            continue
        failures = 0
        ticks += 1
        state = result.get("state")
        if state not in ("idle", "paused", "working", "waiting"):
            raise ValueError("Invalid worker response.")
        print(f"Supplier queue tick {ticks}: {state}.", flush=True)
        if state in ("idle", "paused"):
            print(f"Supplier queue: {state}; {ticks} ticks.")
            return
        if state == "waiting" and int(result.get("next_at", 0)) > time.time() + 300:
            print(f"Supplier queue: scheduled for later; {ticks} ticks.")
            return
        delay = max(1, min(60, float(result.get("wait", 30))))
        if time.monotonic() + delay >= deadline:
            break
        time.sleep(delay)
    print(f"Supplier queue: pending; {ticks} ticks; next scheduled run will continue.")


if __name__ == "__main__":
    try:
        run()
    except Exception as error:
        # No request headers, secrets, supplier data or exception body in public logs.
        print(f"Supplier worker stopped ({type(error).__name__}). Inspect shop diagnostics.")
        raise SystemExit(1)
