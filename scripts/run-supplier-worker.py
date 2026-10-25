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
import urllib.request
from pathlib import Path

spec = importlib.util.spec_from_file_location("shop_deploy", Path(__file__).with_name("deploy-ftp.py"))
deploy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(deploy)
ENDPOINT = "https://eshop.dobrodruzi.cz/supplier-price-cron.php"
KEY_PATH = "/config/supplier-worker.php"


def worker_key():
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
        return match[1].decode()
    finally:
        deploy.disconnect(ftp)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None  # Never forward the credential elsewhere.


def run():
    key = worker_key()
    opener = urllib.request.build_opener(NoRedirect)
    deadline = time.monotonic() + 240
    ticks = 0
    while time.monotonic() < deadline:
        request = urllib.request.Request(ENDPOINT, data=b"", method="POST",
            headers={"X-Supplier-Worker-Key": key, "Accept": "application/json",
                     "User-Agent": "DobrodruziSupplierWorker/1.0"})
        with opener.open(request, timeout=25) as response:
            result = json.loads(response.read(4096))
        ticks += 1
        state = result.get("state")
        if state not in ("idle", "paused", "working", "waiting"):
            raise ValueError("Invalid worker response.")
        if state in ("idle", "paused"):
            print(f"Supplier queue: {state}; {ticks} ticks.")
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
