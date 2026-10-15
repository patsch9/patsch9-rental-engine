#!/usr/bin/env python3
from pathlib import Path
import re

root = Path(__file__).resolve().parents[1]
po = root / 'languages' / 'patsch9-rental-engine-de_DE.po'
text = po.read_text(encoding='utf-8')
text = text.replace('Project-Id-Version: Patsch9 Rental Engine for WooCommerce 2026.10.1', 'Project-Id-Version: Patsch9 Rental Engine for WooCommerce 2026.10.3')

translations = {
    'Minimum rental period': 'Mindestmietdauer',
    'Blocked period created.': 'Sperrzeit angelegt.',
    'Customer': 'Kunde',
    'Calculating …': 'Berechne …',
    'Documentation signature': 'Dokumentationsunterschrift',
    'Page': 'Seite',
}

for msgid, msgstr in translations.items():
    pattern = re.compile(r'(^msgid ' + re.escape(repr(msgid).replace("'", '"')) + r'\nmsgstr )""$', re.MULTILINE)
    replacement = lambda m, value=msgstr: m.group(1) + '"' + value.replace('\\', '\\\\').replace('"', '\\"') + '"'
    text, count = pattern.subn(replacement, text, count=1)
    if count != 1:
        # WP-CLI emits normal quoted PO strings; give a useful failure if the format changes.
        needle = f'msgid "{msgid}"\nmsgstr ""'
        if needle not in text:
            raise SystemExit(f'Missing untranslated PO entry: {msgid}')
        text = text.replace(needle, f'msgid "{msgid}"\nmsgstr "{msgstr}"', 1)

po.write_text(text, encoding='utf-8')
print('Filled German translations for 2026.10.3.')
