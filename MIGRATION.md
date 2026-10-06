# Migration

## Zielversion

Dieses Dokument beschreibt die Migration auf **2026.10.0**, das erste stabile Release im neuen öffentlichen Versionsschema.

## Von internen Vorabversionen

Die letzte interne Vorabversion dieser Plugin-Linie war `2.0.29`. `2026.10.0` verwendet denselben öffentlichen Plugin-Slug `patsch9-rental-engine`; bestehende Installationen können daher regulär aktualisiert werden.

1. Vollständiges Backup von Datenbank und `wp-content` erstellen.
2. Plugin auf `2026.10.0` aktualisieren.
3. WooCommerce-Produktdaten eines Mietprodukts kontrollieren.
4. Testbuchung mit Verfügbarkeitsprüfung und Preisberechnung durchführen.
5. Checkout, Kaution, Dokumentdownload sowie Übergabe-/Rückgabeprozess in einer Testbestellung prüfen.
6. Falls Google Routes verwendet wird, eine Testentfernung berechnen.

Die interne Datenbankschema-Version bleibt von der öffentlichen Plugin-Version getrennt. Persistierte `clr_*`-Tabellen/Metadaten und `RMWC_*`-Bezeichner werden nicht allein wegen des neuen Release-Schemas umbenannt.

## Von älteren öffentlichen Namen/Ordnern

Falls noch ein älterer Plugin-Ordner vor `patsch9-rental-engine` verwendet wird, darf nicht gleichzeitig die alte und die neue Plugin-Datei aktiv sein.

1. Backup erstellen.
2. Alte Plugin-Version deaktivieren.
3. Alten Plugin-Ordner aus `wp-content/plugins` entfernen oder außerhalb dieses Verzeichnisses sichern, **ohne die alte Uninstall-Routine auszuführen**.
4. `patsch9-rental-engine` installieren und aktivieren.
5. Einstellungen, Mietprodukte, Buchungen, Bedingungen und Dokumente kontrollieren.

## Versionswechsel

Alle Versionen vor `2026.10.0` gehören zur Vorabphase. Ab `2026.10.0` gilt ausschließlich das in [VERSIONING.md](VERSIONING.md) dokumentierte Schema `YYYY.M.PATCH`.
