# Änderungsverlauf

Alle stabilen Releases ab Oktober 2026 verwenden das in [VERSIONING.md](VERSIONING.md) dokumentierte Schema `YYYY.M.PATCH`.

## 2026.10.1 – Noch nicht veröffentlicht

- WordPress.org-Review-Fixes: technischer `rmwc_`-Namespace statt des historischen Kurzpräfixes.
- Wiederaufnehmbare, gesperrte Migration bestehender Tabellen, Optionen sowie Post-, Order-Item- und HPOS-Metadaten mit geprüften Datenbankfehlern, Konflikterhalt und gezielter Cache-Invalidierung.
- Checkout-Sperre für betroffene Mietartikel während einer unvollständigen Migration; normale Produkte bleiben bestellbar.
- Alte Warenkörbe, Zubehörverknüpfungen und Kautionsauswahl beim Laden übernommen und serverseitig neu geprüft.
- Historische Buchungsintervall-Migration wieder kontrolliert aufgerufen.
- Deutsche Texte redaktionell überarbeitet; missverständliche englische Originaltexte korrigiert.
- Identischer strenger Plugin Check für Quellstand und ZIP; unbekannte oder fehlgeschlagene Checker-Ausgaben schlagen fehl.
- Upgrade-Regressionstests mit echter WordPress-/WooCommerce-Datenbank und frischer POT-/MO-Erzeugung in CI ergänzt.
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