#!/usr/bin/env python3
"""Reject German UI literals in production PHP and JavaScript source.

The WordPress.org-facing source language is English. Historic German labels from
prerelease builds are allowed only as explicit compatibility fallbacks so old
orders can still be recognized after upgrading.

This deliberately scans PHP string literals rather than comments/documentation.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE_ROOTS = [ROOT / "includes", ROOT / "assets", ROOT / "patsch9-rental-engine.php"]

GERMAN_CUES = re.compile(
    r"(?:[äöüÄÖÜß]|\b(?:Miet\w*|Kaution\w*|Abholung\w*|Rückgabe\w*|Lieferzone\w*|"
    r"Lieferung\w*|Einbehalt\w*|Vermietung\w*|Wartung\w*|Hinterlegung\w*|"
    r"Vertrags\w*|Prüf\w*|Buchung\w*|Gerät\w*|Übergabe\w*|Rückzahl\w*|MwSt\w*|"
    r"Mindest\w*|Wochentag\w*|Standardtarif\w*|Sperrzeit\w*|Produkt\b|"
    r"Akzeptiert\b|Dokumentationsunterschrift\w*|Seite\b|Guten\s+Tag|Berechne\w*)\b)",
    re.IGNORECASE,
)

# Compatibility-only literals used to recognize data written by prerelease
# versions. Do not add new UI copy here.
LEGACY_ALLOWED = {
    "Kaution – ",
    "Lieferung – ",
    "Transport – ",
    "Abholung",
    "Rückgabe",
    # Internal prerelease status enum retained to avoid rewriting historic orders.
    "einbehalten",
    "offen",
    # Historic order-item label recognized alongside the current translated key.
    "Kautionshinterlegung",
    # Compatibility parser for Germanized/WooCommerce tax labels; not UI output.
    r"/(?:MwSt\.?|Umsatzsteuer|Mehrwertsteuer|VAT)/iu",
}


def iter_php_files():
    for root in SOURCE_ROOTS:
        if root.is_file():
            yield root
        else:
            for pattern in ("*.php", "*.js"):
                yield from sorted(root.rglob(pattern))


def php_string_literals(text: str):
    """Yield (line, quote, raw body) while skipping PHP comments."""
    i = 0
    line = 1
    length = len(text)

    while i < length:
        ch = text[i]

        if ch == "\n":
            line += 1
            i += 1
            continue

        if text.startswith("//", i) or ch == "#":
            newline = text.find("\n", i)
            if newline < 0:
                return
            i = newline
            continue

        if text.startswith("/*", i):
            end = text.find("*/", i + 2)
            if end < 0:
                return
            line += text.count("\n", i, end + 2)
            i = end + 2
            continue

        if ch not in {"'", '"'}:
            i += 1
            continue

        quote = ch
        start_line = line
        i += 1
        body: list[str] = []
        while i < length:
            ch = text[i]
            if ch == "\\" and i + 1 < length:
                body.append(ch)
                body.append(text[i + 1])
                if text[i + 1] == "\n":
                    line += 1
                i += 2
                continue
            if ch == quote:
                i += 1
                yield start_line, quote, "".join(body)
                break
            if ch == "\n":
                line += 1
            body.append(ch)
            i += 1


def decode_php_string(body: str, quote: str) -> str:
    if quote == "'":
        return body.replace("\\'", "'").replace("\\\\", "\\")
    return body.replace('\\"', '"').replace("\\\\", "\\")


def main() -> int:
    findings: list[tuple[str, int, str]] = []
    for path in iter_php_files():
        text = path.read_text(encoding="utf-8")
        for line, quote, body in php_string_literals(text):
            value = decode_php_string(body, quote)
            if value in LEGACY_ALLOWED or not GERMAN_CUES.search(value):
                continue
            findings.append((str(path.relative_to(ROOT)), line, value.replace("\n", "\\n")))

    if findings:
        print("German source-language candidates remain in production code:", file=sys.stderr)
        for path, line, value in findings:
            print(f"{path}:{line}: {value}", file=sys.stderr)
        print(
            "Only historic compatibility literals may be allowlisted; new UI strings must use English msgids.",
            file=sys.stderr,
        )
        return 1

    print("Source-language check passed: no unexpected German UI literals found.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
