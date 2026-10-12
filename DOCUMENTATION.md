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
