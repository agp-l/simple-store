"""Read the same installation JSON as PHP, without executing remote PHP code."""
import json
import re
from urllib.parse import urlsplit


def normalize_url(value):
    if not isinstance(value, str):
        raise ValueError("Invalid installation address.")
    value = value.strip().rstrip("/")
    if len(value) > 1000 or re.search(r"[\x00-\x20\x7f\\]", value):
        raise ValueError("Invalid installation address.")
    parts = urlsplit(value)
    host = parts.hostname
    port = parts.port
    if not host or parts.username is not None or parts.password is not None or "?" in value or "#" in value:
        raise ValueError("Invalid installation address.")
    if parts.scheme != "https" and not (parts.scheme == "http" and host in ("localhost", "127.0.0.1", "::1")):
        raise ValueError("Installation address must use HTTPS.")
    if ":" not in host and (len(host) > 253 or any(not re.fullmatch(r"[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?", label) for label in host.split("."))):
        raise ValueError("Invalid installation host.")
    if parts.path and not re.fullmatch(r"/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*", parts.path):
        raise ValueError("Invalid installation directory.")
    host = "[" + host + "]" if ":" in host else host.lower()
    suffix = ":" + str(port) if port is not None and port != (443 if parts.scheme == "https" else 80) else ""
    return parts.scheme + "://" + host + suffix + parts.path


def public_base_url(data):
    if len(data) > 16384:
        raise ValueError("Installation configuration is too large.")
    config = json.loads(data)
    if not isinstance(config, dict) or set(config) - {"public_base_url", "previous_base_urls"}:
        raise ValueError("Invalid installation configuration fields.")
    previous = config.get("previous_base_urls", [])
    if not isinstance(previous, list) or len(previous) > 32:
        raise ValueError("Invalid previous addresses.")
    for value in previous:
        normalize_url(value)
    return normalize_url(config.get("public_base_url"))
