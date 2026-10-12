# Dokumentation

- [README.md](README.md) – Projektübersicht und Bedien-/Integrationshinweise für GitHub.
- [readme.txt](readme.txt) – WordPress.org-kompatible Plugin-Beschreibung.
- [CHANGELOG.md](CHANGELOG.md) – Öffentlicher Änderungsverlauf.
- [VERSIONING.md](VERSIONING.md) – Verbindliches Versionsschema und Release-Regeln.
- [MIGRATION.md](MIGRATION.md) – Hinweise für Updates aus älteren Entwicklungsständen.
- [SECURITY.md](SECURITY.md) – Sicherheitsrichtlinie und Meldeweg.
- [LICENSE](LICENSE) – Lizenztext.


## Prüfungen der Korrekturen vom 12.10.2026

`node tests/test-checkout-sync.cjs` führt das tatsächliche Checkout-JavaScript mit kontrollierten DOM-Ereignissen und verzögerten Store-API-Antworten aus. Die Datenbank-Tests prüfen zusätzlich die Feldregistrierung ohne Kautionswahlprodukte.


## WordPress.org-Vorprüfung vom 12.10.2026

Die automatisierte Nachricht zur Version 2026.10.1 nennt keine konkrete unsichere Funktion. Alle Browser-Schreibpfade wurden auf Nonce-Prüfung, notwendige Berechtigungen und den ausführenden WooCommerce-/Store-API-Hook geprüft. Produktaktionen prüfen edit_post und den produktspezifischen Nonce; privilegierte Admin-Aktionen prüfen manage_woocommerce und aktionsbezogene Nonces. Öffentliche Formular-/AJAX-Aktionen bleiben für Gäste verfügbar und prüfen ihre Formular-Nonces. Geschützte Dokumentdownloads erlauben entweder eine berechtigte Sitzung mit Nonce oder ein zeitlich begrenztes HMAC-Token mit geprüfter Bestellzuordnung. Reine Anzeige-/Filterparameter verändern keine Daten und benötigen dafür keinen Aktions-Nonce.

`tests/test-review-security.php` prüft die tatsächlichen Handler mit Gästen, Abonnenten, fehlenden und falschen Nonces in einer wegwerfbaren WordPress-/WooCommerce-Datenbank. Kautionseinstellungen verifizieren den Nonce jetzt zusätzlich direkt im Handler, auch wenn sie über ihren Hook aufgerufen werden. Die WooCommerce-Abhängigkeitshinweise sind auf die Pluginverwaltung und Benutzer mit activate_plugins beschränkt.

`tools/build-wordpress-org-package.py QUELLE.zip ZIEL.zip` erzeugt aus dem installierbaren GitHub-ZIP das Einreichungspaket. Es entfernt ausschließlich PO/MO-Dateien und prüft Archivpfade, Pflichtdateien und Symlinks. PHP, JavaScript, Templates und alle anderen Nutzdaten bleiben identisch. Der Release-Workflow prüft beide Varianten mit WordPress Plugin Check und veröffentlicht beide ZIPs mit Prüfsummen. Für WordPress.org werden Übersetzungen nach Freischaltung über translate.wordpress.org verwaltet; die vorhandene deutsche PO-Datei bleibt im GitHub-Repository als Importgrundlage erhalten. Ohne installierten deutschen Sprachpack zeigt das WordPress.org-Paket die englischen Originaltexte.
