=== Patsch9 Rental Engine for WooCommerce ===
Contributors: patsch9
Tags: woocommerce, rental, booking, vermietung, kaution
Requires at least: 6.9.5
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 2026.10.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Vermietungs- und Gerätebuchungen für WooCommerce mit Verfügbarkeit, Preisen, Kautionen, Dokumenten und Übergabe-/Rückgabe-Workflow.

== Description ==

Patsch9 Rental Engine erweitert WooCommerce um einen vollständigen Vermietungsprozess. Mietprodukte können zeitabhängige Verfügbarkeit, Preise, Kautionen, Bedingungen, Dokumente, Zubehör, konkrete Geräte sowie Übergabe- und Rückgabeabläufe verwenden.

Das Plugin unterstützt den klassischen WooCommerce-Checkout sowie Cart/Checkout Blocks und deklariert HPOS-Kompatibilität.

**Markenhinweis:** WooCommerce® ist eine Marke von Automattic Inc. Dieses Plugin ist eine unabhängige Drittanbieter-Erweiterung und wird nicht von Automattic herausgegeben, gesponsert oder unterstützt.

== Funktionen ==

* Mietzeiträume mit Start-/Enddatum und Uhrzeiten.
* Serverseitige Verfügbarkeits- und Kapazitätsprüfung.
* Wochentags-/Wochenendpreise, Sperrtage und Buchungsvorlauf.
* Rückzahlbare Kautionen mit unterschiedlichen Hinterlegungswegen.
* Versionierte allgemeine und produktspezifische Mietbedingungen.
* Mietvertrag, Kautionsbelege sowie Übergabe-/Rückgabeprotokolle als PDF.
* Zubehör, Verbrauchsmaterial und mietgebundene Zusatzartikel.
* Physische Gerätezuordnung und Workflow für Übergabe und Rückgabe.
* Abholung und optionale Liefer-/Abholprozesse.

== Voraussetzungen ==

* WordPress 6.9.5 oder neuer.
* PHP 8.2 oder neuer.
* WooCommerce 10.9.4 oder neuer.

== Installation ==

1. WooCommerce installieren und aktivieren.
2. Plugin-ZIP hochladen und aktivieren.
3. Ein WooCommerce-Produkt bearbeiten.
4. Im Produktdatenbereich die Vermietungsfunktion aktivieren und konfigurieren.

== Konfiguration ==

Die wesentlichen Einstellungen werden direkt am WooCommerce-Produkt gepflegt. Dazu gehören Mietdauer, Zeiten, Preise, Kaution, Zubehör, Lieferoptionen und Übergaberegeln. Globale Mietbedingungen und Workflow-Funktionen werden in den vom Plugin bereitgestellten Administrationsbereichen verwaltet.

== External Services ==

= Google Routes API =

Optional kann die automatische Lieferentfernung über die Google Routes API unter `https://routes.googleapis.com/directions/v2:computeRoutes` berechnet werden. Die Funktion ist standardmäßig deaktiviert.

Bei aktivierter Funktion werden die konfigurierte Ausgangsadresse und die vom Kunden eingegebene Zieladresse zusammen mit den technisch erforderlichen Verbindungsdaten an Google übertragen. Das Plugin speichert Routenantworten nicht dauerhaft zwischen.

Google Maps Platform Terms: https://cloud.google.com/maps-platform/terms
Google Datenschutz: https://policies.google.com/privacy

Der Website-Betreiber ist für die korrekte Datenschutzinformation und die Einhaltung der anwendbaren Google-Bedingungen verantwortlich.

== Datenschutz ==

Die Kernfunktionen arbeiten lokal in WordPress/WooCommerce. Eine Datenübertragung an Google erfolgt nur, wenn die optionale Routenberechnung ausdrücklich aktiviert wurde.

== Kompatibilität ==

* WooCommerce HPOS wird deklariert.
* Classic Checkout und Cart/Checkout Blocks werden unterstützt.
* Historische interne Speicherbezeichner bleiben aus Gründen der Abwärtskompatibilität erhalten.

== Frequently Asked Questions ==

= Sind Mietprodukte normale WooCommerce-Versandartikel? =

Nein. Übergabe, Abholung und optionale Vermieter-Lieferung werden durch den Mietprozess gesteuert.

= Können mietgebundene Zubehörartikel separat gekauft werden? =

Nein. Als mietgebunden konfigurierte Zubehörartikel werden nur im Zusammenhang mit einem passenden Mietprodukt angeboten.

= Ist die Kaution normaler Mietumsatz? =

Das Plugin behandelt rückzahlbare Kautionen getrennt von der normalen Mietpreisberechnung. Die rechtliche und steuerliche Einordnung bleibt Aufgabe des Betreibers.

== Changelog ==

= 2026.10.0 =
* Erstes stabiles öffentliches Release im neuen projektweiten Versionsschema `YYYY.M.PATCH`.
* Enthält den konsolidierten Funktions- und Sicherheitsstand aller bisherigen internen Vorabversionen bis einschließlich 2.0.29.
* Dokumentation vollständig vereinheitlicht und auf Deutsch aktualisiert.
* Checkout-Block-Abhängigkeiten, HPOS-Pfade, Dokumentdownloads, Preis-/Verfügbarkeitsprüfung und externe Routenintegration erneut verifiziert.

== Upgrade Notice ==

= 2026.10.0 =
Erstes stabiles Release der neuen öffentlichen Versionslinie. Bestehende Installationen aus der Vorabphase sollten vor dem Update gesichert und anschließend funktional geprüft werden.
