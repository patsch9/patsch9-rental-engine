#!/usr/bin/env python3
from pathlib import Path
import re

root = Path(__file__).resolve().parents[1]
po = root / 'languages' / 'patsch9-rental-engine-de_DE.po'
text = po.read_text(encoding='utf-8')
text = text.replace('Project-Id-Version: Patsch9 Rental Engine for WooCommerce 2026.10.1', 'Project-Id-Version: Patsch9 Rental Engine for WooCommerce 2026.10.3')
text = text.replace('Project-Id-Version: Patsch9 Rental Engine for WooCommerce 2026.10.2', 'Project-Id-Version: Patsch9 Rental Engine for WooCommerce 2026.10.3')

translations = {
    'Minimum rental period': 'Mindestmietdauer',
    'Blocked period created.': 'Sperrzeit angelegt.',
    'Customer': 'Kunde',
    'Calculating …': 'Berechne …',
    'Documentation signature': 'Dokumentationsunterschrift',
    'Page': 'Seite',
}

for msgid, msgstr in translations.items():
    quoted_id = re.escape('"' + msgid.replace('\\', '\\\\').replace('"', '\\"') + '"')
    pattern = re.compile(r'(^msgid ' + quoted_id + r'\nmsgstr )"[^"\n]*"$', re.MULTILINE)
    escaped = msgstr.replace('\\', '\\\\').replace('"', '\\"')
    text, count = pattern.subn(lambda m, value=escaped: m.group(1) + '"' + value + '"', text, count=1)
    if count != 1:
        raise SystemExit(f'Missing simple PO entry: {msgid}')

po.write_text(text, encoding='utf-8')
print('Verified/filled German translations for 2026.10.3.')
