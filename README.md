# Patsch9 Rental Engine for WooCommerce

Aktuelle stabile Version: **2026.10.0**

Patsch9 Rental Engine erweitert WooCommerce um Vermietungs- und Gerätebuchungsprozesse mit Verfügbarkeit, zeitabhängigen Preisen, Kautionen, Mietbedingungen, Dokumenten sowie Übergabe- und Rückgabeabläufen.

## Funktionsumfang

- Mietzeiträume mit Start-/Enddatum und konfigurierbaren Uhrzeiten.
- Verfügbarkeits- und Kapazitätsprüfung mit serverseitiger Validierung.
- Wochentags- und Wochenendpreise sowie Sperr- und Vorlaufregeln.
- Rückzahlbare Kautionen mit unterschiedlichen Zahlungs-/Hinterlegungswegen.
- Versionierte allgemeine und produktspezifische Mietbedingungen.
- Mietverträge, Kautionsbelege sowie Übergabe- und Rückgabeprotokolle als PDF.
- Zubehör, Verbrauchsmaterial und mietgebundene Zusatzartikel.
- Physische Gerätezuordnung sowie Übergabe-/Rückgabe-Workflow.
- Abholung, Lieferung und Abholung durch den Vermieter.
- Classic Checkout und WooCommerce Cart/Checkout Blocks.
- HPOS-Kompatibilität.

## Voraussetzungen

- WordPress **6.9.5 oder neuer**.
- PHP **8.2 oder neuer**.
- WooCommerce **10.9.4 oder neuer**.

`Tested up to` wird bewusst nur auf tatsächlich getestete WordPress-Versionen angehoben.

## Installation

1. WooCommerce installieren und aktivieren.
2. Das ZIP-Paket dieses Plugins unter **Plugins → Installieren → Plugin hochladen** installieren.
3. Plugin aktivieren.
4. Ein WooCommerce-Produkt bearbeiten und im Produktdatenbereich die Vermietungsfunktion aktivieren.

## Konfiguration

Die produktbezogenen Einstellungen befinden sich in den WooCommerce-Produktdaten. Dort werden unter anderem Mietdauer, Zeiten, Preise, Kaution, Zubehör und Übergaberegeln gepflegt. Globale Funktionen wie Mietbedingungen, Dokumente und Workflow-Optionen werden über die vom Plugin bereitgestellten WooCommerce-/WordPress-Administrationsbereiche verwaltet.

## Externe Dienste & Datenschutz

Die Kernfunktionen arbeiten lokal in WordPress/WooCommerce. Optional kann die **Google Routes API** für die automatische Berechnung von Lieferentfernungen aktiviert werden.

Bei aktivierter Routenberechnung werden die konfigurierte Ausgangsadresse und die vom Kunden eingegebene Zieladresse an Google übertragen. Routenantworten werden vom Plugin nicht dauerhaft zwischengespeichert.

- Google Maps Platform Terms: https://cloud.google.com/maps-platform/terms
- Google Datenschutz: https://policies.google.com/privacy

Der Website-Betreiber ist für die korrekte Datenschutzinformation und die Einhaltung der anwendbaren Bedingungen verantwortlich.

## Kompatibilität

- WooCommerce HPOS (`custom_order_tables`) wird deklariert und über WooCommerce-CRUD berücksichtigt.
- Cart/Checkout Blocks (`cart_checkout_blocks`) werden deklariert.
- Der klassische WooCommerce-Checkout bleibt unterstützt.
- Persistierte historische Schlüssel wie `clr_*` und interne `RMWC_*`-Bezeichner bleiben aus Gründen der Abwärtskompatibilität bestehen.

## Versionsschema

Ab 2026.10.0 gilt projektweit `YYYY.M.PATCH`. Details stehen in [VERSIONING.md](VERSIONING.md).

## Releases

Installierbare ZIP-Dateien werden automatisch als [GitHub Releases](https://github.com/patsch9/patsch9-rental-engine/releases) bereitgestellt. Ein Release wird nur erzeugt, wenn der entsprechende `v...`-Tag zur Plugin-Version passt und die Paketprüfungen erfolgreich sind.

## Migration

Hinweise für Installationen aus der Vorabphase stehen in [MIGRATION.md](MIGRATION.md).

## Sicherheit

Sicherheitslücken bitte **nicht öffentlich als GitHub-Issue veröffentlichen**. Vorgehen und benötigte Angaben stehen in [SECURITY.md](SECURITY.md).

## Entwicklung & Tests

Das Repository enthält automatisierte statische Prüfungen für unterstützte PHP-Versionen, JavaScript-Syntax und WordPress Plugin Check. Vor produktiven Releases sind zusätzlich Integrationstests mit der tatsächlich unterstützten WordPress-/WooCommerce-Kombination erforderlich.

## Projekt unterstützen

Wenn dir das Plugin hilft, kannst du die Weiterentwicklung unterstützen:

- [GitHub Sponsors](https://github.com/sponsors/patsch9)
- [Buy Me a Coffee](https://buymeacoffee.com/patsch09)

Die Links sind zusätzlich über GitHubs Sponsor-Funktion (`.github/FUNDING.yml`) hinterlegt.

## Markenhinweise

WooCommerce® ist eine Marke von Automattic Inc. Dieses Plugin ist eine unabhängige Drittanbieter-Erweiterung und steht in keiner Verbindung zu Automattic; es wird nicht von Automattic herausgegeben, gesponsert oder unterstützt.

## Lizenz

GPL-2.0-or-later. Siehe [LICENSE](LICENSE).
