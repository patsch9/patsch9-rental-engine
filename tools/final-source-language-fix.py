#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def replace_exact(path: Path, replacements: dict[str, str]) -> None:
    text = path.read_text(encoding="utf-8")
    original = text
    for old, new in replacements.items():
        if old not in text:
            raise SystemExit(f"Expected block not found in {path}: {old[:120]!r}")
        text = text.replace(old, new)
    if text == original:
        raise SystemExit(f"No changes produced for {path}")
    path.write_text(text, encoding="utf-8")


def main() -> None:
    documents = ROOT / "includes/v2/modules/class-rmwc-v2-documents.php"
    replace_exact(
        documents,
        {
            "$prefix = 'missing_terms_appendix' === sanitize_key( $snapshot['correction']['type'] ?? '' ) ? 'rental-agreement-corrected' : __( 'Rental agreement', 'patsch9-rental-engine' );":
                "$prefix = 'missing_terms_appendix' === sanitize_key( $snapshot['correction']['type'] ?? '' ) ? 'rental-agreement-corrected' : 'rental-agreement';",
            "$prefix = 'uebergabeprotokoll';": "$prefix = 'handover-protocol';",
            "$prefix = 'rueckgabeprotokoll';": "$prefix = 'return-protocol';",
            "$prefix = 'kautionsquittung';": "$prefix = 'security-deposit-receipt';",
            "$prefix = 'kautionsrueckzahlung';": "$prefix = 'security-deposit-refund';",
            "$prefix = 'mietdokument';": "$prefix = 'rental-document';",
            "return $base ?: 'mietdokument.pdf';": "return $base ?: 'rental-document.pdf';",
            "$stem = 'mietdokument';": "$stem = 'rental-document';",
            "'Mietdokument-E-Mail konnte nicht versendet werden: ' . substr( sanitize_text_field( $exception->getMessage() ), 0, 500 ),":
                "'Rental document email could not be sent: ' . substr( sanitize_text_field( $exception->getMessage() ), 0, 500 ),",
        },
    )

    integrations = ROOT / "includes/v2/modules/class-rmwc-v2-integrations.php"
    replace_exact(
        integrations,
        {
            """    public function display_order_item_meta_value( $display_value, $meta, $item ) {
        if ( ! $item instanceof WC_Order_Item_Product || ! is_object( $meta ) || 'Kautionshinterlegung' !== (string) ( $meta->key ?? '' ) ) {
            return $display_value;
        }
""":
            """    public function display_order_item_meta_value( $display_value, $meta, $item ) {
        $deposit_meta_keys = [ __( 'Security deposit method', 'patsch9-rental-engine' ), 'Kautionshinterlegung' ];
        if ( ! $item instanceof WC_Order_Item_Product || ! is_object( $meta ) || ! in_array( (string) ( $meta->key ?? '' ), $deposit_meta_keys, true ) ) {
            return $display_value;
        }
""",
            """            foreach ( $meta_data as &$meta_row ) {
                if ( is_array( $meta_row ) && 'Kautionshinterlegung' === (string) ( $meta_row['key'] ?? '' ) && (float) ( $rental['deposit'] ?? 0 ) > 0 ) {
""":
            """            $deposit_meta_keys = [ __( 'Security deposit method', 'patsch9-rental-engine' ), 'Kautionshinterlegung' ];
            foreach ( $meta_data as &$meta_row ) {
                if ( is_array( $meta_row ) && in_array( (string) ( $meta_row['key'] ?? '' ), $deposit_meta_keys, true ) && (float) ( $rental['deposit'] ?? 0 ) > 0 ) {
""",
            """                    wp_date( 'd.m.Y H:i', strtotime( $start_at ) ),
                    wp_date( 'd.m.Y H:i', strtotime( $end_at ) )
""":
            """                    wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $start_at ), wp_timezone() ),
                    wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $end_at ), wp_timezone() )
""",
        },
    )

    source_check = ROOT / "tests/check-source-language.py"
    replace_exact(
        source_check,
        {
            """    "Abholung",
    "Rückgabe",
}
""":
            """    "Abholung",
    "Rückgabe",
    # Internal prerelease status enum retained to avoid rewriting historic orders.
    "einbehalten",
    # Historic order-item label recognized alongside the current translated key.
    "Kautionshinterlegung",
    # Compatibility parser for Germanized/WooCommerce tax labels; not UI output.
    "/(?:MwSt\\.?|Umsatzsteuer|Mehrwertsteuer|VAT)/iu",
}
""",
        },
    )

    print("Applied final source-language compatibility fixes.")


if __name__ == "__main__":
    main()
