#!/usr/bin/env python3
"""Wrap remaining raw user-facing PHP strings with WordPress i18n calls.

This is a one-off source transformation. It intentionally avoids database/meta
lookup keys and string-comparison operands; those are reviewed separately as
compatibility identifiers instead of being translated implicitly.
"""
from __future__ import annotations

import html
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOMAIN = 'patsch9-rental-engine'
EXCLUDED = {'.git', '.github', 'languages', 'tools', 'tests', 'node_modules', 'vendor'}

GERMAN = re.compile(
    r'(?:[äöüÄÖÜß]|\b(?:und|oder|für|mit|ohne|nicht|keine|kein|bitte|dies(?:e|er|es)?|wird|werden|wurde|'
    r'vermiet\w*|miet\w*|buchung\w*|kaution\w*|abhol\w*|rückgab\w*|übergab\w*|liefer\w*|kunde\w*|'
    r'gerät\w*|einstellungen?|speichern|gespeichert|bearbeiten|löschen|wählen|auswählen|verfüg\w*|preis\w*|'
    r'tage?|woche\w*|monat\w*|adresse\w*|bestellung\w*|zahlung\w*|erinnerung\w*|inventarnummer|seriennummer|'
    r'schäden?|quelle|produkt|zeitraum|anzahl|status|grunddaten|zeiten|abrechnung|standardpreis|tagespreis|'
    r'vorausbuchung|sperr\w*|wochentag\w*|allgemeine|ergänzende|akzeptierte|hinweis|datenschutz|aktiv|bereinigung|'
    r'rückerstattung\w*|transport\w*|entfernung\w*|fahrstrecke|fahrpreis|uhr|stunden?|minuten?|erhalten|offen|'
    r'einbehalten|erlassen|beschädigung|reinigung\w*|verlust|sonstiger|montag|dienstag|mittwoch|donnerstag|'
    r'freitag|samstag|sonntag|hallo|viele\s+grüße|grund|hinfahrt|rückfahrt)\b)',
    re.IGNORECASE,
)

SINGLE = r"'(?:\\.|[^'\\])*'"


def decode_single(literal: str) -> str:
    body = literal[1:-1]
    return body.replace("\\'", "'").replace('\\\\', '\\')


def quote_php(value: str) -> str:
    return "'" + value.replace('\\', '\\\\').replace("'", "\\'") + "'"


def is_german_literal(literal: str) -> bool:
    try:
        return bool(GERMAN.search(decode_single(literal)))
    except Exception:
        return False


def wrapped(literal: str, escape: str = '__') -> str:
    return f"{escape}( {literal}, '{DOMAIN}' )"


def replace_arrow_values(source: str) -> str:
    pattern = re.compile(rf"(?P<prefix>=>\s*)(?P<lit>{SINGLE})")

    def repl(match: re.Match[str]) -> str:
        lit = match.group('lit')
        if not is_german_literal(lit):
            return match.group(0)
        return match.group('prefix') + wrapped(lit)

    return pattern.sub(repl, source)


def wrap_first_literal_arg(source: str, function_pattern: str) -> str:
    pattern = re.compile(rf"(?P<prefix>{function_pattern}\s*\(\s*)(?P<lit>{SINGLE})")

    def repl(match: re.Match[str]) -> str:
        lit = match.group('lit')
        if not is_german_literal(lit):
            return match.group(0)
        return match.group('prefix') + wrapped(lit)

    return pattern.sub(repl, source)


def wrap_sprintf_formats(source: str) -> str:
    return wrap_first_literal_arg(source, r'sprintf')


def wrap_wp_error_direct(source: str) -> str:
    pattern = re.compile(rf"(?P<prefix>new\s+WP_Error\s*\(\s*{SINGLE}\s*,\s*)(?P<lit>{SINGLE})")

    def repl(match: re.Match[str]) -> str:
        lit = match.group('lit')
        return match.group('prefix') + (wrapped(lit) if is_german_literal(lit) else lit)

    return pattern.sub(repl, source)


def wrap_get_option_default(source: str) -> str:
    pattern = re.compile(rf"(?P<prefix>get_option\s*\(\s*{SINGLE}\s*,\s*)(?P<lit>{SINGLE})")

    def repl(match: re.Match[str]) -> str:
        lit = match.group('lit')
        return match.group('prefix') + (wrapped(lit) if is_german_literal(lit) else lit)

    return pattern.sub(repl, source)


def wrap_assignment_values(source: str) -> str:
    patterns = [
        re.compile(rf"(?P<prefix>\.\=\s*)(?P<lit>{SINGLE})"),
        re.compile(rf"(?P<prefix>\[\]\s*=\s*)(?P<lit>{SINGLE})"),
        re.compile(rf"(?P<prefix>\?\s*{SINGLE}\s*:\s*)(?P<lit>{SINGLE})"),
    ]
    for pattern in patterns:
        def repl(match: re.Match[str]) -> str:
            lit = match.group('lit')
            return match.group('prefix') + (wrapped(lit) if is_german_literal(lit) else lit)
        source = pattern.sub(repl, source)
    return source


def process_inline_html(segment: str) -> str:
    # Translate accessibility/placeholder attributes. Value-like attributes are
    # deliberately left alone because they are protocol/data identifiers.
    attr_pattern = re.compile(r'(?P<name>placeholder|title|aria-label)="(?P<value>[^"<>]*[A-Za-zÄÖÜäöüß][^"<>]*)"')

    def attr_repl(match: re.Match[str]) -> str:
        value = html.unescape(match.group('value')).strip()
        if not value or '<?php' in value:
            return match.group(0)
        return f'{match.group("name")}="<?php esc_attr_e( {quote_php(value)}, \'{DOMAIN}\' ); ?>"'

    segment = attr_pattern.sub(attr_repl, segment)

    # Translate visible text nodes. Wrapping all alphabetic text nodes is safer
    # than language guessing here: English source text should be translatable too.
    text_pattern = re.compile(r'>(?P<value>[^<>]+)<')

    def text_repl(match: re.Match[str]) -> str:
        raw = match.group('value')
        if '<?php' in raw or not re.search(r'[A-Za-zÄÖÜäöüß]', raw):
            return match.group(0)
        leading = raw[: len(raw) - len(raw.lstrip())]
        trailing = raw[len(raw.rstrip()):]
        value = html.unescape(raw.strip())
        if not value:
            return match.group(0)
        return '>' + leading + f'<?php esc_html_e( {quote_php(value)}, \'{DOMAIN}\' ); ?>' + trailing + '<'

    return text_pattern.sub(text_repl, segment)


def wrap_inline_html(source: str) -> str:
    pattern = re.compile(r'(?P<close>\?>)(?P<html>.*?)(?P<open><\?php)', re.DOTALL)
    return pattern.sub(lambda m: m.group('close') + process_inline_html(m.group('html')) + m.group('open'), source)


def patch_core_specials(source: str) -> str:
    # Product-data tab title is a visible WooCommerce label.
    source = source.replace("'label'    => 'Vermietung',", "'label'    => __( 'Vermietung', 'patsch9-rental-engine' ),")

    # Admin menu titles.
    source = source.replace(
        "add_submenu_page( 'woocommerce', 'Vermietungen', 'Vermietungen', 'manage_woocommerce'",
        "add_submenu_page( 'woocommerce', __( 'Vermietungen', 'patsch9-rental-engine' ), __( 'Vermietungen', 'patsch9-rental-engine' ), 'manage_woocommerce'"
    )

    # Submit buttons and product-search placeholder emitted from PHP strings.
    source = re.sub(
        rf"submit_button\(\s*(?P<lit>{SINGLE})\s*\)",
        lambda m: f"submit_button( {wrapped(m.group('lit'))} )" if is_german_literal(m.group('lit')) else m.group(0),
        source,
    )

    # Simple notice HTML strings returned by admin handlers.
    notice = re.compile(r"return '<div class=\\\"notice notice-(?P<type>[a-z-]+)\\\"><p>(?P<text>[^<]+)</p></div>';" )
    source = notice.sub(
        lambda m: "return '<div class=\"notice notice-" + m.group('type') + "\"><p>' . esc_html__( " + quote_php(html.unescape(m.group('text'))) + ", 'patsch9-rental-engine' ) . '</p></div>';",
        source,
    )

    return source


def process_php(path: Path) -> None:
    source = path.read_text(encoding='utf-8')
    original = source

    source = wrap_inline_html(source)
    source = replace_arrow_values(source)
    source = wrap_sprintf_formats(source)
    source = wrap_wp_error_direct(source)
    source = wrap_get_option_default(source)
    source = wrap_assignment_values(source)

    for function in [
        r'wc_add_notice',
        r'wp_die',
        r'submit_button',
    ]:
        source = wrap_first_literal_arg(source, function)

    if path.name == 'class-rmwc-plugin.php' and path.parent.name == 'includes':
        source = patch_core_specials(source)

    if source != original:
        path.write_text(source, encoding='utf-8')


def main() -> None:
    changed = 0
    for path in sorted(ROOT.rglob('*.php')):
        if set(path.relative_to(ROOT).parts) & EXCLUDED:
            continue
        before = path.read_text(encoding='utf-8')
        process_php(path)
        if path.read_text(encoding='utf-8') != before:
            changed += 1
    print(f'Wrapped raw UI strings in {changed} PHP files.')


if __name__ == '__main__':
    main()
