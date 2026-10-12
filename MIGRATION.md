# Migration auf 2026.10.1

Die öffentliche Plugin-Version (`2026.10.1`) und die interne Schema-Version sind getrennt. Unterstützt werden `2026.10.0` und historische Vorabinstallationen derselben Plugin-Linie. Der Ordner bleibt `patsch9-rental-engine`.

## Vorbereitung und Reihenfolge

1. Datenbank und `wp-content` vollständig sichern; zuerst eine Staging-Kopie aktualisieren.
2. Bei gemeinsamer Nutzung zunächst die **Accounting Bridge auf 2026.10.5** aktualisieren. Diese erkennt die alten und die neuen Rental-Metadaten. Erst danach Rental aktualisieren.
3. Rental auf `2026.10.1` aktualisieren. Niemals zwei Versionen der Plugin-Linie gleichzeitig aktivieren.
4. Produktkonfiguration, vorhandene Buchungen, Vertrags- und Kautionsdokumente kontrollieren; einen vor dem Update gefüllten Warenkorb bis zum Checkout testen.
5. Classic Checkout und Checkout Blocks, Zubehör, Lieferkosten, Kautionsauswahl und Lexware-Export einer Kaution prüfen. Eine rückzahlbare Kaution muss im Export eine informative Textposition bleiben.

## Datenumzug

- Eigene Tabellen `clr_bookings`, `clr_terms_versions`, `clr_documents`, `clr_assets` und `clr_asset_assignments` werden unter dem jeweiligen WordPress-Tabellenpräfix atomar auf `rmwc_*` umbenannt.
- Optionen `clr_*` werden einschließlich ihrer serialisierten Werte und Autoload-Einstellungen übernommen.
- Private Metadaten `_clr_*` werden in Postmeta, WooCommerce-Bestellpositionen und HPOS auf `_rmwc_*` umgestellt.
- Alte Session- und persistente Warenkörbe werden beim Laden normalisiert: Mietdaten, Zubehörverknüpfungen und Kautionsauswahl werden übernommen. Preise, Kaution und Transport werden anhand der aktuellen Produktkonfiguration serverseitig neu berechnet. Ungültige Mietdaten sperren den Checkout und müssen durch erneutes Hinzufügen des Artikels korrigiert werden.
- Alte reine Datumsbuchungen erhalten nach der Schema-Migration Uhrzeiten. Das historische inklusive Enddatum wird in eine Rückgabe am Folgetag umgerechnet. Ungültige Altbuchungen werden zur manuellen Prüfung erhalten.
- Bestehende Vertrags-/Dokument-Snapshots und interne Statuswerte werden nicht übersetzt oder inhaltlich umgeschrieben.

## Fehler und Wiederaufnahme

Die Migration verwendet eine pro Datenbank und Website abgegrenzte MySQL/MariaDB-Sperre. Die Namespace-Migrationsversion `2` wird erst nach allen erfolgreichen Schritten gespeichert. Bereits erfolgreiche Schritte bleiben nach einem Fehler erhalten; der nächste Request setzt am verbliebenen Zustand an.

Eine fehlgeschlagene Existenz- oder COUNT-Abfrage gilt niemals als Beleg für eine leere Tabelle. Ausschließlich nach erfolgreichen Zählungen darf eine leere doppelte Tabelle entfernt werden. Zwei befüllte Tabellen oder unterschiedliche alte/neue Options- bzw. Metawerte werden nicht automatisch zusammengeführt. Beide Bestände bleiben zur manuellen Auflösung erhalten.

Bei einem Fehler werden Mietartikel und zugehörige Warenkörbe in Classic Checkout und Store API gesperrt. Normale Produkte bleiben bestellbar, sofern ihre Daten zuverlässig gelesen werden können. Ein Administrator sieht eine Fehlermeldung. Zuerst Datenbank-/Serverfehler und gegebenenfalls beide Tabellenbestände bzw. kollidierende Werte anhand des Backups prüfen. Migrationsmarker nicht manuell auf „fertig“ setzen.

Die ältere Buchungsintervall-Migration läuft ebenfalls gesperrt, prüft jeden Schreibvorgang und arbeitet höchstens 10.000 Datensätze pro Request ab. Bis zu ihrem vollständigen Abschluss bleibt der Miet-Checkout gesperrt.

## Caches und Sprache

Die Migration invalidiert betroffene WordPress-Metadaten-/Options-Caches, WooCommerce-Produkt-/Positions-Caches sowie HPOS-Datastore- und Bestellobjekt-Caches vor und nach SQL-Änderungen. Es wird kein globaler Cache-Flush vorausgesetzt. Nach dem Update sollten Seiten- und CDN-Caches regulär geleert werden, damit das aktuelle Formular und die passenden JavaScript-Actions ausgeliefert werden.

Übersetzbare Quelltexte sind Englisch; `languages/` enthält POT sowie die geprüfte deutsche PO/MO. Die GitHub-Dokumentation ist Deutsch, die WordPress.org-Readme Englisch. Kundeneigene Texte und historische akzeptierte Bedingungen bleiben erhalten.

## Tests

`tests/test-upgrade.php` ist ausschließlich für eine ausdrücklich freigegebene, wegwerfbare WordPress-/WooCommerce-Testdatenbank vorgesehen (`RMWC_RUN_UPGRADE_TESTS=1`). Der Test verändert die Miettabellen. Die CI führt ihn in `wp-env` aus. Er prüft insbesondere Fehler beim COUNT/SHOW/INSERT/UPDATE/DELETE/DROP/RENAME, Wiederaufnahme, Sperren, Cache-Invalidierung, Warenkörbe, HPOS und die alte Intervallumrechnung. Der Accounting-Export besitzt zusätzliche Regressionstests im Bridge-Repository.

## Durchgeführte Prüfung am 12.10.2026

- 52 Upgrade-/Checkout-Regressionen mit WordPress 7.1.3, WooCommerce 11.2.1 und echter MariaDB 10.11.14 erfolgreich. Enthalten: zwei konkurrierende Datenbankverbindungen, gezielte SQL-Fehler, Wiederaufnahme, vorgefüllte WordPress-/WooCommerce-Caches, HPOS-Datastore-Caching, Warenkorb und migrierter Kautionsexport mit dem neuen Bridge-Code.
- Accounting Bridge: 9 zusätzliche Export-Regressionen erfolgreich (neue/alte Kautionskennzeichen, normale Gebühren, gespeicherte Zahlungsbedingungen und Vorrang kanonischer Werte).
- WordPress Plugin Check 2.1.0 für Quellstand und gebautes Release-Paket: keine Fehler oder Warnungen; keine Allowlist mehr erforderlich.
- PHP-Syntax unter 8.2.33, 8.3.6 und 8.4.25 sowie JavaScript-Syntax geprüft.
- 773 deutsche gettext-Einträge: aktuelle Quelltexte erneut per WP-CLI extrahiert, PO/MO, Pluralformen und Platzhalter geprüft. 56 englische Originaltexte und 70 deutsche Übersetzungen wurden gezielt redaktionell überarbeitet.
- Die tatsächlichen GitHub-Workflows müssen nach dem Push laufen. Diese lokalen Ergebnisse sind keine Aussage über einen bereits abgeschlossenen GitHub-CI-Lauf.

Die Tests verwenden eine Wegwerfdatenbank, keine Kopie eines produktiven Shops. Zahlungsanbieter-Rückzahlungen, SMTP-Zustellung und Google Routes wurden nicht extern ausgelöst. Vor dem produktiven Update bleibt der Upgrade-Test mit dem tatsächlichen Bestand und dem eingesetzten Cache-Backend erforderlich.
