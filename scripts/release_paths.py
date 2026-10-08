"""Allowlist for files that may be copied from a checkout to web hosting."""

from pathlib import PurePosixPath


APP_DIRS = ("assets/", "src/", "view/", "images/")
APP_FILES = {".htaccess", "style.css", "composer.json", "composer.lock", "database/schema.sql"}
CONFIG_FILES = {"config/.htaccess", "config/site.php", "config/menus.php",
                "config/database.example.php", "config/checkout.example.php"}
TOOLS = {"tools/admin.php", "tools/apply-schema.php", "tools/content.php",
         "tools/mail-worker.php"}


def allowed(name: str, *, vendor: bool = False) -> bool:
    path = PurePosixPath(name)
    if not name or path.is_absolute() or ".." in path.parts or "/" in name and "\\" in name:
        return False
    if vendor and name.startswith("vendor/"):
        return True
    if name == "images/media/.htaccess":
        return True
    if name.startswith("images/media/"):
        return False
    return (name in APP_FILES or name in CONFIG_FILES or name in TOOLS or
            ("/" not in name and name.endswith(".php")) or name.startswith(APP_DIRS))
