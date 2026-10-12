#!/usr/bin/env python3
"""Build a German->English gettext map for the one-off i18n refactor.

Machine translation is used only for source-language conversion. The original
German msgid is retained verbatim as the de_DE translation. Critical rental
terminology is overridden explicitly.
"""
from __future__ import annotations

import json
import re
from pathlib import Path

import polib
from transformers import AutoModelForSeq2SeqLM, AutoTokenizer

ROOT = Path(__file__).resolve().parents[1]
POT = ROOT / "tools" / "current-source.pot"
OUT = ROOT / "tools" / "de-en-map.json"
MODEL_NAME = "Helsinki-NLP/opus-mt-de-en"

GERMAN_RE = re.compile(
    r"(?:[äöüÄÖÜß]|\b(?:und|oder|für|mit|ohne|nicht|keine|kein|bitte|miet\w*|vermiet\w*|"
    r"buchung\w*|kaution\w*|abholung|rückgabe|lieferung|kunde\w*|gerät\w*|einstellungen|"
    r"speichern|gespeichert|bearbeiten|löschen|wählen|auswählen|verfügbar|preis\w*|tage?|"
    r"woche\w*|monat\w*|adresse|bestellung\w*|zahlung\w*|erinnerung\w*|übergabe\w*)\b)",
    re.IGNORECASE,
)

# Exact translations for terminology that generic MT tends to render poorly.
MANUAL = {
    "Vermietung": "Rental",
    "Kaution": "Security deposit",
    "Mietpreis": "Rental price",
    "Mietvertrag": "Rental agreement",
    "Mietbedingungen": "Rental terms",
    "Abholung und Rückgabe durch Kunde": "Pickup and return by customer",
    "Lieferung durch Vermieter und Rückgabe durch Kunde": "Delivery by rental operator and return by customer",
    "Lieferung und Abholung durch Vermieter": "Delivery and collection by rental operator",
    "Keine Abholung": "No pickup",
    "Kein Mietbeginn an diesem Tag": "No rental start on this day",
    "Keine Rückgabe": "No return",
    "Keine Rückgabe an diesem Tag": "No return on this day",
    "Tagespreis": "Daily price",
    "Standardpreis": "Standard price",
    "Leer = Standard-Mietpreis": "Empty = standard rental price",
    "je Gerät": "per rental unit",
    "Buchung nicht gefunden.": "Booking not found.",
    "Buchung wurde aktualisiert.": "Booking updated.",
    "Manuelle Buchung/Sperre wurde gelöscht.": "Manual booking/block was deleted.",
    "Wochentage & Sperrtage": "Weekdays & blocked days",
    "Einfache Zusatzoptionen (ohne eigenen WooCommerce-Artikel)": "Simple add-ons (without a separate WooCommerce product)",
    "Dieses WooCommerce-Produkt als Mietartikel anbieten.": "Offer this WooCommerce product as a rental item.",
    "Vermietungs- und Gerätebuchungen für WooCommerce mit Verfügbarkeit, Preisen, Kautionen, Bedingungen, Dokumenten und Übergabe-/Rückgabe-Workflow.": "Rental and equipment booking workflows for WooCommerce with availability, pricing, security deposits, terms, documents, and handover/return management.",
}

PROTECTED_RE = re.compile(
    r"%(?:\d+\$)?[-+0-9.]*[bcdeEfFgGosuxX]|<[^>]+>|https?://[^\s)]+|`[^`]+`"
)


def is_german(text: str) -> bool:
    return bool(GERMAN_RE.search(text))


def protect(text: str) -> tuple[str, dict[str, str]]:
    mapping: dict[str, str] = {}

    def repl(match: re.Match[str]) -> str:
        key = f"ZXQPH{len(mapping)}QXZ"
        mapping[key] = match.group(0)
        return key

    return PROTECTED_RE.sub(repl, text), mapping


def restore(text: str, mapping: dict[str, str]) -> str:
    for key, value in mapping.items():
        text = text.replace(key, value)
        text = text.replace(key.lower(), value)
    return text


def normalize_translation(text: str) -> str:
    replacements = {
        "landlord": "rental operator",
        "Landlord": "Rental operator",
        "tenant": "rental customer",
        "Tenant": "Rental customer",
        "rental device": "rental unit",
        "Rental device": "Rental unit",
        "hand-over": "handover",
        "Hand-over": "Handover",
    }
    for old, new in replacements.items():
        text = text.replace(old, new)
    return text.strip()


def main() -> None:
    catalogue = polib.pofile(str(POT))
    originals = sorted(
        {
            value
            for entry in catalogue
            for value in (entry.msgid, entry.msgid_plural or "")
            if value and is_german(value)
        },
        key=str.casefold,
    )

    tokenizer = AutoTokenizer.from_pretrained(MODEL_NAME)
    model = AutoModelForSeq2SeqLM.from_pretrained(MODEL_NAME)

    result: dict[str, str] = {}
    pending: list[tuple[str, str, dict[str, str]]] = []
    for original in originals:
        if original in MANUAL:
            result[original] = MANUAL[original]
            continue
        protected, placeholders = protect(original)
        pending.append((original, protected, placeholders))

    for offset in range(0, len(pending), 12):
        batch = pending[offset : offset + 12]
        encoded = tokenizer(
            [row[1] for row in batch],
            return_tensors="pt",
            padding=True,
            truncation=True,
            max_length=512,
        )
        generated = model.generate(**encoded, max_new_tokens=512)
        translated = tokenizer.batch_decode(generated, skip_special_tokens=True)
        for (original, _protected, placeholders), english in zip(batch, translated):
            english = normalize_translation(restore(english, placeholders))
            if not english:
                raise RuntimeError(f"Empty translation for: {original!r}")
            # printf placeholders are API contracts. Refuse a translation that
            # changes them instead of committing a latent runtime bug.
            old_printf = sorted(re.findall(r"%(?:\d+\$)?[-+0-9.]*[bcdeEfFgGosuxX]", original))
            new_printf = sorted(re.findall(r"%(?:\d+\$)?[-+0-9.]*[bcdeEfFgGosuxX]", english))
            if old_printf != new_printf:
                raise RuntimeError(f"Placeholder mismatch: {original!r} -> {english!r}")
            result[original] = english

    OUT.write_text(json.dumps(result, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(f"Created {OUT.relative_to(ROOT)} with {len(result)} source translations")


if __name__ == "__main__":
    main()
