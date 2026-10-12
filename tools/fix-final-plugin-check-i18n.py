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

# Exact source rewrites required by WordPress Plugin Check and the final
# language-quality review. Persisted internal keys are intentionally excluded.
REPLACEMENTS = {
    "%d Rental day(s) – %s": "%1$d Rental day(s) – %2$s",
    "Deposit received during the handover process: %s (%s).": "Deposit received during the handover process: %1$s (%2$s).",
    "Deposit received: %s (%s).": "Deposit received: %1$s (%2$s).",
    "Security deposit refunded: %s (%s).": "Security deposit refunded: %1$s (%2$s).",
    "Rental: Transfer for booking #%d documented.": "Rental: Handover for booking #%d documented.",
    "Retention due to cleaning / pollution": "Retention due to cleaning",
    "We confirm receipt of your deposit. You will find the receipt in the appendix.": "We confirm receipt of your security deposit. The receipt is attached.",
    "In the notes you will receive the billing of your rental deposit with the documented repayment and the retention.": "The attached statement documents the refunded and retained portions of the security deposit.",
    "We confirm the repayment of your deposit or partial deposit. You can find the refund receipt in the attachment.": "We confirm the refund of your security deposit or partial security deposit. The refund receipt is attached.",
    "Reminder Mails": "Reminder emails",
    "Preceding in hours": "Lead time in hours",
}

# Translator comments are deliberately specific. WordPress coding standards
# require them immediately above gettext calls that contain placeholders.
COMMENTS = {
    "Pickup or rental start is not available on %s. Please choose a different start date.": "%s: Localized weekday name.",
    "Return is not available on %s. Please choose a different return date.": "%s: Localized weekday name.",
    "The minimum rental period is %d rental day(s).": "%d: Minimum number of rental days.",
    "The maximum rental period is %d rental day(s).": "%d: Maximum number of rental days.",
    "%1$d Rental day(s) – %2$s": "1: Number of rental days. 2: Pricing label.",
    "%s km per trip": "%s: Localized delivery distance.",
    "Transport – %s": "%s: Rental product name.",
    "Security deposit – %s": "%s: Rental product name.",
    "%s: There is not enough stock available for at least one selected period.": "%s: Rental product name.",
    "Rental booking stopped before payment – %s": "%s: Booking validation error message.",
    "WARNING rental booking: %1$s Please review order %2$s manually.": "1: Booking validation error message. 2: WooCommerce order number.",
    "Rental: unpaid online reservation automatically released after %d minutes.": "%d: Reservation hold time in minutes.",
    "Rental: The period for booking #%d was changed by an administrator. Financial line items were not recalculated automatically.": "%d: Rental booking ID.",
    "Rental: Booking #%d has been cancelled in the rental management. The order itself has not been automatically cancelled; the rental reservation will not be created again at later status changes.": "%d: Rental booking ID.",
    "Placeholders: %name%, %product%, %pickup%, %return%, %order%": "Literal reminder-template tokens. Keep the tokens unchanged.",
    "Rental: Handover for booking #%d documented.": "%d: Rental booking ID.",
    "Rental: Return for booking #%d documented.": "%d: Rental booking ID.",
    "Deposit received during the handover process: %1$s (%2$s).": "1: Formatted deposit amount. 2: Deposit method label.",
    "Security deposit refunded during the return process: %s.": "%s: Formatted refunded deposit amount.",
    "Deposit received: %1$s (%2$s).": "1: Formatted deposit amount. 2: Deposit method label.",
    "Security deposit refunded: %1$s (%2$s).": "1: Formatted refunded deposit amount. 2: Refund method label.",
    "Rental – mobile action: %s": "%s: URL for the current rental workflow action.",
}

TRANSLATIONS = {
    "%1$d Rental day(s) – %2$s": "%1$d Miettag(e) – %2$s",
    "Deposit received during the handover process: %1$s (%2$s).": "Kaution im Übergabeprozess erhalten: %1$s (%2$s).",
    "Deposit received: %1$s (%2$s).": "Kaution erhalten: %1$s (%2$s).",
    "Security deposit refunded: %1$s (%2$s).": "Kaution zurückgezahlt: %1$s (%2$s).",
    "Rental: Handover for booking #%d documented.": "Vermietung: Übergabe für Buchung #%d dokumentiert.",
    "Retention due to cleaning": "Einbehalt wegen Reinigung",
    "We confirm receipt of your security deposit. The receipt is attached.": "Wir bestätigen den Erhalt Ihrer Kaution. Der Beleg ist angehängt.",
    "The attached statement documents the refunded and retained portions of the security deposit.": "Die angehängte Abrechnung dokumentiert den zurückgezahlten und den einbehaltenen Teil der Kaution.",
    "We confirm the refund of your security deposit or partial security deposit. The refund receipt is attached.": "Wir bestätigen die Rückzahlung Ihrer Kaution bzw. Teilkaution. Der Rückzahlungsbeleg ist angehängt.",
    "Reminder emails": "Erinnerungs-E-Mails",
    "Lead time in hours": "Vorlauf in Stunden",
    "Rental start": "Mietbeginn",
    "Delivery zone": "Lieferzone",
    "Delivery/pickup address": "Liefer-/Abholadresse",
    "Security deposit method": "Kautionshinterlegung",
    "Retained:": "Einbehalt:",
}

RENAMED_FROM = {new: old for old, new in REPLACEMENTS.items()}


def replace_source_text(text: str) -> str:
    for old, new in REPLACEMENTS.items():
        text = text.replace(old, new)
    return text


def add_translator_comments(text: str) -> str:
    lines = text.splitlines(keepends=True)
    output: list[str] = []
    for line in lines:
        matched_comment = None
        for msgid, comment in COMMENTS.items():
            if msgid in line and re.search(r"\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\s*\(", line):
                matched_comment = comment
                break
        if matched_comment:
            previous = output[-1].strip() if output else ""
            if "translators:" not in previous.lower():
                indent = re.match(r"\s*", line).group(0)
                output.append(f"{indent}/* translators: {matched_comment} */\n")
        output.append(line)
    return "".join(output)


def patch_php() -> None:
    files = [
        ROOT / "includes" / "class-rmwc-plugin.php",
        ROOT / "includes" / "v2" / "modules" / "class-rmwc-v2-workflow.php",
        ROOT / "includes" / "v2" / "modules" / "class-rmwc-v2-deposits.php",
        ROOT / "includes" / "v2" / "modules" / "class-rmwc-v2-integrations.php",
    ]
    changed = 0
    for path in files:
        text = path.read_text(encoding="utf-8")
        original = text
        text = replace_source_text(text)

        # Current visible order-item labels must be English source strings.
        if path.name == "class-rmwc-plugin.php":
            text = text.replace(
                "$item->add_meta_data( 'Mietbeginn', $this->format_datetime_display( $r['start_at'] ), true );",
                "$item->add_meta_data( __( 'Rental start', 'patsch9-rental-engine' ), $this->format_datetime_display( $r['start_at'] ), true );",
            )
            text = text.replace(
                "$item->add_meta_data( 'Lieferzone', $r['delivery_zone'], true );",
                "$item->add_meta_data( __( 'Delivery zone', 'patsch9-rental-engine' ), $r['delivery_zone'], true );",
            )
            text = text.replace(
                "$item->add_meta_data( 'Liefer-/Abholadresse', $r['delivery_address'], true );",
                "$item->add_meta_data( __( 'Delivery/pickup address', 'patsch9-rental-engine' ), $r['delivery_address'], true );",
            )
            text = text.replace(
                "                'Kautionshinterlegung',\n",
                "                __( 'Security deposit method', 'patsch9-rental-engine' ),\n",
            )

        if path.name == "class-rmwc-v2-deposits.php":
            text = text.replace(
                "$note .= ' Einbehalt: ' . wp_strip_all_tags( wc_price( $retained, [ 'currency' => $order->get_currency() ] ) )",
                "$note .= ' ' . __( 'Retained:', 'patsch9-rental-engine' ) . ' ' . wp_strip_all_tags( wc_price( $retained, [ 'currency' => $order->get_currency() ] ) )",
            )
            text = text.replace(
                "'date'          => wp_date( 'd.m.Y H:i' ),",
                "'date'          => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),",
            )

        text = add_translator_comments(text)
        if text != original:
            path.write_text(text, encoding="utf-8")
            changed += 1
    print(f"Patched {changed} PHP files.")


def patch_migration() -> None:
    path = ROOT / "includes" / "class-rmwc-legacy-migration.php"
    text = path.read_text(encoding="utf-8")
    old = """            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration of plugin-owned tables.\n            $legacy_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $legacy_table ) );\n            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration of plugin-owned tables.\n            $current_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $current_table ) );\n"""
    new = """            // These collision checks must observe the database directly. Caching a\n            // one-time schema migration decision could cause the wrong table to win.\n            // phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching\n            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- One-time migration of plugin-owned tables.\n            $legacy_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $legacy_table ) );\n            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- One-time migration of plugin-owned tables.\n            $current_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $current_table ) );\n            // phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching\n"""
    if old not in text:
        raise SystemExit("Migration count-query block not found")
    path.write_text(text.replace(old, new), encoding="utf-8")
    print("Patched migration query annotations.")


def rebuild_po(old_po: Path) -> None:
    import polib

    old = polib.pofile(str(old_po))
    pot = polib.pofile(str(POT))
    old_entries = {(e.msgctxt or "", e.msgid): e for e in old if e.msgid}

    out = polib.POFile()
    out.metadata = dict(old.metadata)
    out.metadata["Project-Id-Version"] = "Patsch9 Rental Engine for WooCommerce 2026.10.1"
    out.metadata["Language"] = "de_DE"
    out.metadata["Language-Team"] = "German"
    out.metadata["X-Domain"] = DOMAIN

    missing: list[str] = []
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
        key = (src.msgctxt or "", src.msgid)
        previous = old_entries.get(key)
        if src.msgid_plural:
            if previous and previous.msgstr_plural:
                dst.msgstr_plural = dict(previous.msgstr_plural)
            else:
                missing.append(src.msgid)
        elif src.msgid in TRANSLATIONS:
            dst.msgstr = TRANSLATIONS[src.msgid]
        elif previous and previous.msgstr:
            dst.msgstr = previous.msgstr
        else:
            old_id = RENAMED_FROM.get(src.msgid)
            previous = old_entries.get((src.msgctxt or "", old_id)) if old_id else None
            if previous and previous.msgstr:
                translated = previous.msgstr
                # Keep positional placeholders aligned with the new msgid.
                if src.msgid == "%1$d Rental day(s) – %2$s":
                    translated = translated.replace("%d", "%1$d", 1).replace("%s", "%2$s", 1)
                elif src.msgid in {
                    "Deposit received during the handover process: %1$s (%2$s).",
                    "Deposit received: %1$s (%2$s).",
                    "Security deposit refunded: %1$s (%2$s).",
                }:
                    translated = translated.replace("%s", "%1$s", 1).replace("%s", "%2$s", 1)
                dst.msgstr = translated
            else:
                missing.append(src.msgid)
        out.append(dst)

    if missing:
        print("Missing German translations:", file=sys.stderr)
        for item in sorted(set(missing)):
            print(f" - {item}", file=sys.stderr)
        raise SystemExit(1)

    out.save(str(PO))
    out.save_as_mofile(str(MO))
    print(f"Rebuilt {len(out)} translated catalogue entries.")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=("patch", "rebuild-po"))
    parser.add_argument("--old-po", type=Path)
    args = parser.parse_args()
    if args.mode == "patch":
        patch_php()
        patch_migration()
    else:
        if not args.old_po:
            raise SystemExit("--old-po is required")
        rebuild_po(args.old_po)


if __name__ == "__main__":
    main()
