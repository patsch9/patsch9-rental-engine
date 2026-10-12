#!/usr/bin/env python3
"""One-off audit helper for the WordPress.org i18n refactor.

The script only reports candidates. It never rewrites source files.
"""
from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "tools" / "i18n-candidates.json"
TEXT_DOMAIN = "patsch9-rental-engine"

GERMAN_CUES = re.compile(
    r"(?:[äöüÄÖÜß]|\b(?:der|die|das|und|oder|bitte|keine|kein|miet\w*|buch\w*|abhol\w*|"
    r"rück\w*|liefer\w*|einstellungen?|produkt\w*|kaution\w*|kunde\w*|gerät\w*|tage?|"
    r"woche\w*|monat\w*|preis\w*|verfüg\w*|speichern|gespeichert|löschen|bearbeiten|hinweis|"
    r"grund|notiz|zahlung\w*|erinnerung\w*|bestellung\w*|anzahl|status|aktiv|betreff|auswählen|"
    r"wählen|montag|dienstag|mittwoch|donnerstag|freitag|samstag|sonntag|stunden?|minuten?|"
    r"adresse\w*|entfernung\w*|transport\w*|übergabe\w*|vollständig|einbehalten|erhalten|offen|"
    r"erlassen|fehler|nicht|konnte|wurde|werden|für|mit|ohne|dies|dieser|diese|dieses)\b)",
    re.IGNORECASE,
)

# PHP/JS quoted strings. This intentionally ignores heredocs/nowdocs; those are
# surfaced separately by the raw cue scan below if they contain German text.
STRING_RE = re.compile(r"(?P<quote>['\"])(?P<body>(?:\\.|(?!\1).)*)(?P=quote)", re.DOTALL)
GETTEXT_NEARBY = re.compile(
    r"(?:__|_e|_x|_ex|_n|_nx|esc_html__|esc_html_e|esc_html_x|esc_attr__|esc_attr_e|esc_attr_x)\s*\($",
    re.DOTALL,
)


def decode_literal(body: str, quote: str) -> str:
    # Enough for audit output; retain unknown escapes literally.
    try:
        if quote == "'":
            return body.replace("\\'", "'").replace("\\\\", "\\")
        return bytes(body, "utf-8").decode("unicode_escape")
    except Exception:
        return body


def classify(source: str, start: int, path: Path) -> str:
    before = source[max(0, start - 140):start]
    if path.suffix == ".js":
        return "javascript"
    compact = re.sub(r"\s+", " ", before)
    if re.search(r"(?:__|_e|_x|_ex|_n|_nx|esc_html__|esc_html_e|esc_html_x|esc_attr__|esc_attr_e|esc_attr_x)\s*\([^()]{0,80}$", compact):
        return "gettext"
    if re.search(r"new\s+WP_Error\s*\([^,]+,\s*$", compact):
        return "wp_error_message"
    if re.search(r"wc_add_notice\s*\(\s*$", compact):
        return "wc_notice"
    if re.search(r"add_order_note\s*\(\s*$", compact):
        return "order_note"
    if re.search(r"['\"](?:label|description|placeholder|title|name)['\"]\s*=>\s*$", compact):
        return "ui_array_value"
    if re.search(r"(?:submit_button|add_submenu_page|add_meta_box)\s*\([^)]*$", compact):
        return "ui_function_argument"
    return "php_string"


def audit_file(path: Path) -> list[dict]:
    source = path.read_text(encoding="utf-8")
    rel = str(path.relative_to(ROOT)).replace("\\", "/")
    rows: list[dict] = []
    for match in STRING_RE.finditer(source):
        quote = match.group("quote")
        value = decode_literal(match.group("body"), quote)
        if not value.strip() or not GERMAN_CUES.search(value):
            continue
        line = source.count("\n", 0, match.start()) + 1
        line_start = source.rfind("\n", 0, match.start()) + 1
        line_end = source.find("\n", match.end())
        if line_end < 0:
            line_end = len(source)
        context = source[line_start:line_end].strip()
        rows.append(
            {
                "file": rel,
                "line": line,
                "kind": classify(source, match.start(), path),
                "value": value,
                "context": context[:600],
            }
        )
    return rows


def main() -> None:
    candidates: list[dict] = []
    for path in sorted(ROOT.rglob("*")):
        if not path.is_file() or path.suffix not in {".php", ".js"}:
            continue
        rel_parts = set(path.relative_to(ROOT).parts)
        if rel_parts & {".git", ".github", "node_modules", "vendor", "tests"}:
            continue
        candidates.extend(audit_file(path))

    unique_values = sorted({row["value"] for row in candidates}, key=str.casefold)
    payload = {
        "text_domain": TEXT_DOMAIN,
        "candidate_occurrences": len(candidates),
        "unique_candidate_values": len(unique_values),
        "unique_values": unique_values,
        "occurrences": candidates,
    }
    OUT.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"Wrote {OUT.relative_to(ROOT)} with {len(candidates)} occurrences / {len(unique_values)} unique values")


if __name__ == "__main__":
    main()
