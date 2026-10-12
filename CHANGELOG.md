# Änderungsverlauf

Alle stabilen Releases ab Oktober 2026 verwenden das in [VERSIONING.md](VERSIONING.md) dokumentierte Schema `YYYY.M.PATCH`.

## 2026.10.1 – Noch nicht veröffentlicht

- WordPress.org-Review-Fixes: technischer `rmwc_`-Namespace statt des historischen Kurzpräfixes.
- Sichere Migration bestehender Tabellen, Optionen sowie Post-, Order-Item- und HPOS-Metadaten.
- `Requires at least` auf Major/Minor korrigiert.
- Accounting-Bridge-Integration auf `patsacbr_payment_terms_addition` aktualisiert.
- Internationalisierung auf englische Originaltexte mit vollständigem deutschem `de_DE`-Katalog umgestellt.
- CI-Prüfungen für Sprachkataloge, englische Quelltexte, Legacy-Präfixe und den veralteten Accounting-Bridge-Hook ergänzt.

## 2026.10.0 – 2026-10-06

Erstes stabiles öffentliches Release.

### Enthalten

- Vollständiger Vermietungsworkflow für WooCommerce inklusive Classic Checkout und Checkout Blocks.
- Verfügbarkeit, Kapazität, Mietdauer, Wochentags-/Wochenendpreise und Buchungsvorlauf.
- Kautionen, Mietbedingungen, PDF-Dokumente, Gerätezuordnung sowie Übergabe-/Rückgabeprozesse.
- Zubehör, Verbrauchsmaterial und Liefer-/Abholoptionen.
- HPOS-Kompatibilität und serverseitige Checkout-/Preisvalidierung.
- Gehärtete Dokumentdownloads, Integritätsprüfungen und Workflow-Sperren.
- Optionale Google-Routes-Integration mit dokumentierter externer Datenübertragung.
- Explizite WooCommerce-Block-Abhängigkeiten zur Vermeidung von Load-Order-Races.
- Vereinheitlichte deutsche Projekt- und WordPress.org-Dokumentation.

## Interne Vorabphase

Die Entwicklungsstände bis einschließlich `2.0.29` waren interne Vorabversionen. Ihre Funktionen und Korrekturen sind in `2026.10.0` konsolidiert. Ab `2026.10.0` gilt ausschließlich das neue öffentliche Versionsschema.