#!/usr/bin/env python3
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]


def replace(path, old, new, count=1):
    p = ROOT / path
    text = p.read_text(encoding='utf-8')
    actual = text.count(old)
    if actual != count:
        raise SystemExit(f'{path}: expected {count} occurrences, found {actual}: {old[:100]!r}')
    p.write_text(text.replace(old, new), encoding='utf-8')


def regex_replace(path, pattern, repl, count=1, flags=0):
    p = ROOT / path
    text = p.read_text(encoding='utf-8')
    new, actual = re.subn(pattern, repl, text, count=count, flags=flags)
    if actual != count:
        raise SystemExit(f'{path}: expected {count} regex replacements, got {actual}: {pattern}')
    p.write_text(new, encoding='utf-8')

plugin = 'includes/class-rmwc-plugin.php'

replace(
    plugin,
    "        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '';\n        if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {",
    "        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '';\n        /**\n         * Filters the client IP used only for the short-lived abuse-prevention fingerprint.\n         * Reverse-proxy integrations may supply the trusted originating address here.\n         * The filtered value is still validated below and is never stored directly.\n         */\n        $ip = trim( (string) apply_filters( 'rmwc_client_ip', $ip ) );\n        if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {"
)

replace(
    plugin,
    "    private function public_rental_product( $product_id ) {",
    "    /**\n     * Apply the shared budget before creating/updating a per-client transient.\n     * This bounds transient churn during distributed abuse.\n     */\n    private function public_request_rate_allowed( $bucket, $global_limit, $client_limit, $window = MINUTE_IN_SECONDS ) {\n        return $this->global_rate_allowed( $bucket, $global_limit, $window )\n            && $this->request_rate_allowed( $bucket, $client_limit, $window );\n    }\n\n    private function public_rental_product( $product_id ) {"
)

replace(
    plugin,
    "    private function is_rental( $product_id ) {\n        return $product_id && 'yes' === get_post_meta( $product_id, '_rmwc_rental_enabled', true );\n    }\n\n    private function config_product_id",
    "    private function is_rental( $product_id ) {\n        return $product_id && 'yes' === get_post_meta( $product_id, '_rmwc_rental_enabled', true );\n    }\n\n    private function delivery_allowed( $product_id ) {\n        return 'yes' === get_post_meta( $product_id, '_rmwc_allow_delivery', true )\n            || 'yes' === get_post_meta( $product_id, '_rmwc_allow_delivery_return', true );\n    }\n\n    private function config_product_id"
)

replace(plugin, "                    'label'             => 'Mindestmietdauer',", "                    'label'             => __( 'Minimum rental period', 'patsch9-rental-engine' ),")
replace(plugin, "            if ( ! $this->request_rate_allowed( 'availability', 120 ) || ! $this->global_rate_allowed( 'availability', 1200 ) ) {", "            if ( ! $this->public_request_rate_allowed( 'availability', 1200, 120 ) ) {")
replace(plugin, "        if ( ! $this->request_rate_allowed( 'delivery_quote', 20 ) || ! $this->global_rate_allowed( 'delivery_quote', 180 ) ) {", "        if ( ! $this->public_request_rate_allowed( 'delivery_quote', 180, 20 ) ) {")
replace(plugin, "            if ( ! $this->request_rate_allowed( 'delivery_cart', 20 ) || ! $this->global_rate_allowed( 'delivery_cart', 180 ) ) {", "            if ( ! $this->public_request_rate_allowed( 'delivery_cart', 180, 20 ) ) {")

replace(
    plugin,
    "        if ( ! $product_id || ! $this->public_rental_product( $product_id ) || 'yes' !== get_post_meta( $product_id, '_rmwc_allow_delivery', true ) ) {",
    "        if ( ! $product_id || ! $this->public_rental_product( $product_id ) || ! $this->delivery_allowed( $product_id ) ) {"
)
replace(
    plugin,
    "        if ( 'yes' !== get_post_meta( $product_id, '_rmwc_allow_delivery', true ) ) {\n            return new WP_Error( 'rmwc_delivery_disabled', __( 'No delivery is possible for this product.', 'patsch9-rental-engine' ) );\n        }",
    "        if ( ! $this->delivery_allowed( $product_id ) ) {\n            return new WP_Error( 'rmwc_delivery_disabled', __( 'No delivery is possible for this product.', 'patsch9-rental-engine' ) );\n        }"
)

replace(plugin, "            $start         = DateTimeImmutable::createFromFormat( '!Y-m-d', $start_date );\n            $end           = DateTimeImmutable::createFromFormat( '!Y-m-d', $end_date );", "            $start         = DateTimeImmutable::createFromFormat( '!Y-m-d', $start_date, wp_timezone() );\n            $end           = DateTimeImmutable::createFromFormat( '!Y-m-d', $end_date, wp_timezone() );")
replace(plugin, "                    'label' => $used_override ? 'Wochentagstarif' : 'Standardtarif',", "                    'label' => $used_override ? __( 'Weekday rate', 'patsch9-rental-engine' ) : __( 'Standard rate', 'patsch9-rental-engine' ),")
replace(plugin, "        return [ 'price' => $price, 'label' => 'Standardtarif', 'units' => $units ];", "        return [ 'price' => $price, 'label' => __( 'Standard rate', 'patsch9-rental-engine' ), 'units' => $units ];")

replace(plugin, "        echo '<p><label>Status<br><select name=\"rmwc_deposit_status\" style=\"width:100%\">';", "        echo '<p><label>' . esc_html__( 'Status', 'patsch9-rental-engine' ) . '<br><select name=\"rmwc_deposit_status\" style=\"width:100%\">';")
replace(plugin, "            return '<div class=\"notice notice-success\"><p>Sperrzeit angelegt.</p></div>';", "            return '<div class=\"notice notice-success\"><p>' . esc_html__( 'Blocked period created.', 'patsch9-rental-engine' ) . '</p></div>';")
replace(plugin, "                    <p><label>Produkt<?php $this->rental_product_search_field( 'product_id' ); ?></label></p>", "                    <p><label><?php esc_html_e( 'Product', 'patsch9-rental-engine' ); ?><?php $this->rental_product_search_field( 'product_id' ); ?></label></p>", count=2)

regex_replace(
    plugin,
    r"\[\s*'Mo'\s*,\s*'Di'\s*,\s*'Mi'\s*,\s*'Do'\s*,\s*'Fr'\s*,\s*'Sa'\s*,\s*'So'\s*\]",
    "[ __( 'Mon', 'patsch9-rental-engine' ), __( 'Tue', 'patsch9-rental-engine' ), __( 'Wed', 'patsch9-rental-engine' ), __( 'Thu', 'patsch9-rental-engine' ), __( 'Fri', 'patsch9-rental-engine' ), __( 'Sat', 'patsch9-rental-engine' ), __( 'Sun', 'patsch9-rental-engine' ) ]"
)

replace(plugin, "                    '%name%'    => $row['customer_name'] ?: 'Guten Tag',", "                    '%name%'    => $row['customer_name'] ?: __( 'Customer', 'patsch9-rental-engine' ),")
replace(plugin, "                'calculateTransport'     => __( 'Calculate transport costs', 'patsch9-rental-engine' ),", "                'calculateTransport'     => __( 'Calculate transport costs', 'patsch9-rental-engine' ),\n                'calculating'             => __( 'Calculating …', 'patsch9-rental-engine' ),")

replace('assets/rental.js', "    btn.prop('disabled',true).text('Berechne …');", "    btn.prop('disabled',true).text(t('calculating','Calculating …'));")

replace('includes/v2/modules/class-rmwc-v2-documents.php', "                    $lines[] = 'Akzeptiert am ' . $this->display_datetime( $snapshot['accepted_at'] );", "                    $lines[] = __( 'Accepted on', 'patsch9-rental-engine' ) . ' ' . $this->display_datetime( $snapshot['accepted_at'] );", count=1)
replace('includes/v2/modules/class-rmwc-v2-documents.php', "                $lines[] = 'Akzeptiert am ' . $this->display_datetime( $snapshot['accepted_at'] );", "                $lines[] = __( 'Accepted on', 'patsch9-rental-engine' ) . ' ' . $this->display_datetime( $snapshot['accepted_at'] );", count=1)

pdf = 'includes/v2/class-rmwc-pdf.php'
replace(
    pdf,
    '                $stream .= "BT /F1 8 Tf 50 170 Td (Dokumentationsunterschrift:) Tj ET\\n";',
    "                $signature_label = __( 'Documentation signature', 'patsch9-rental-engine' ) . ':';\n                $stream .= 'BT /F1 8 Tf 50 170 Td (' . self::pdf_escape( self::encode( $signature_label ) ) . \") Tj ET\\n\";"
)
replace(
    pdf,
    '            $stream .= "BT /F1 8 Tf 50 28 Td (Seite " . ( $page_index + 1 ) . \' / \' . count( $pages ) . ") Tj ET\\n";',
    "            $page_label = __( 'Page', 'patsch9-rental-engine' ) . ' ' . ( $page_index + 1 ) . ' / ' . count( $pages );\n            $stream .= 'BT /F1 8 Tf 50 28 Td (' . self::pdf_escape( self::encode( $page_label ) ) . \") Tj ET\\n\";"
)
replace(pdf, "            $page .= $text_command( 'Dokumentationsunterschrift', 'F2', 9.2, 58, $y );", "            $page .= $text_command( __( 'Documentation signature', 'patsch9-rental-engine' ), 'F2', 9.2, 58, $y );")
replace(pdf, "            $stream .= $text_command( 'Seite ' . ( $page_index + 1 ) . ' / ' . $page_count, 'F1', 7.8, 485, 30 );", "            $stream .= $text_command( __( 'Page', 'patsch9-rental-engine' ) . ' ' . ( $page_index + 1 ) . ' / ' . $page_count, 'F1', 7.8, 485, 30 );")

# Source-language policy: cover PHP and frontend/admin JS, while retaining only explicit legacy data values.
lang = 'tests/check-source-language.py'
replace(lang, '"""Reject German UI literals in production PHP source.', '"""Reject German UI literals in production PHP and JavaScript source.')
replace(lang, 'SOURCE_ROOTS = [ROOT / "includes", ROOT / "patsch9-rental-engine.php"]', 'SOURCE_ROOTS = [ROOT / "includes", ROOT / "assets", ROOT / "patsch9-rental-engine.php"]')
replace(
    lang,
    '    r"Vertrags\\w*|Prüf\\w*|Buchung\\w*|Gerät\\w*|Übergabe\\w*|Rückzahl\\w*|MwSt\\w*)\\b)",',
    '    r"Vertrags\\w*|Prüf\\w*|Buchung\\w*|Gerät\\w*|Übergabe\\w*|Rückzahl\\w*|MwSt\\w*|"\n    r"Mindest\\w*|Wochentag\\w*|Standardtarif\\w*|Sperrzeit\\w*|Produkt\\b|"\n    r"Akzeptiert\\b|Dokumentationsunterschrift\\w*|Seite\\b|Guten\\s+Tag|Berechne\\w*)\\b)",'
)
replace(lang, '    "einbehalten",\n', '    "einbehalten",\n    "offen",\n')
replace(
    lang,
    'def iter_php_files():\n    for root in SOURCE_ROOTS:\n        if root.is_file():\n            yield root\n        else:\n            yield from sorted(root.rglob("*.php"))',
    'def iter_php_files():\n    for root in SOURCE_ROOTS:\n        if root.is_file():\n            yield root\n        else:\n            for pattern in ("*.php", "*.js"):\n                yield from sorted(root.rglob(pattern))'
)

# Version and release documentation.
replace('patsch9-rental-engine.php', ' * Version: 2026.10.2', ' * Version: 2026.10.3')
replace('patsch9-rental-engine.php', "define( 'RMWC_VERSION', '2026.10.2' );", "define( 'RMWC_VERSION', '2026.10.3' );")
replace('readme.txt', 'Stable tag: 2026.10.2', 'Stable tag: 2026.10.3')
replace('README.md', 'Entwicklungsstand: **2026.10.2**', 'Entwicklungsstand: **2026.10.3**')
replace(
    'CHANGELOG.md',
    '## 2026.10.2 – Noch nicht veröffentlicht\n',
    '## 2026.10.3 – Noch nicht veröffentlicht\n\n'
    '- Produkte mit ausschließlich aktivierter Lieferung und späterer Abholung durch den Vermieter werden in Distanzberechnung und Warenkorbserverlogik korrekt akzeptiert.\n'
    '- Öffentliche Rate-Limits prüfen zuerst das globale Budget; der optionale, weiterhin validierte Filter `rmwc_client_ip` unterstützt vertrauenswürdige Reverse-Proxy-Integrationen ohne Roh-IP-Speicherung.\n'
    '- Die Wochenendpreisberechnung verwendet konsistent die WordPress-Zeitzone.\n'
    '- Verbliebene deutsche UI-Literale in Administration, Erinnerungen, PDF-Dokumenten und JavaScript wurden auf englische Gettext-Quelltexte umgestellt.\n'
    '- Der Source-Language-Check erfasst nun auch JavaScript und zusätzliche deutsche Wortstämme; historische interne Statuswerte bleiben explizit als Kompatibilitätsdaten erlaubt.\n'
    '- Regressionstests für Delivery-Return-Konfiguration und die Rate-Limit-/Client-IP-Härtung ergänzt.\n\n'
    '## 2026.10.2 – Noch nicht veröffentlicht\n'
)

# Add runtime regressions to the existing disposable WordPress/WooCommerce test fixture.
test = 'tests/test-review-security.php'
marker = "set_current_screen( 'dashboard' );\n"
insert = r'''$plugin = RMWC_Plugin::instance();
$delivery_allowed = new ReflectionMethod( $plugin, 'delivery_allowed' );
$delivery_allowed->setAccessible( true );
update_post_meta( $product_id, '_rmwc_allow_delivery', 'no' );
update_post_meta( $product_id, '_rmwc_allow_delivery_return', 'yes' );
$check( true === $delivery_allowed->invoke( $plugin, $product_id ), 'Delivery/collection-only configuration is accepted by server-side delivery paths' );
update_post_meta( $product_id, '_rmwc_allow_delivery_return', 'no' );
$check( false === $delivery_allowed->invoke( $plugin, $product_id ), 'Delivery remains disabled when neither transport mode is enabled' );

$request_rate = new ReflectionMethod( $plugin, 'request_rate_allowed' );
$request_rate->setAccessible( true );
$rate_bucket = 'review_client_' . strtolower( wp_generate_password( 8, false, false ) );
$ip_one = static function() { return '203.0.113.10'; };
$ip_two = static function() { return '203.0.113.11'; };
add_filter( 'rmwc_client_ip', $ip_one );
$check( true === $request_rate->invoke( $plugin, $rate_bucket, 1, MINUTE_IN_SECONDS ), 'Filtered client IP receives its own rate-limit budget' );
remove_filter( 'rmwc_client_ip', $ip_one );
add_filter( 'rmwc_client_ip', $ip_two );
$check( true === $request_rate->invoke( $plugin, $rate_bucket, 1, MINUTE_IN_SECONDS ), 'A second validated filtered client IP receives a separate budget' );
remove_filter( 'rmwc_client_ip', $ip_two );

$public_rate = new ReflectionMethod( $plugin, 'public_request_rate_allowed' );
$public_rate->setAccessible( true );
$global_bucket = 'review_global_' . strtolower( wp_generate_password( 8, false, false ) );
$global_ip = '203.0.113.12';
$global_ip_filter = static function() use ( $global_ip ) { return $global_ip; };
add_filter( 'rmwc_client_ip', $global_ip_filter );
$fingerprint = hash_hmac( 'sha256', $global_ip, wp_salt( 'nonce' ) );
$client_key = 'rmwc_rate_' . sanitize_key( $global_bucket ) . '_' . substr( $fingerprint, 0, 32 );
delete_transient( $client_key );
set_transient( 'rmwc_global_rate_' . sanitize_key( $global_bucket ), 1, MINUTE_IN_SECONDS );
$check(
    false === $public_rate->invoke( $plugin, $global_bucket, 1, 1, MINUTE_IN_SECONDS ) && false === get_transient( $client_key ),
    'Exhausted global budget short-circuits before creating a per-client transient'
);
remove_filter( 'rmwc_client_ip', $global_ip_filter );
delete_transient( 'rmwc_global_rate_' . sanitize_key( $global_bucket ) );
delete_transient( $client_key );

'''
replace(test, marker, insert + marker)

print('Applied 2026.10.3 review-hardening patch.')
