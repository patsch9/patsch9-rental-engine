#!/usr/bin/env python3
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOMAIN = "patsch9-rental-engine"
POT = ROOT / "languages" / f"{DOMAIN}.pot"
PO = ROOT / "languages" / f"{DOMAIN}-de_DE.po"
MO = ROOT / "languages" / f"{DOMAIN}-de_DE.mo"

# Every entry here is an exact source-string rename. The left side is the
# historic/machine-generated msgid; the right side is the reviewed English
# source string used from 2026.10.1 onward.
MSGID_RENAMES = {
    "Externer Dienst": "External service",
    "Startadresse": "Origin address",
    "z. B. Besenhausen 1, 37133 Friedland": "e.g. Besenhausen 1, 37133 Friedland",
    "Betreff": "Subject",
    "Platzhalter: %name%, %product%, %pickup%, %return%, %order%": "Placeholders: %name%, %product%, %pickup%, %return%, %order%",
    "Daten bei Deinstallation": "Data on uninstall",
    "Unbezahlte Online-Reservierung halten": "Hold unpaid online reservation",
    "Grund/Notiz": "Reason / note",
    "z. B. Wartung, eigene Veranstaltung": "e.g. maintenance, internal event",
    "Stornieren": "Cancel",
    "Notiz": "Note",
    "Sonstige Hinterlegung": "Other deposit method",
    "Bar / manuell / separat": "Cash / manual / separate",
    "Betrag": "Amount",
    "Notiz zum Einbehalt (optional)": "Retention note (optional)",
    "Bar / manuell": "Cash / manual",
    "Vertragsnummer": "Contract number",
    "Erstellt": "Created",
    "Vertragsparteien": "Contracting parties",
    "Telefon:": "Phone:",
    "Weitere Leistungen": "Additional services",
    "Sicherheitsleistung": "Security deposit",
    "Hinterlegung": "Deposit method",
    "akzeptiert": "accepted",
    "Akzeptiert am": "Accepted on",
    "Korrekturhinweis": "Correction note",
    "Vorher": "Previous",
    "Jetzt": "Current",
    "Belegdetails": "Receipt details",
    "Belegnummer": "Receipt number",
    "Datum": "Date",
    "Auszahlung / Verrechnung": "Refund / offset",
    "Erhaltene Sicherheitsleistung": "Security deposit received",
    "Protokollnummer": "Protocol number",
    "Dokumentiert am": "Documented on",
    "Erforderlich": "Required",
    "In Ordnung": "OK",
    "Abweichung / fehlt": "Deviation / missing",
    "Bewertet": "Evaluated",
    "Dokumentnummer": "Document number",
    "Dokumenttyp": "Document type",
    "inkl. %s %% MwSt.": "incl. %s %% VAT",
    "%s %% MwSt.": "%s %% VAT",
    "Reserviert": "Reserved",
    "Wartung": "Maintenance",
    "Defekt": "Defective",
    "Kaufdatum": "Purchase date",
    "Letzte Wartung": "Last service",
    "Interne Notiz": "Internal note",
    "Inventar": "Inventory",
    "Aktionen": "Actions",
    "Einfachauswahl – maximal eine Option": "Single selection – at most one option",
    "Optional. Leer = Produktname verwenden.": "Optional. Empty = use product name.",
    "Vorherige Produkte": "Previous products",
    "Menge": "Quantity",
    "Menge verringern": "Decrease quantity",
    "Aktive Zusatzfassung": "Active supplemental version",
    "Date of check:": "Checksum:",
    "Aktive Version:": "Active version:",
    "Versionsbezeichnung": "Version label",
    "z. B. 2026-08-13": "e.g. 2026-08-13",
    "Versionshistorie": "Version history",
    "Archiv": "Archive",
    "+ Punkt": "+ Add checkpoint",
    "Unterschrift": "Signature",
    "Termin": "Date / time",
    "Aktion": "Action",
    "Dokumentation / Bemerkung": "Documentation / note",
    "Aktuell hinterlegt": "Currently held",
    "Verbleibender Betrag:": "Remaining amount:",
    "Optionale Dokumentationsunterschrift": "Optional documentation signature",
    "Unterschriftsfeld": "Signature field",
    "Remembrance of your rent: %product%": "Reminder for your rental: %product%",
    "The automatic distance calculation is currently fully engaged. Please try again for a short time.": "The automatic distance calculation is currently busy. Please try again shortly.",
    "The automatic distance calculation is temporarily fully engaged. Please try again later.": "The automatic distance calculation is temporarily unavailable. Please try again later.",
    "Flat rate of delivery": "Delivery flat rate",
    "Adjusting": "Cleanup",
    "Definitely remove booking table and plugin settings when deleting the plugin": "Permanently remove booking tables and plugin settings when deleting the plugin",
    "Please choose a valid type of deposit deposit.": "Please choose a valid security deposit method.",
    "Please choose how to deposit the deposit.": "Please choose how to provide the security deposit.",
    "Bar": "Cash",
    "Rent deposit": "Rental security deposit",
    "Rent deposit not yet paid back upon return; repayment of the balance will be made later / separately.": "Security deposit not fully refunded at return; the remaining amount will be refunded later / separately.",
    "Billing your rental deposit": "Security deposit statement",
    "Reimbursement of your rental deposit": "Refund of your security deposit",
    "Deposit account": "Security deposit statement",
    "Deposit paid back": "Security deposit refunded",
    "Detection of security deposit separately": "Record security deposit separately",
    "Deposit as received book": "Record security deposit as received",
    "About original online payment method (max. %s)": "Via the original online payment method (max. %s)",
    "Reimbursement": "Refund",
    "Reimbursement Rent deposit": "Security deposit refund",
    "Rent deposit returned in the return process: %s.": "Security deposit refunded during the return process: %s.",
    "Rent deposit repaid: %s (%s).": "Security deposit refunded: %s (%s).",
    "Commage-separated gateway IDs, where the deposit is not included in the WooCommerce payment amount. Standard: bacs, cheque, cod. Add additional invoice/prepayment plugins here.": "Comma-separated gateway IDs for which the security deposit is not included in the WooCommerce payment amount. Default: bacs, cheque, cod. Add additional invoice/prepayment gateways here.",
    "Owner": "Rental operator",
    "Rental customers": "Renter",
    "Rental and rental period": "Rental items and rental period",
    "Filed online with the order": "Provided online with the order",
    "Installation: Additional rental conditions – %s": "Appendix: Additional rental terms – %s",
    "Rental goods": "Rental items",
    "Return Log": "Return protocol",
    "Transfer Log": "Handover protocol",
    "Open/deposited": "Open / held",
    "Associated devices and documented output state": "Assigned equipment and documented initial condition",
    "Already documented before handover in the inventory:": "Already documented in inventory before handover:",
    "No known pre-damages / traces of usage stored in the inventory.": "No known pre-existing damage or signs of wear stored in inventory.",
    "Surrender": "Handed over",
    "Open commit": "Open handover",
    "Open Return": "Open return",
    "Rental equipment(s)": "Rental equipment",
    "Device stored.": "Device saved.",
    "Manage Individual Devices": "Manage individual equipment",
    "Edit Device": "Edit equipment",
    "Create Device": "Add equipment",
    "Price, tax, inventory and \"Sell separately only\" come directly from this WooCommerce article.": "Price, tax, stock, and the \"Sold individually\" setting are taken directly from this WooCommerce product.",
    "Roll through accessories": "Browse accessories",
    "Not elsewhere specified": "No add-on",
    "No accessories from this group add.": "Do not add accessories from this group.",
    "Selected without addition": "No add-on selected",
    "Transfer & Return": "Handover & return",
    "Transfers & Returns": "Handovers & returns",
    "Transfer": "Handover",
    "Transfer checklist": "Handover checklist",
    "Transfer log to your rent": "Handover protocol for your rental",
    "Return log to your rent": "Return protocol for your rental",
    "In the appendix you will receive the minutes of the delivery of your rental item.": "Attached is the handover protocol for your rental item.",
    "In the Appendix you will receive the log for the return of your rental item.": "Attached is the return protocol for your rental item.",
    "Rental procedure": "Rental workflow",
}

TRANSLATIONS = {
    "Rental and equipment booking workflows for WooCommerce with availability, pricing, security deposits, terms, documents, and handover/return management.": "Miet- und Gerätebuchungsabläufe für WooCommerce mit Verfügbarkeit, Preisen, Kautionen, Mietbedingungen, Dokumenten sowie Übergabe- und Rückgabeverwaltung.",
    "Mon": "Mo", "Tue": "Di", "Wed": "Mi", "Thu": "Do", "Fri": "Fr", "Sat": "Sa", "Sun": "So",
    "Material": "Material",
    "Search rental accessories…": "Miet-Zubehör suchen…",
    "×": "×",
    "Weekend rate": "Wochenendtarif",
    "Tiered rate": "Staffeltarif",
    "Weekday rate": "Wochentagstarif",
    "Standard rate": "Standardtarif",
    "External service": "Externer Dienst",
    "Origin address": "Startadresse",
    "e.g. Besenhausen 1, 37133 Friedland": "z. B. Besenhausen 1, 37133 Friedland",
    "Subject": "Betreff",
    "Placeholders: %name%, %product%, %pickup%, %return%, %order%": "Platzhalter: %name%, %product%, %pickup%, %return%, %order%",
    "Data on uninstall": "Daten bei Deinstallation",
    "Hold unpaid online reservation": "Unbezahlte Online-Reservierung halten",
    "Reason / note": "Grund / Notiz",
    "e.g. maintenance, internal event": "z. B. Wartung, eigene Veranstaltung",
    "Cancel": "Abbrechen",
    "Note": "Notiz",
    "Other deposit method": "Sonstige Hinterlegung",
    "Cash / manual / separate": "Bar / manuell / separat",
    "Amount": "Betrag",
    "Retention note (optional)": "Notiz zum Einbehalt (optional)",
    "Cash / manual": "Bar / manuell",
    "Contract number": "Vertragsnummer",
    "Created": "Erstellt",
    "Contracting parties": "Vertragsparteien",
    "Phone:": "Telefon:",
    "Additional services": "Weitere Leistungen",
    "Security deposit": "Kaution",
    "Deposit method": "Hinterlegung",
    "accepted": "akzeptiert",
    "Accepted on": "Akzeptiert am",
    "Correction note": "Korrekturhinweis",
    "Previous": "Vorher",
    "Current": "Jetzt",
    "Receipt details": "Belegdetails",
    "Receipt number": "Belegnummer",
    "Date": "Datum",
    "Refund / offset": "Auszahlung / Verrechnung",
    "Security deposit received": "Erhaltene Kaution",
    "Protocol number": "Protokollnummer",
    "Documented on": "Dokumentiert am",
    "Required": "Erforderlich",
    "OK": "In Ordnung",
    "Deviation / missing": "Abweichung / fehlt",
    "Evaluated": "Bewertet",
    "Document number": "Dokumentnummer",
    "Document type": "Dokumenttyp",
    "incl. %s %% VAT": "inkl. %s %% MwSt.",
    "%s %% VAT": "%s %% MwSt.",
    "Reserved": "Reserviert",
    "Maintenance": "Wartung",
    "Defective": "Defekt",
    "Purchase date": "Kaufdatum",
    "Last service": "Letzte Wartung",
    "Internal note": "Interne Notiz",
    "Inventory": "Inventar",
    "Actions": "Aktionen",
    "Single selection – at most one option": "Einzelauswahl – maximal eine Option",
    "Optional. Empty = use product name.": "Optional. Leer = Produktname verwenden.",
    "Previous products": "Vorherige Produkte",
    "Quantity": "Menge",
    "Decrease quantity": "Menge verringern",
    "Active supplemental version": "Aktive Zusatzfassung",
    "Checksum:": "Prüfsumme:",
    "Active version:": "Aktive Version:",
    "Version label": "Versionsbezeichnung",
    "e.g. 2026-08-13": "z. B. 2026-08-13",
    "Version history": "Versionshistorie",
    "Archive": "Archiv",
    "+ Add checkpoint": "+ Prüfpunkt",
    "Signature": "Unterschrift",
    "Date / time": "Termin",
    "Action": "Aktion",
    "Documentation / note": "Dokumentation / Bemerkung",
    "Currently held": "Aktuell hinterlegt",
    "Remaining amount:": "Verbleibender Betrag:",
    "Optional documentation signature": "Optionale Dokumentationsunterschrift",
    "Signature field": "Unterschriftsfeld",
    "Reminder for your rental: %product%": "Erinnerung an Ihre Miete: %product%",
    "The automatic distance calculation is currently busy. Please try again shortly.": "Die automatische Entfernungsberechnung ist gerade ausgelastet. Bitte kurz erneut versuchen.",
    "The automatic distance calculation is temporarily unavailable. Please try again later.": "Die automatische Entfernungsberechnung ist vorübergehend nicht verfügbar. Bitte später erneut versuchen.",
    "Delivery flat rate": "Lieferpauschale",
    "Cleanup": "Bereinigung",
    "Permanently remove booking tables and plugin settings when deleting the plugin": "Buchungstabellen und Plugin-Einstellungen beim Löschen des Plugins endgültig entfernen",
    "Please choose a valid security deposit method.": "Bitte eine gültige Art der Kautionshinterlegung wählen.",
    "Please choose how to provide the security deposit.": "Bitte wählen, wie die Kaution hinterlegt werden soll.",
    "Cash": "Bar",
    "Rental security deposit": "Mietkaution",
    "Security deposit not fully refunded at return; the remaining amount will be refunded later / separately.": "Kaution bei Rückgabe noch nicht vollständig zurückgezahlt; der Restbetrag wird später / separat zurückgezahlt.",
    "Security deposit statement": "Kautionsabrechnung",
    "Refund of your security deposit": "Rückzahlung Ihrer Kaution",
    "Security deposit refunded": "Kaution zurückgezahlt",
    "Record security deposit separately": "Kaution separat erfassen",
    "Record security deposit as received": "Kaution als erhalten buchen",
    "Via the original online payment method (max. %s)": "Über die ursprüngliche Online-Zahlungsart (max. %s)",
    "Refund": "Rückzahlung",
    "Security deposit refund": "Kautionsrückzahlung",
    "Security deposit refunded during the return process: %s.": "Kaution im Rückgabeprozess zurückgezahlt: %s.",
    "Security deposit refunded: %s (%s).": "Kaution zurückgezahlt: %s (%s).",
    "Comma-separated gateway IDs for which the security deposit is not included in the WooCommerce payment amount. Default: bacs, cheque, cod. Add additional invoice/prepayment gateways here.": "Kommagetrennte Gateway-IDs, bei denen die Kaution nicht in den WooCommerce-Zahlbetrag aufgenommen wird. Standard: bacs, cheque, cod. Weitere Rechnung-/Vorkasse-Gateways hier ergänzen.",
    "Rental operator": "Vermieter",
    "Renter": "Mieter",
    "Rental items and rental period": "Mietgegenstände und Mietzeitraum",
    "Provided online with the order": "Online mit der Bestellung hinterlegt",
    "Appendix: Additional rental terms – %s": "Anlage: Ergänzende Mietbedingungen – %s",
    "Rental items": "Mietartikel",
    "Return protocol": "Rückgabeprotokoll",
    "Handover protocol": "Übergabeprotokoll",
    "Open / held": "Noch offen / hinterlegt",
    "Assigned equipment and documented initial condition": "Zugeordnete Geräte und dokumentierter Ausgangszustand",
    "Already documented in inventory before handover:": "Bereits vor der Übergabe im Inventar dokumentiert:",
    "No known pre-existing damage or signs of wear stored in inventory.": "Keine bekannten Vorschäden oder Gebrauchsspuren im Inventar hinterlegt.",
    "Handed over": "Übergeben",
    "Open handover": "Übergabe öffnen",
    "Open return": "Rückgabe öffnen",
    "Rental equipment": "Mietgeräte",
    "Device saved.": "Gerät gespeichert.",
    "Manage individual equipment": "Einzelgeräte verwalten",
    "Edit equipment": "Gerät bearbeiten",
    "Add equipment": "Gerät anlegen",
    "Price, tax, stock, and the \"Sold individually\" setting are taken directly from this WooCommerce product.": "Preis, Steuer, Lagerbestand und die Einstellung „Einzeln verkauft“ werden direkt aus diesem WooCommerce-Produkt übernommen.",
    "Browse accessories": "Zubehör durchsuchen",
    "No add-on": "Ohne Zusatz",
    "Do not add accessories from this group.": "Kein Zubehör aus dieser Gruppe hinzufügen.",
    "No add-on selected": "Kein Zusatz gewählt",
    "Handover & return": "Übergabe & Rückgabe",
    "Handovers & returns": "Übergaben & Rückgaben",
    "Handover": "Übergabe",
    "Handover checklist": "Übergabe-Checkliste",
    "Handover protocol for your rental": "Übergabeprotokoll zu Ihrer Miete",
    "Return protocol for your rental": "Rückgabeprotokoll zu Ihrer Miete",
    "Attached is the handover protocol for your rental item.": "Im Anhang erhalten Sie das Übergabeprotokoll zu Ihrem Mietartikel.",
    "Attached is the return protocol for your rental item.": "Im Anhang erhalten Sie das Rückgabeprotokoll zu Ihrem Mietartikel.",
    "Rental workflow": "Mietablauf",
    "Deposit": "Kaution",
    "Still open:": "Noch offen:",
    "Cancel editing": "Bearbeitung abbrechen",
}

GETTEXT_FUNCS = (
    "__", "_e", "esc_html__", "esc_html_e", "esc_attr__", "esc_attr_e"
)


def php_single(value: str) -> str:
    return value.replace("\\", "\\\\").replace("'", "\\'")


def rewrite_gettext(text: str) -> tuple[str, int]:
    total = 0
    for old, new in MSGID_RENAMES.items():
        old_q = re.escape(php_single(old))
        for fn in GETTEXT_FUNCS:
            pattern = re.compile(
                rf"\b{re.escape(fn)}\(\s*'{old_q}'\s*,\s*'{re.escape(DOMAIN)}'\s*\)"
            )
            repl = f"{fn}( '{php_single(new)}', '{DOMAIN}' )"
            text, count = pattern.subn(repl, text)
            total += count
    return text, total


RAW_REPLACEMENTS = {
    "return wp_date( 'd.m.Y H:i', $dt->getTimestamp(), wp_timezone() ) . ' Uhr';":
        "return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $dt->getTimestamp(), wp_timezone() );",
    "$value .= ' (' . wc_format_localized_decimal( $r['delivery_distance'] ) . ' km je Fahrt)';":
        "$value .= ' (' . sprintf( __( '%s km per trip', 'patsch9-rental-engine' ), wc_format_localized_decimal( $r['delivery_distance'] ) ) . ')';",
    "$item->add_meta_data( 'Rückgabe', $this->format_datetime_display( $r['end_at'] ), true );":
        "$item->add_meta_data( __( 'Return', 'patsch9-rental-engine' ), $this->format_datetime_display( $r['end_at'] ), true );",
    "$item->add_meta_data( 'Mietdauer', sprintf( __( '%d Rental day(s) – %s', 'patsch9-rental-engine' ), (int) $r['days'], $r['pricing_label'] ), true );":
        "$item->add_meta_data( __( 'Rental duration', 'patsch9-rental-engine' ), sprintf( __( '%d Rental day(s) – %s', 'patsch9-rental-engine' ), (int) $r['days'], $r['pricing_label'] ), true );",
    "$item->add_meta_data( 'Transport / Übergabe', $this->fulfilment_label( $r['fulfilment'] ), true );":
        "$item->add_meta_data( __( 'Transport / handover', 'patsch9-rental-engine' ), $this->fulfilment_label( $r['fulfilment'] ), true );",
    "$item->add_meta_data( 'Lieferentfernung', wc_format_localized_decimal( $r['delivery_distance'] ) . ' km je Fahrt', true );":
        "$item->add_meta_data( __( 'Delivery distance', 'patsch9-rental-engine' ), sprintf( __( '%s km per trip', 'patsch9-rental-engine' ), wc_format_localized_decimal( $r['delivery_distance'] ) ), true );",
    "'cash' === sanitize_key( $r['deposit_method'] ?? 'online' ) ? 'Separat überweisen oder bei Abholung bar hinterlegen' : __( 'Provided online with the order', 'patsch9-rental-engine' ),":
        "'cash' === sanitize_key( $r['deposit_method'] ?? 'online' ) ? __( 'Transfer separately or deposit cash at collection', 'patsch9-rental-engine' ) : __( 'Provided online with the order', 'patsch9-rental-engine' ),",
    "$order->update_status( 'cancelled', 'Vermietung: unbezahlte Online-Reservierung nach ' . $this->unpaid_hold_minutes() . ' Minuten automatisch freigegeben.' );":
        "$order->update_status( 'cancelled', sprintf( __( 'Rental: unpaid online reservation automatically released after %d minutes.', 'patsch9-rental-engine' ), $this->unpaid_hold_minutes() ) );",
    "$lines[] = __( 'Additional rental terms – ', 'patsch9-rental-engine' ) . sanitize_text_field( $snapshot['product_name'] ?? 'Mietartikel' );":
        "$lines[] = __( 'Additional rental terms – ', 'patsch9-rental-engine' ) . sanitize_text_field( $snapshot['product_name'] ?? __( 'Rental item', 'patsch9-rental-engine' ) );",
    "'mietvertrag-korrigiert'": "'rental-agreement-corrected'",
    "'mietbedingungen-bestellung-' . sanitize_file_name( $order->get_order_number() ) . '.pdf'":
        "'rental-terms-order-' . sanitize_file_name( $order->get_order_number() ) . '.pdf'",
    "RMWC_PDF::build( 'Akzeptierte Mietbedingungen', $lines )":
        "RMWC_PDF::build( __( 'Accepted rental terms', 'patsch9-rental-engine' ), $lines )",
    "$order->add_order_note( 'Vermietung – mobile Aktion: ' . $action );":
        "$order->add_order_note( sprintf( __( 'Rental – mobile action: %s', 'patsch9-rental-engine' ), $action ) );",
}

# New writes should use translatable labels. Historic German keys remain only
# in explicit read/recognition fallbacks to keep prerelease orders operable.
RAW_REPLACEMENTS.update({
    "$item->update_meta_data( 'Abholung', $this->format_datetime_display( $interval['start_at'] ) );":
        "$item->update_meta_data( __( 'Pickup / rental start', 'patsch9-rental-engine' ), $this->format_datetime_display( $interval['start_at'] ) );",
    "$item->update_meta_data( 'Rückgabe', $this->format_datetime_display( $interval['end_at'] ) );":
        "$item->update_meta_data( __( 'Return', 'patsch9-rental-engine' ), $this->format_datetime_display( $interval['end_at'] ) );",
    "$item->update_meta_data( 'Abholung', $old_pickup );":
        "$item->update_meta_data( __( 'Pickup / rental start', 'patsch9-rental-engine' ), $old_pickup );",
    "$item->update_meta_data( 'Rückgabe', $old_return );":
        "$item->update_meta_data( __( 'Return', 'patsch9-rental-engine' ), $old_return );",
})

MOBILE_LABELS = {
    "'Vermietung: Zeitraum'": "__( 'Rental: Period', 'patsch9-rental-engine' )",
    "'Vermietung: Status'": "__( 'Rental: Status', 'patsch9-rental-engine' )",
    "'Vermietung: Geräte'": "__( 'Rental: Equipment', 'patsch9-rental-engine' )",
    "'Vermietung: Transport'": "__( 'Rental: Transport', 'patsch9-rental-engine' )",
    "'Vermietung: Kaution'": "__( 'Rental: Security deposit', 'patsch9-rental-engine' )",
    "'Vermietung: Aktion'": "__( 'Rental: Action', 'patsch9-rental-engine' )",
}

MOBILE_TRANSLATIONS = {
    "Rental: Period": "Vermietung: Zeitraum",
    "Rental: Status": "Vermietung: Status",
    "Rental: Equipment": "Vermietung: Geräte",
    "Rental: Transport": "Vermietung: Transport",
    "Rental: Security deposit": "Vermietung: Kaution",
    "Rental: Action": "Vermietung: Aktion",
    "Rental – mobile action: %s": "Vermietung – mobile Aktion: %s",
    "%s km per trip": "%s km je Fahrt",
    "Rental: unpaid online reservation automatically released after %d minutes.": "Vermietung: Unbezahlte Online-Reservierung nach %d Minuten automatisch freigegeben.",
    "Accepted rental terms": "Akzeptierte Mietbedingungen",
}
TRANSLATIONS.update(MOBILE_TRANSLATIONS)


def rewrite_source() -> None:
    gettext_changes = 0
    raw_changes = 0
    for path in sorted((ROOT / "includes").rglob("*.php")) + [ROOT / "patsch9-rental-engine.php"]:
        text = path.read_text(encoding="utf-8")
        original = text
        text, count = rewrite_gettext(text)
        gettext_changes += count
        for old, new in RAW_REPLACEMENTS.items():
            c = text.count(old)
            if c:
                text = text.replace(old, new)
                raw_changes += c
        if path.name == "class-rmwc-v2-integrations.php":
            # Exact associative keys and exact summary lookup are all current UI metadata.
            for old, new in MOBILE_LABELS.items():
                c = text.count(old)
                if c:
                    text = text.replace(old, new)
                    raw_changes += c
        if text != original:
            path.write_text(text, encoding="utf-8")
    print(f"Rewrote {gettext_changes} gettext msgids and {raw_changes} raw/UI occurrences.")


def rebuild_po(old_po_path: Path) -> None:
    import polib

    old_po = polib.pofile(str(old_po_path))
    pot = polib.pofile(str(POT))
    old_by_id = {e.msgid: e for e in old_po if e.msgid}
    renamed_from = {new: old for old, new in MSGID_RENAMES.items()}

    out = polib.POFile()
    out.metadata = dict(old_po.metadata)
    out.metadata["Project-Id-Version"] = "Patsch9 Rental Engine for WooCommerce 2026.10.1"
    out.metadata["Language"] = "de_DE"
    out.metadata["Language-Team"] = "German"
    out.metadata["X-Domain"] = DOMAIN

    missing = []
    for src in pot:
        dst = polib.POEntry(
            msgid=src.msgid,
            msgid_plural=src.msgid_plural,
            msgctxt=src.msgctxt,
            occurrences=list(src.occurrences),
            comment=src.comment,
            tcomment=src.tcomment,
            flags=list(src.flags),
        )
        if src.msgid_plural:
            old = old_by_id.get(src.msgid)
            if old and old.msgstr_plural:
                dst.msgstr_plural = dict(old.msgstr_plural)
            else:
                missing.append(src.msgid)
        else:
            if src.msgid in TRANSLATIONS:
                dst.msgstr = TRANSLATIONS[src.msgid]
            else:
                old = old_by_id.get(src.msgid)
                if old and old.msgstr:
                    dst.msgstr = old.msgstr
                else:
                    old_id = renamed_from.get(src.msgid)
                    previous = old_by_id.get(old_id) if old_id else None
                    if previous and previous.msgstr:
                        dst.msgstr = previous.msgstr
                    else:
                        missing.append(src.msgid)
        out.append(dst)

    if missing:
        print("Missing reviewed German translations:", file=sys.stderr)
        for item in missing:
            print(f" - {item}", file=sys.stderr)
        raise SystemExit(1)

    out.save(str(PO))
    out.save_as_mofile(str(MO))
    print(f"Rebuilt {PO.relative_to(ROOT)} and {MO.relative_to(ROOT)} with {len(out)} entries.")


GERMAN_CUES = re.compile(
    r"(?:[äöüÄÖÜß]|\b(?:Abholung|Aktion|Aktive|Betrag|Beleg|Bewertet|Daten|Erforderlich|Erstellt|"
    r"Hinterlegung|Inventar|Kaution|Kaufdatum|Liefer|Menge|Miet|Notiz|Rückgabe|Sicherheitsleistung|"
    r"Stornieren|Termin|Unterschrift|Verbleibender|Vermietung|Vertrags|Versions|Vorherige|Wartung|MwSt|"
    r"akzeptiert|z\.\s*B\.|Grund|Betreff|Platzhalter)\b)", re.IGNORECASE
)

# These literals intentionally recognize pre-2026.10.1 stored values. They are
# compatibility data, not current UI source strings.
LEGACY_ALLOWED = {
    "Kaution – ", "Lieferung – ", "Transport – ", "Abholung", "Rückgabe",
}


def audit_pot() -> None:
    import polib
    pot = polib.pofile(str(POT))
    bad = []
    for entry in pot:
        if not entry.msgid or entry.msgid in LEGACY_ALLOWED:
            continue
        if GERMAN_CUES.search(entry.msgid):
            bad.append(entry.msgid)
    if bad:
        print("German source msgids remain:", file=sys.stderr)
        for item in sorted(set(bad)):
            print(f" - {item}", file=sys.stderr)
        raise SystemExit(1)
    print("No German UI msgids remain in POT.")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=("rewrite-source", "rebuild-po", "audit-pot"))
    parser.add_argument("--old-po", type=Path)
    args = parser.parse_args()
    if args.mode == "rewrite-source":
        rewrite_source()
    elif args.mode == "rebuild-po":
        if not args.old_po:
            raise SystemExit("--old-po is required")
        rebuild_po(args.old_po)
    else:
        audit_pot()


if __name__ == "__main__":
    main()
