#!/usr/bin/env python3
from pathlib import Path
p = Path(__file__).resolve().parent / 'apply-2026-10-3.py'
text = p.read_text(encoding='utf-8')
old = "replace(plugin, \"            if ( ! $this->request_rate_allowed( 'availability', 120 ) || ! $this->global_rate_allowed( 'availability', 1200 ) ) {\", \"            if ( ! $this->public_request_rate_allowed( 'availability', 1200, 120 ) ) {\")"
new = "replace(plugin, \"        if ( ! $this->request_rate_allowed( 'availability', 120 ) || ! $this->global_rate_allowed( 'availability', 1200 ) ) {\", \"        if ( ! $this->public_request_rate_allowed( 'availability', 1200, 120 ) ) {\")"
if text.count(old) != 1:
    raise SystemExit('Availability patch line was not found exactly once.')
p.write_text(text.replace(old, new, 1), encoding='utf-8')
