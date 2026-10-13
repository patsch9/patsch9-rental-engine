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

GitHub und WordPress.org verwenden dasselbe installierbare ZIP mit der deutschen PO-/MO-Übersetzung. Der Hinweis der automatisierten Vorprüfung zu Sprachdateien ist eine Empfehlung zur Nutzung von translate.wordpress.org, kein ausdrücklich genanntes Verbot. Die gebündelte deutsche Übersetzung bleibt für sofortige deutsche Bedienbarkeit erhalten, bis ein deutsches Sprachpaket verfügbar ist. Ein späterer Wechsel zu WordPress-Sprachpaketen erfolgt erst nach verifizierter Bereitstellung; die PO-Datei dient dann als Importgrundlage. Der Release-Workflow prüft und veröffentlicht ein gemeinsames Paket mit Prüfsumme.
