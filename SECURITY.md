# Sicherheitsrichtlinie

## Unterstützte Versionen

Sicherheitskorrekturen werden grundsätzlich für die jeweils aktuelle stabile Version bereitgestellt. Ältere Versionen sollten vor einer Meldung aktualisiert werden, sofern die ältere Version nicht zur Beschreibung eines Upgrade-Problems erforderlich ist.

## Sicherheitslücke melden

Bitte vermutete Sicherheitslücken nicht vor einer Korrektur als öffentliches GitHub-Issue veröffentlichen.

Bevorzugt ist GitHubs **Private Vulnerability Reporting / Security Advisory** des jeweiligen Repositories. Eine Meldung sollte mindestens enthalten:

- betroffene Plugin-Version;
- WordPress-, WooCommerce- und PHP-Version;
- nachvollziehbare Reproduktionsschritte;
- benötigte Benutzerrolle bzw. Authentifizierungsstatus;
- erwartetes und tatsächliches Verhalten;
- relevante Request-/Response-Informationen ohne Geheimnisse oder personenbezogene Produktivdaten.

Bitte niemals produktive API-Keys, Session-Cookies, Nonces, Kundendaten oder vollständige Datenbank-Dumps mitsenden.

## Grundsätze

Das Plugin folgt den WordPress-Sicherheitskonventionen: Berechtigungsprüfungen für privilegierte Aktionen, Nonces gegen CSRF wo anwendbar, Validierung und Sanitization bei Eingaben, kontextbezogenes spätes Escaping bei Ausgaben sowie vorbereitete Datenbankabfragen.

## Plugin-spezifische Hinweise

- Dokumentdownloads koppeln Dokument, Bestellung und Berechtigung bzw. zeitlich begrenzten Gastzugriff.
- Preis-, Verfügbarkeits-, Mietdauer- und Kapazitätsregeln werden serverseitig geprüft; Browserdaten gelten nicht als Vertrauensquelle.
- Dokument- und Bedingungssnapshots werden integritätsgeprüft.
- Sensible Workflow-Übergänge und Kautionsaktionen verwenden Sperr-/Statusmechanismen gegen Doppelverarbeitung.
- Die optionale Google-Routes-Integration ist standardmäßig deaktiviert und enthält zusätzliche Request-Begrenzungen.
