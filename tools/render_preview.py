#!/usr/bin/env python3
"""Create plain HTML previews from the PHP view fragments, without a PHP runtime."""

from html import escape
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
VIEWS = ROOT / "view"


def fragment(name: str) -> str:
    return (VIEWS / name).read_text(encoding="utf-8")


def render(filename: str, body: str, title: str, description: str) -> None:
    head = fragment("head.php")
    head = head.replace(
        "<?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>",
        escape(title, quote=True),
    ).replace(
        "<?= htmlspecialchars($pageDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>",
        escape(description, quote=True),
    )
    header = fragment("header.php").replace(
        "<?php require __DIR__ . '/menu.php'; ?>", fragment("menu.php")
    )
    if "<?php" in head + header:
        raise ValueError("PHP tag remains in a rendered fragment")

    html = (
        '<!doctype html>\n<html lang="cs">\n'
        + head
        + '\n<body>\n'
        + header
        + '\n'
        + fragment(body)
        + '\n'
        + fragment("footer.php")
        + '\n'
        + fragment("cart.php")
        + '\n<script defer src="assets/app.js"></script>'
        + '\n<script defer src="assets/product.js"></script>\n</body>\n</html>\n'
    )
    # The actual PHP templates keep their .php links. Only local HTML previews use .html.
    html = html.replace("produkt-topo-terraventure.php", "produkt-topo-terraventure.html")
    html = html.replace("index.php", "index.html")
    (ROOT / filename).write_text(html, encoding="utf-8")


if __name__ == "__main__":
    render(
        "index.html", "body.php", "Dobrodruzi.cz — vybavení na každou cestu",
        "Batohy, stany, spacáky a vybavení na cesty ven.",
    )
    render(
        "produkt-topo-terraventure.html", "product-body.php",
        "Topo Athletic Terraventure 5 Men's — dobrodruzi.cz",
        "Trailové boty Topo Athletic Terraventure 5 Men's v obchodě Dobrodruzi.",
    )
    print("Náhledy index.html a produkt-topo-terraventure.html byly vytvořeny.")
