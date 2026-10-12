#!/usr/bin/env python3
"""Reject German UI literals in production source.

The WordPress.org-facing source language is English. Historic German labels from
prerelease builds are allowed only as explicit compatibility fallbacks so old
orders can still be recognized after upgrading.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE_ROOTS = [ROOT / "includes", ROOT / "patsch9-rental-engine.php"]

GERMAN_CUES = re.compile(
    r"(?:[äöüÄÖÜß]|\b(?:Miet\w*|Kaution\w*|Abholung\w*|Rückgabe\w*|Lieferzone\w*|"
    r"Lieferung\w*|Einbehalt\w*|Vermietung\w*|Wartung\w*|Hinterlegung\w*|"
    r"Vertrags\w*|Prüf\w*|Buchung\w*|Gerät\w*|Übergabe\w*|Rückzahl\w*|MwSt\w*)\b)",
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
}

STRING_RE = re.compile(r"(?P<quote>['\"])(?P<body>(?:\\.|(?!\1).)*)(?P=quote)", re.DOTALL)


def iter_php_files():
    for root in SOURCE_ROOTS:
        if root.is_file():
            yield root
        else:
            yield from sorted(root.rglob("*.php"))


def decode_php_string(body: str, quote: str) -> str:
    if quote == "'":
        return body.replace("\\'", "'").replace("\\\\", "\\")
    return body.replace('\\"', '"').replace("\\\\", "\\")


def main() -> int:
    findings: list[tuple[str, int, str]] = []
    for path in iter_php_files():
        text = path.read_text(encoding="utf-8")
        for match in STRING_RE.finditer(text):
            value = decode_php_string(match.group("body"), match.group("quote"))
            if value in LEGACY_ALLOWED or not GERMAN_CUES.search(value):
                continue
            line = text.count("\n", 0, match.start()) + 1
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
