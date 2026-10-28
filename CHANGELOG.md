# Änderungsverlauf

Alle stabilen Releases ab Oktober 2026 verwenden das in [VERSIONING.md](VERSIONING.md) dokumentierte Schema `YYYY.M.PATCH`.

## 2026.10.4 – 2026-10-28

- Mietartikel verwenden in Produktübersichten keinen direkten WooCommerce-„In den Warenkorb“-Request mehr. Die Aktion „Auswählen“ öffnet stattdessen die Produktdetailseite, auf der Mietzeitraum und Optionen vollständig erfasst werden.
- `ajax_add_to_cart` wird für Mietartikel deaktiviert; die bestehende Nonce- und Mietdatenvalidierung bleibt unverändert aktiv.
- Pflicht-Zubehörgruppen werden nach dem Hinzufügen zum Warenkorb weiterhin serverseitig geprüft. Fehlt später der letzte gültige Artikel einer Pflichtgruppe, wird der Warenkorb als ungültig markiert und der Checkout blockiert.
- Im klassischen Warenkorb wird die Entfernen-Aktion für den letzten gültigen Artikel einer Pflichtgruppe ausgeblendet. Bei Mehrfachauswahl bleiben einzelne Artikel entfernbar, solange mindestens eine gültige Auswahl bestehen bleibt.
- Die Pflicht-Zubehörprüfung greift auch bei Cart/Checkout Blocks und manipulierten Warenkorb-Requests über `woocommerce_check_cart_items`.
- Integrationstests für Pflicht-Zubehör, Entfernen im Warenkorb und Checkout-Integrität ergänzt.

## 2026.10.3 – 2026-10-15

- Produkte mit ausschließlich aktivierter Lieferung und späterer Abholung durch den Vermieter werden in Distanzberechnung und Warenkorbserverlogik korrekt akzeptiert.
- Öffentliche Rate-Limits prüfen zuerst das globale Budget; der optionale, weiterhin validierte Filter `rmwc_client_ip` unterstützt vertrauenswürdige Reverse-Proxy-Integrationen ohne Roh-IP-Speicherung.
- Die Wochenendpreisberechnung verwendet konsistent die WordPress-Zeitzone.
- Verbliebene deutsche UI-Literale in Administration, Erinnerungen, PDF-Dokumenten und JavaScript wurden auf englische Gettext-Quelltexte umgestellt.
- Der Source-Language-Check erfasst nun auch JavaScript und zusätzliche deutsche Wortstämme; historische interne Statuswerte bleiben explizit als Kompatibilitätsdaten erlaubt.
- Regressionstests für Delivery-Return-Konfiguration und die Rate-Limit-/Client-IP-Härtung ergänzt.

## 2026.10.2 – 2026-10-13

- WordPress.org-Vorprüfung: WooCommerce-Abhängigkeitshinweise auf die Pluginverwaltung und berechtigte Benutzer begrenzt.
- Kautionseinstellungen prüfen den eigenen Nonce zusätzlich direkt im Speicherhandler.
- Negative Integrationstests für Rechte-/Nonce-Prüfungen der Admin- und Produktaktionen ergänzt.
- Deutsche PO/MO-Dateien bleiben im einheitlichen GitHub-/WordPress.org-Paket enthalten, bis ein deutsches WordPress-Sprachpaket verfügbar ist.

- Kautionsauswahl im Checkout Block wird ohne passende Produkte nicht registriert; die unzulässige Konfiguration hidden=true entfällt.
- Während einer laufenden Store-API-Anfrage geänderte Kautionswahl oder Zahlungsart wird anschließend zuverlässig nachgesendet.
- Fehlgeschlagene unveränderte Anfragen lösen keine automatische Wiederholungsschleife aus.
- Regressionstests für Auswahlwechsel, Zahlungsarten, konkurrierende Anfragen und Fehler ergänzt.

## 2026.10.1 – 2026-10-12

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