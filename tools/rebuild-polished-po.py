#!/usr/bin/env python3
"""Rebuild de_DE against a freshly generated POT without losing translations."""
from __future__ import annotations

import json
from pathlib import Path
import polib

ROOT = Path(__file__).resolve().parents[1]
POT = ROOT / 'languages' / 'patsch9-rental-engine.pot'
PO = ROOT / 'languages' / 'patsch9-rental-engine-de_DE.po'
MAP = ROOT / 'tools' / 'polish-map.json'


def key(entry):
    return (entry.msgctxt or '', entry.msgid, entry.msgid_plural or '')


def main() -> None:
    payload = json.loads(MAP.read_text(encoding='utf-8'))
    mapping = payload['msgid_map']
    explicit = {}
    explicit.update(payload.get('new_translations', {}))
    explicit.update(payload.get('extra_translations', {}))

    old = polib.pofile(str(PO))
    old_by_key = {key(entry): entry for entry in old if entry.msgid and entry.translated()}
    # The reviewed wording map is one-to-one for the strings changed here.
    old_for_new = {new: old_msgid for old_msgid, new in mapping.items()}

    pot = polib.pofile(str(POT))
    rebuilt = polib.POFile()
    rebuilt.metadata = dict(old.metadata)
    rebuilt.metadata['Project-Id-Version'] = 'Patsch9 Rental Engine for WooCommerce 2026.10.1'
    rebuilt.metadata['Language'] = 'de_DE'
    rebuilt.metadata['X-Domain'] = 'patsch9-rental-engine'

    missing = []
    for source in pot:
        if not source.msgid:
            continue
        out = polib.POEntry(
            msgid=source.msgid,
            msgid_plural=source.msgid_plural,
            msgctxt=source.msgctxt,
            occurrences=source.occurrences,
            comment=source.comment,
            tcomment=source.tcomment,
            flags=source.flags,
        )
        same = old_by_key.get(key(source))
        if same:
            out.msgstr = same.msgstr
            out.msgstr_plural.update(same.msgstr_plural)
        elif source.msgid in old_for_new:
            prior_key = (source.msgctxt or '', old_for_new[source.msgid], source.msgid_plural or '')
            prior = old_by_key.get(prior_key)
            if not prior:
                # Context may have been added/removed only by extraction; use a
                # unique msgid match as a safe fallback.
                matches = [entry for entry in old if entry.msgid == old_for_new[source.msgid] and entry.translated()]
                prior = matches[0] if len(matches) == 1 else None
            if prior:
                out.msgstr = prior.msgstr
                out.msgstr_plural.update(prior.msgstr_plural)
        elif source.msgid in explicit and not source.msgid_plural:
            out.msgstr = explicit[source.msgid]

        if source.msgid_plural:
            if not out.msgstr_plural:
                missing.append(source.msgid)
        elif not out.msgstr:
            missing.append(source.msgid)
        rebuilt.append(out)

    if missing:
        raise RuntimeError('Missing German translations after polish: ' + repr(missing[:30]))

    rebuilt.save(str(PO))
    print(f'Rebuilt German catalogue with {len(rebuilt)} translated entries.')


if __name__ == '__main__':
    main()
