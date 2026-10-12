#!/usr/bin/env python3
"""Deterministic final i18n polish for the rental plugin.

No machine translation is performed here. Exact source msgids are replaced by
reviewed English wording, while a mapping file is emitted so the existing
German translations can be retained when the PO catalogue is rebuilt.
"""
from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOMAIN = "patsch9-rental-engine"
MAP_OUT = ROOT / "tools" / "polish-map.json"

MSGID_MAP = {
    "At the %s no collection or rental start is possible. Please choose another starting day.": "Pickup or rental start is not available on %s. Please choose a different start date.",
    "No return is possible on the %s. Please select another day of return.": "Return is not available on %s. Please choose a different return date.",
    "Basic Data & Times": "Basic data & times",
    "Standard settlement": "Default billing",
    "Number available": "Available quantity",
    "How many identical devices of this product can be rented at the same time.": "How many identical rental units of this product can be rented at the same time.",
    "Pick-up/rental start": "Pickup / rental start",
    "Pick-up/rental start:": "Pickup / rental start:",
    "Example: 12:00. From this date the booking starts on the selected start date.": "Example: 12:00. The booking starts at this time on the selected start date.",
    "Additional lock time after return, e.g. 2 hours for cleaning and control.": "Additional buffer time after return, e.g. 2 hours for cleaning and inspection.",
    "Deposit & handover": "Security deposit & handover",
    "Refundable security per rented device.": "Refundable security deposit per rental unit.",
    "Note on the deposit": "Security deposit note",
    "e.g. will be refunded after checking the return.": "e.g. refunded after the returned item has been inspected.",
    "Allow collection": "Allow pickup",
    "Delivery + Allow pick-up": "Allow delivery and collection",
    "Flexible price scales": "Flexible pricing tiers",
    "Price priority: weekend rate → matching price scale → weekday rates → standard price. Example: from 7 rental days Package price 129 € incl. 7 days, afterwards 15 € per additional rental day.": "Pricing priority: weekend rate → matching pricing tier → weekday rate → standard price. Example: from 7 rental days, a package price of €129 includes 7 days; each additional rental day costs €15.",
    "Ab Tagen": "From day",
    "Days to days": "Up to day",
    "Paketpreis": "Package price",
    "Days included": "Included days",
    "Next day": "Additional day",
    "+ Price scale": "+ Add pricing tier",
    "Active weekend rate": "Enable weekend rate",
    "Only grips with exactly the right start/return weekday and exactly the following duration (e.g. Friday to Monday = 3 rental days).": "Applies only when the start and return weekdays match exactly and the resulting duration is exact (e.g. Friday to Monday = 3 rental days).",
    "Additional options & rental accessories": "Add-ons & rental accessories",
    "For material sizes and priced accessories use rental accessories. Simple additional options are intended for services or surcharges that do not require own storage items.": "Use rental accessories for material variants and priced accessory items. Simple add-ons are intended for services or surcharges that do not require their own inventory items.",
    "Gruppe": "Group",
    "Settlement": "Billing",
    "+ Einfache Zusatzoption": "+ Add simple option",
    "Bezeichnung": "Label",
    "Bis km": "Up to km",
    "+ delivery zone": "+ Add delivery zone",
    "einmalig": "once",
    "per unit of rental": "per rental unit",
    "bis 10 km": "up to 10 km",
    "Only active bookings that have not yet been passed can be edited.": "Only active bookings that have not yet been handed over can be edited.",
    "The booking change could not be saved. The previous condition was restored.": "The booking change could not be saved. The previous state was restored.",
    "The WooCommerce order position for the booking was not found. The change was reset.": "The WooCommerce order item for the booking was not found. The change was reverted.",
    "The rental data of the WooCommerce order position are incomplete. The change has been reset.": "The rental data on the WooCommerce order item is incomplete. The change was reverted.",
    "Rental: Period of booking #%d administratively changed. Financial positions were not automatically recalculated.": "Rental: The period for booking #%d was changed by an administrator. Financial line items were not recalculated automatically.",
    "The order position could not be safely updated. Booking and equipment assignment were reset to the previous level.": "The order item could not be updated safely. The booking and equipment assignment were restored to their previous state.",
    "An already handed over, returned or otherwise completed booking cannot be deleted or cancelled for reasons of proof.": "A booking that has already been handed over, returned, or otherwise completed cannot be deleted or cancelled because its audit trail must be retained.",
    "The order booking has been cancelled and remains in the history for reasons of proof.": "The order booking was cancelled and remains in the history to preserve its audit trail.",
    "Deposit deposit": "Security deposit handling",
    "When making online payment let customer choose": "Let the customer choose at checkout",
    "Always directly with the online payment": "Always collect with the online payment",
    "Always separately / bar at handover": "Always separately / cash at handover",
    "Decrees": "Waived",
    "Not cleaned correctly / cleaning effort": "Insufficient cleaning / cleaning costs",
    "Full withholding": "Fully retained",
    "Filed online with order": "Provided online with the order",
    "The amount of a paid order is deliberately not changed here.": "The total of an already paid order is intentionally not changed here.",
    "Zusatzoption": "Add-on",
    "Bearbeitet von": "Processed by",
    "rental contract": "Rental agreement",
    "Additional rental conditions –": "Additional rental terms – ",
    "Bearbeitung abbrechen": "Cancel editing",
    "Gruppenname": "Group name",
    "Auswahlart": "Selection type",
    "Pflichtauswahl": "Required selection",
    "Berechnung": "Calculation",
    "Abbrechen": "Cancel",
    "Von": "From",
    "Bis": "To",
}

# New English strings introduced outside existing gettext calls. Their German
# translations are supplied explicitly when the catalogue is rebuilt.
NEW_TRANSLATIONS = {
    "per trip": "je Fahrt",
    "total": "gesamt",
    "on the selected return date": "am gewählten Rückgabetag",
    "Transport flat fee per trip:": "Transportpauschale je Fahrt:",
    "Rental item": "Mietartikel",
    "from": "ab",
    " / rental unit": " / Mieteinheit",
    " / rental day": " / Miettag",
    "Security deposit – %s": "Kaution – %s",
    "Transport – %s": "Transport – %s",
    "Rental booking stopped before payment – %s": "Vermietung: Bestellung vor Zahlung gestoppt – %s",
    "WARNING rental booking: %1$s Please review order %2$s manually.": "ACHTUNG Vermietung: %1$s Bestellung %2$s bitte manuell prüfen.",
    "This order does not contain a rental security deposit.": "Diese Bestellung enthält keine Mietkaution.",
    "Total security deposit:": "Kaution gesamt:",
    "Refunded": "Zurückgezahlt",
    "Retained": "Einbehalten",
    "Reason for retention": "Grund für Einbehalt",
    "Refund/completion date": "Rückzahlung/Abschluss am",
    "Note / damage": "Notiz / Schaden",
    "Permission denied.": "Keine Berechtigung.",
    "Settings saved.": "Einstellungen gespeichert.",
    "Please select a valid rental product.": "Bitte ein gültiges Mietprodukt auswählen.",
    "Please enter a valid email address.": "Bitte eine gültige E-Mail-Adresse angeben.",
    "Manual booking created.": "Manuelle Buchung angelegt.",
    "Please enter a valid blocked period.": "Bitte einen gültigen Sperrzeitraum angeben.",
    "Search rental products…": "Mietprodukt suchen…",
    "Rental reminder: %product%": "Erinnerung an Ihre Miete: %product%",
    "Hello %name%,\n\nThis is a reminder of your upcoming rental of %product%.\nPickup / rental start: %pickup%\nReturn: %return%\n\nBest regards": "Hallo %name%,\n\nwir erinnern an Ihre bevorstehende Miete von %product%.\nAbholung/Mietbeginn: %pickup%\nRückgabe: %return%\n\nViele Grüße",
}


def php_single(value: str) -> str:
    return "'" + value.replace('\\', '\\\\').replace("'", "\\'") + "'"


def apply_msgid_map(text: str) -> str:
    for old, new in MSGID_MAP.items():
        text = text.replace(php_single(old), php_single(new))
    return text


def replace_exact(text: str, old: str, new: str, label: str, required: bool = True) -> str:
    count = text.count(old)
    if required and count == 0:
        raise RuntimeError(f"Missing expected source fragment: {label}")
    return text.replace(old, new)


def patch_core() -> None:
    path = ROOT / 'includes' / 'class-rmwc-plugin.php'
    text = apply_msgid_map(path.read_text(encoding='utf-8'))

    text = text.replace("$weekdays[ $start_day ] ?? 'gewählten Wochentag'", "$weekdays[ $start_day ] ?? __( 'selected weekday', 'patsch9-rental-engine' )")
    text = text.replace("$weekdays[ $end_day ] ?? 'gewählten Wochentag'", "$weekdays[ $end_day ] ?? __( 'selected weekday', 'patsch9-rental-engine' )")

    text = text.replace(
        "<strong><?php esc_html_e( 'Pickup / rental start:', 'patsch9-rental-engine' ); ?></strong> <?php echo esc_html( $this->pickup_time( $id ) ); ?> Uhr<br>",
        "<strong><?php esc_html_e( 'Pickup / rental start:', 'patsch9-rental-engine' ); ?></strong> <?php echo esc_html( $this->pickup_time( $id ) ); ?><br>"
    )
    text = text.replace(
        "<strong><?php esc_html_e( 'Return:', 'patsch9-rental-engine' ); ?></strong> <?php echo esc_html( $this->return_time( $id ) ); ?> Uhr am gewählten Rückgabetag",
        "<strong><?php esc_html_e( 'Return:', 'patsch9-rental-engine' ); ?></strong> <?php echo esc_html( $this->return_time( $id ) ); ?> <?php esc_html_e( 'on the selected return date', 'patsch9-rental-engine' ); ?>"
    )
    text = text.replace(
        "<?php echo esc_html( 'unit' === $addon['mode'] ? ' / Mieteinheit' : ( 'day' === $addon['mode'] ? ' / Miettag' : '' ) ); ?>",
        "<?php echo esc_html( 'unit' === $addon['mode'] ? __( ' / rental unit', 'patsch9-rental-engine' ) : ( 'day' === $addon['mode'] ? __( ' / rental day', 'patsch9-rental-engine' ) : '' ) ); ?>"
    )
    text = text.replace(
        "<p>Transportpauschale je Fahrt: <?php echo wp_kses_post( wc_price( $delivery_fee ) ); ?></p>",
        "<p><?php esc_html_e( 'Transport flat fee per trip:', 'patsch9-rental-engine' ); ?> <?php echo wp_kses_post( wc_price( $delivery_fee ) ); ?></p>"
    )
    text = text.replace("$name       = ! empty( $item['data'] ) ? $item['data']->get_name() : 'Mietartikel';", "$name       = ! empty( $item['data'] ) ? $item['data']->get_name() : __( 'Rental item', 'patsch9-rental-engine' );")
    text = text.replace("$product ? $product->get_name() : 'Mietartikel'", "$product ? $product->get_name() : __( 'Rental item', 'patsch9-rental-engine' )")
    text = text.replace("$product ? $product->get_name() : 'Mietartikel',", "$product ? $product->get_name() : __( 'Rental item', 'patsch9-rental-engine' ),")
    text = text.replace("$labels[ $unit ] ?? 'Miettag'", "$labels[ $unit ] ?? __( 'Rental day', 'patsch9-rental-engine' )")
    text = text.replace("return '<span class=\"price rmwc-catalog-price\">ab ' . wc_price( $price ) . ' / '", "return '<span class=\"price rmwc-catalog-price\">' . esc_html__( 'from', 'patsch9-rental-engine' ) . ' ' . wc_price( $price ) . ' / '")

    # Fee names are user-visible, but old German prefixes remain accepted as a
    # compatibility fallback when identifying fees created by prerelease builds.
    text = text.replace("$cart->add_fee( 'Transport – ' . $row['name'], $row['amount'], true, $row['tax_class'] );", "$cart->add_fee( sprintf( __( 'Transport – %s', 'patsch9-rental-engine' ), $row['name'] ), $row['amount'], true, $row['tax_class'] );")
    text = text.replace("$cart->add_fee( 'Kaution – ' . $row['name'], $row['amount'], false );", "$cart->add_fee( sprintf( __( 'Security deposit – %s', 'patsch9-rental-engine' ), $row['name'] ), $row['amount'], false );")
    text = text.replace("if ( 0 === strpos( $item->get_name(), 'Kaution – ' ) ) {", "if ( 0 === strpos( $item->get_name(), __( 'Security deposit – ', 'patsch9-rental-engine' ) ) || 0 === strpos( $item->get_name(), 'Kaution – ' ) ) {")
    text = text.replace("} elseif ( 0 === strpos( $item->get_name(), 'Lieferung – ' ) || 0 === strpos( $item->get_name(), 'Transport – ' ) ) {", "} elseif ( 0 === strpos( $item->get_name(), __( 'Delivery – ', 'patsch9-rental-engine' ) ) || 0 === strpos( $item->get_name(), __( 'Transport – ', 'patsch9-rental-engine' ) ) || 0 === strpos( $item->get_name(), 'Lieferung – ' ) || 0 === strpos( $item->get_name(), 'Transport – ' ) ) {")

    text = text.replace("$order->add_order_note( 'Vermietung: Bestellung vor Zahlung gestoppt – ' . $result->get_error_message() );", "$order->add_order_note( sprintf( __( 'Rental booking stopped before payment – %s', 'patsch9-rental-engine' ), $result->get_error_message() ) );")
    text = text.replace("$order->add_order_note( 'ACHTUNG Vermietung: ' . $result->get_error_message() . ' Bestellung bitte manuell prüfen.' );", "$order->add_order_note( sprintf( __( 'WARNING rental booking: %1$s Please review order %2$s manually.', 'patsch9-rental-engine' ), $result->get_error_message(), $order->get_order_number() ) );")

    # The legacy core deposit box is normally replaced by V2, but keep it fully
    # translatable as a fallback instead of shipping raw German UI.
    text = text.replace("add_meta_box( 'rmwc_deposit_box', 'Mietkaution',", "add_meta_box( 'rmwc_deposit_box', __( 'Rental security deposit', 'patsch9-rental-engine' ),")
    text = text.replace("echo '<p>Diese Bestellung enthält keine Mietkaution.</p>';", "echo '<p>' . esc_html__( 'This order does not contain a rental security deposit.', 'patsch9-rental-engine' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Translation escaped above.")
    text = text.replace("echo '<p><strong>Kaution gesamt:</strong> '", "echo '<p><strong>' . esc_html__( 'Total security deposit:', 'patsch9-rental-engine' ) . '</strong> '")
    text = text.replace("echo '<p><label>Zurückgezahlt<br><input", "echo '<p><label>' . esc_html__( 'Refunded', 'patsch9-rental-engine' ) . '<br><input")
    text = text.replace("echo '<p><label>Einbehalten<br><input", "echo '<p><label>' . esc_html__( 'Retained', 'patsch9-rental-engine' ) . '<br><input")
    text = text.replace("echo '<p><label>Grund für Einbehalt<br><select", "echo '<p><label>' . esc_html__( 'Reason for retention', 'patsch9-rental-engine' ) . '<br><select")
    text = text.replace("echo '<p><label>Rückzahlung/Abschluss am<br><input", "echo '<p><label>' . esc_html__( 'Refund/completion date', 'patsch9-rental-engine' ) . '<br><input")
    text = text.replace("echo '<p><label>Notiz / Schaden<br><textarea", "echo '<p><label>' . esc_html__( 'Note / damage', 'patsch9-rental-engine' ) . '<br><textarea")

    text = text.replace("return '<div class=\"notice notice-error\"><p>Keine Berechtigung.</p></div>';", "return '<div class=\"notice notice-error\"><p>' . esc_html__( 'Permission denied.', 'patsch9-rental-engine' ) . '</p></div>';")
    text = text.replace("return '<div class=\"notice notice-success\"><p>Einstellungen gespeichert.</p></div>';", "return '<div class=\"notice notice-success\"><p>' . esc_html__( 'Settings saved.', 'patsch9-rental-engine' ) . '</p></div>';")
    text = text.replace("return '<div class=\"notice notice-error\"><p>Bitte ein gültiges Mietprodukt auswählen.</p></div>';", "return '<div class=\"notice notice-error\"><p>' . esc_html__( 'Please select a valid rental product.', 'patsch9-rental-engine' ) . '</p></div>';")
    text = text.replace("return '<div class=\"notice notice-error\"><p>Bitte eine gültige E-Mail-Adresse angeben.</p></div>';", "return '<div class=\"notice notice-error\"><p>' . esc_html__( 'Please enter a valid email address.', 'patsch9-rental-engine' ) . '</p></div>';")
    text = text.replace("return '<div class=\"notice notice-success\"><p>Manuelle Buchung angelegt.</p></div>';", "return '<div class=\"notice notice-success\"><p>' . esc_html__( 'Manual booking created.', 'patsch9-rental-engine' ) . '</p></div>';")
    text = text.replace("return '<div class=\"notice notice-error\"><p>Bitte einen gültigen Sperrzeitraum angeben.</p></div>';", "return '<div class=\"notice notice-error\"><p>' . esc_html__( 'Please enter a valid blocked period.', 'patsch9-rental-engine' ) . '</p></div>';")
    text = text.replace("data-placeholder=\"Mietprodukt suchen…\"", "data-placeholder=\"' . esc_attr__( 'Search rental products…', 'patsch9-rental-engine' ) . '\"")

    old_subject = "'Erinnerung an Ihre Miete: %product%'"
    text = text.replace(old_subject, "__( 'Rental reminder: %product%', 'patsch9-rental-engine' )")
    old_body = '"Hallo %name%,\\n\\nwir erinnern an Ihre bevorstehende Miete von %product%.\\nAbholung/Mietbeginn: %pickup%\\nRückgabe: %return%\\n\\nViele Grüße"'
    new_body = "__( \"Hello %name%,\\n\\nThis is a reminder of your upcoming rental of %product%.\\nPickup / rental start: %pickup%\\nReturn: %return%\\n\\nBest regards\", 'patsch9-rental-engine' )"
    text = text.replace(old_body, new_body)

    path.write_text(text, encoding='utf-8')


def patch_deposits() -> None:
    path = ROOT / 'includes' / 'v2' / 'modules' / 'class-rmwc-v2-deposits.php'
    text = apply_msgid_map(path.read_text(encoding='utf-8'))
    # Keep the old German fee prefix as a compatibility fallback; new fees use
    # the localized canonical English source string from the core module.
    text = text.replace("|| 0 === strpos( (string) $fee->get_name(), 'Kaution – ' );", "|| 0 === strpos( (string) $fee->get_name(), __( 'Security deposit – ', 'patsch9-rental-engine' ) )\n                || 0 === strpos( (string) $fee->get_name(), 'Kaution – ' );")
    text = text.replace("$order->add_order_note( 'Mietkaution: Sperre für unklare Online-Rückzahlung wurde nach manueller Prüfung aufgehoben.' );", "$order->add_order_note( __( 'Rental security deposit: The block for an unclear online refund was cleared after manual review.', 'patsch9-rental-engine' ) );")
    path.write_text(text, encoding='utf-8')


def patch_other_php() -> None:
    for rel in [
        'includes/v2/modules/class-rmwc-v2-documents.php',
        'includes/v2/modules/class-rmwc-v2-inventory.php',
        'includes/v2/modules/class-rmwc-v2-materials.php',
        'includes/v2/modules/class-rmwc-v2-integrations.php',
        'includes/v2/modules/class-rmwc-v2-workflow.php',
        'includes/v2/modules/class-rmwc-v2-accessories.php',
        'includes/v2/class-rmwc-pdf.php',
    ]:
        path = ROOT / rel
        if path.exists():
            path.write_text(apply_msgid_map(path.read_text(encoding='utf-8')), encoding='utf-8')


def patch_front_js() -> None:
    path = ROOT / 'assets' / 'rental.js'
    text = path.read_text(encoding='utf-8')
    old = "$('#rmwc_delivery_result').text(`${r.data.distance} km je Fahrt – ${r.data.zone.label}: ${currency(deliveryFee)} je Fahrt${legs===2?' / '+currency(deliveryFee*2)+' gesamt':''} · Google Maps`);"
    new = "$('#rmwc_delivery_result').text(`${r.data.distance} km ${t('perTrip','per trip')} – ${r.data.zone.label}: ${currency(deliveryFee)} ${t('perTrip','per trip')}${legs===2?' / '+currency(deliveryFee*2)+' '+t('total','total'):''} · Google Maps`);"
    text = text.replace(old, new)
    path.write_text(text, encoding='utf-8')


def patch_localized_js_strings() -> None:
    path = ROOT / 'includes' / 'class-rmwc-plugin.php'
    text = path.read_text(encoding='utf-8')
    marker = "                'selectAccessory'        => __( 'Please select at least one accessory option.', 'patsch9-rental-engine' ),\n"
    addition = marker + (
        "                'perTrip'                => __( 'per trip', 'patsch9-rental-engine' ),\n"
        "                'total'                  => __( 'total', 'patsch9-rental-engine' ),\n"
    )
    if "'perTrip'" not in text:
        if marker not in text:
            raise RuntimeError('Could not find frontend localization marker')
        text = text.replace(marker, addition, 1)
    path.write_text(text, encoding='utf-8')


def main() -> None:
    patch_core()
    patch_deposits()
    patch_other_php()
    patch_front_js()
    patch_localized_js_strings()
    payload = {
        'msgid_map': MSGID_MAP,
        'new_translations': NEW_TRANSLATIONS,
        'extra_translations': {
            'selected weekday': 'gewählten Wochentag',
            'Rental security deposit': 'Mietkaution',
            'Delivery – ': 'Lieferung – ',
            'Transport – ': 'Transport – ',
            'Security deposit – ': 'Kaution – ',
            'Rental security deposit: The block for an unclear online refund was cleared after manual review.': 'Mietkaution: Sperre für unklare Online-Rückzahlung wurde nach manueller Prüfung aufgehoben.',
        },
    }
    MAP_OUT.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    print('Applied deterministic i18n wording and UI polish.')


if __name__ == '__main__':
    main()
