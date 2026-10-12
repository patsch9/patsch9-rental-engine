#!/usr/bin/env python3
"""Report likely German user-facing source text outside language catalogues.

This audit is intentionally broad. It reports line snippets for human review and
never rewrites source code. Historic compatibility values can be allowlisted
explicitly after review.
"""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
EXCLUDED = {'.git', '.github', 'languages', 'tools', 'tests', 'node_modules', 'vendor'}
SUFFIXES = {'.php', '.js'}

# Strong signals first (umlauts and domain-specific German/UI words). Generic
# articles such as "die" are intentionally omitted to keep false positives
# manageable, while short labels used by the rental UI are included explicitly.
GERMAN = re.compile(
    r'(?:[äöüÄÖÜß]|\b(?:'
    r'Vermiet(?:ung|ungen|er|ers)?|Miet(?:e|preis|preise|gerät|geräte|dauer|tag|tage|tagen|bedingungen|vertrag|artikel|produkt|zeitraum)|'
    r'Kaution(?:en|sbeleg|shinterlegung)?|Abholung|Rückgabe|Übergabe|Lieferung|Buchung(?:en)?|Sperrtag(?:e)?|Wochentag(?:e)?|'
    r'Preisstaffel(?:n)?|Paketpreis|Standardpreis|Tagespreis|Vorausbuchung|Verfügbarkeit|verfügbar|Kunde(?:n)?|Gerät(?:e)?|'
    r'Inventarnummer|Seriennummer|Schäden|Einstellungen|Speichern|gespeichert|Bearbeiten|bearbeiten|Löschen|löschen|'
    r'Auswählen|auswählen|Bitte|Keine|Kein|Fehler|Erinnerung(?:en)?|Zahlung(?:en)?|Bestellung(?:en)?|Rechnung(?:en)?|'
    r'Rückerstattung(?:en)?|Transportkosten|Lieferadresse|Gruppe|Gruppenname|Bezeichnung|Berechnung|Auswahlart|Pflichtauswahl|'
    r'Zusatzoption(?:en)?|Zubehör(?:gruppe|produkt|artikel)?|einmalig|je|pro|bis|ab|von|anzahl|hinweis|aktiv|deaktiviert|'
    r'Entfernung|Fahrstrecke|Fahrpreis|Hinfahrt|Rückfahrt|Grunddaten|Zeiten|Abrechnung|Quelle|Lager|Bestand|Artikel|'
    r'Montag|Dienstag|Mittwoch|Donnerstag|Freitag|Samstag|Sonntag|Abbrechen|Zurück|Weiter|Schließen|Öffnen|Speichern'
    r')\b)',
    re.IGNORECASE,
)

# These source fragments are historic compatibility values, not UI copy. They
# must remain readable for old orders and are intentionally not translated.
ALLOW = (
    "Kautionshinterlegung",  # Historic WooCommerce order-item display meta key.
)


def main() -> None:
    findings: list[tuple[str, int, str]] = []
    for path in sorted(ROOT.rglob('*')):
        if not path.is_file() or path.suffix.lower() not in SUFFIXES:
            continue
        parts = set(path.relative_to(ROOT).parts)
        if parts & EXCLUDED:
            continue
        rel = str(path.relative_to(ROOT)).replace('\\', '/')
        for lineno, line in enumerate(path.read_text(encoding='utf-8').splitlines(), 1):
            if not GERMAN.search(line):
                continue
            stripped = line.strip()
            if not stripped:
                continue
            # Ignore developer comments that do not render at runtime.
            if stripped.startswith(('//', '/*', '*', '#')):
                continue
            if any(value in line for value in ALLOW):
                continue
            findings.append((rel, lineno, stripped[:500]))

    print(f'German source-language candidates: {len(findings)}')
    for rel, lineno, text in findings:
        print(f'{rel}:{lineno}: {text}')


if __name__ == '__main__':
    main()
