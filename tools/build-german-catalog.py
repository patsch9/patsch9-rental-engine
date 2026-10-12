#!/usr/bin/env python3
"""Build a complete bundled de_DE catalogue from the final English POT."""
from __future__ import annotations

import json
import re
from pathlib import Path

import polib
from transformers import AutoModelForSeq2SeqLM, AutoTokenizer

ROOT = Path(__file__).resolve().parents[1]
POT = ROOT / "languages" / "patsch9-rental-engine.pot"
MAP = ROOT / "tools" / "de-en-map.json"
OUT = ROOT / "languages" / "patsch9-rental-engine-de_DE.po"
MODEL_NAME = "Helsinki-NLP/opus-mt-en-de"

MANUAL_DE = {
    "Patsch9 Rental Engine for WooCommerce": "Patsch9 Rental Engine for WooCommerce",
    "Patrick Schmidt": "Patrick Schmidt",
    "https://github.com/patsch9": "https://github.com/patsch9",
    "https://github.com/patsch9/patsch9-rental-engine": "https://github.com/patsch9/patsch9-rental-engine",
    "Patsch9 Rental Engine migration stopped.": "Migration der Patsch9 Rental Engine wurde gestoppt.",
    "Both the historic and canonical rental database tables contain data. No automatic merge was attempted to avoid corrupting reservations or documents. Please create a database backup and resolve the duplicate table set before continuing.": "Sowohl die historischen als auch die neuen Vermietungstabellen enthalten Daten. Um Reservierungen oder Dokumente nicht zu beschädigen, wurde keine automatische Zusammenführung durchgeführt. Bitte erstelle ein Datenbank-Backup und löse den doppelten Tabellenbestand, bevor du fortfährst.",
    "requires WooCommerce.": "benötigt WooCommerce.",
    "requires WooCommerce 10.9.4 or newer.": "benötigt WooCommerce 10.9.4 oder höher.",
    "Rental": "Vermietung",
    "Security deposit": "Kaution",
    "Rental price": "Mietpreis",
    "Rental agreement": "Mietvertrag",
    "Rental terms": "Mietbedingungen",
    "Pickup and return by customer": "Abholung und Rückgabe durch Kunde",
    "Delivery by rental operator and return by customer": "Lieferung durch Vermieter und Rückgabe durch Kunde",
    "Delivery and collection by rental operator": "Lieferung und Abholung durch Vermieter",
}

PROTECTED_RE = re.compile(
    r"%(?:\d+\$)?[-+0-9.]*[bcdeEfFgGosuxX]|<[^>]+>|https?://[^\s)]+|`[^`]+`"
)


def protect(text: str) -> tuple[str, dict[str, str]]:
    mapping: dict[str, str] = {}

    def repl(match: re.Match[str]) -> str:
        key = f"ZXQPH{len(mapping)}QXZ"
        mapping[key] = match.group(0)
        return key

    return PROTECTED_RE.sub(repl, text), mapping


def restore(text: str, mapping: dict[str, str]) -> str:
    for key, value in mapping.items():
        text = text.replace(key, value).replace(key.lower(), value)
    return text


def normalize_german(text: str) -> str:
    replacements = {
        "Sicherheitsleistung": "Kaution",
        "Sicherheitsdepot": "Kaution",
        "Mietbetreiber": "Vermieter",
        "Vermietungsbetreiber": "Vermieter",
        "Mieteinheit": "Mietgerät",
        "Mietvertrag Vereinbarung": "Mietvertrag",
    }
    for old, new in replacements.items():
        text = text.replace(old, new)
    return text.strip()


def main() -> None:
    old_to_new = json.loads(MAP.read_text(encoding="utf-8"))
    new_to_old = {english: german for german, english in old_to_new.items()}
    pot = polib.pofile(str(POT))

    po = polib.POFile()
    po.metadata = {
        "Project-Id-Version": "Patsch9 Rental Engine for WooCommerce 2026.10.1",
        "Report-Msgid-Bugs-To": "https://wordpress.org/support/plugin/patsch9-rental-engine",
        "Language": "de_DE",
        "Language-Team": "German",
        "MIME-Version": "1.0",
        "Content-Type": "text/plain; charset=UTF-8",
        "Content-Transfer-Encoding": "8bit",
        "Plural-Forms": "nplurals=2; plural=(n != 1);",
        "X-Domain": "patsch9-rental-engine",
    }

    tokenizer = AutoTokenizer.from_pretrained(MODEL_NAME)
    model = AutoModelForSeq2SeqLM.from_pretrained(MODEL_NAME)

    missing: list[tuple[polib.POEntry, str, dict[str, str], bool]] = []

    for entry in pot:
        if not entry.msgid:
            continue
        new_entry = polib.POEntry(
            msgid=entry.msgid,
            msgid_plural=entry.msgid_plural,
            occurrences=entry.occurrences,
            comment=entry.comment,
            tcomment=entry.tcomment,
            flags=entry.flags,
            msgctxt=entry.msgctxt,
        )

        if entry.msgid_plural:
            singular_de = MANUAL_DE.get(entry.msgid) or new_to_old.get(entry.msgid)
            plural_de = MANUAL_DE.get(entry.msgid_plural) or new_to_old.get(entry.msgid_plural)
            if singular_de and plural_de:
                new_entry.msgstr_plural[0] = singular_de
                new_entry.msgstr_plural[1] = plural_de
            else:
                protected_s, ph_s = protect(entry.msgid)
                protected_p, ph_p = protect(entry.msgid_plural)
                missing.append((new_entry, protected_s, ph_s, False))
                missing.append((new_entry, protected_p, ph_p, True))
        else:
            german = MANUAL_DE.get(entry.msgid) or new_to_old.get(entry.msgid)
            if german:
                new_entry.msgstr = german
            else:
                protected, placeholders = protect(entry.msgid)
                missing.append((new_entry, protected, placeholders, False))
        po.append(new_entry)

    # Translate only entries that were already English in the old source or
    # were newly introduced during the refactor. Original German strings are
    # restored exactly from new_to_old above.
    for offset in range(0, len(missing), 12):
        batch = missing[offset : offset + 12]
        encoded = tokenizer(
            [row[1] for row in batch],
            return_tensors="pt",
            padding=True,
            truncation=True,
            max_length=512,
        )
        generated = model.generate(**encoded, max_new_tokens=512)
        translated = tokenizer.batch_decode(generated, skip_special_tokens=True)
        for (entry, _protected, placeholders, plural), german in zip(batch, translated):
            german = normalize_german(restore(german, placeholders))
            source = entry.msgid_plural if plural else entry.msgid
            old_printf = sorted(re.findall(r"%(?:\d+\$)?[-+0-9.]*[bcdeEfFgGosuxX]", source or ""))
            new_printf = sorted(re.findall(r"%(?:\d+\$)?[-+0-9.]*[bcdeEfFgGosuxX]", german))
            if old_printf != new_printf:
                raise RuntimeError(f"Placeholder mismatch: {source!r} -> {german!r}")
            if plural:
                entry.msgstr_plural[1] = german
                entry.msgstr_plural.setdefault(0, german)
            elif entry.msgid_plural:
                entry.msgstr_plural[0] = german
                entry.msgstr_plural.setdefault(1, german)
            else:
                entry.msgstr = german

    OUT.parent.mkdir(parents=True, exist_ok=True)
    po.save(str(OUT))
    untranslated = [entry.msgid for entry in po if not entry.translated()]
    if untranslated:
        raise RuntimeError(f"Untranslated German catalogue entries: {untranslated[:10]!r}")
    print(f"Created {OUT.relative_to(ROOT)} with {len(po)} translated entries")


if __name__ == "__main__":
    main()
