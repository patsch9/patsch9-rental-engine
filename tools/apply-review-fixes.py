#!/usr/bin/env python3
"""Apply the one-off WordPress.org review refactor to the rental plugin.

The transformation intentionally separates the public rmwc_ namespace from the
historic short namespace. Existing installations are migrated at runtime by
RMWC_Legacy_Migration; legacy identifiers must not remain active registrations.
"""
from __future__ import annotations

from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]

SOURCE_SUFFIXES = {'.php', '.js', '.css'}
EXCLUDED_PARTS = {'.git', '.github', 'tools', 'languages', 'tests', 'node_modules', 'vendor'}


def source_files():
    for path in ROOT.rglob('*'):
        if not path.is_file() or path.suffix not in SOURCE_SUFFIXES:
            continue
        if set(path.relative_to(ROOT).parts) & EXCLUDED_PARTS:
            continue
        yield path


def replace_namespace() -> None:
    for path in source_files():
        text = path.read_text(encoding='utf-8')
        original = text

        if path.name == 'class-rmwc-plugin.php':
            # Remove public legacy AJAX registrations. They become duplicate
            # rmwc_ handlers after the namespace replacement and, more
            # importantly, keep a short public WordPress hook prefix alive.
            text = '\n'.join(
                line for line in text.split('\n')
                if 'Legacy 1.0.x alias.' not in line
            )
            # Runtime migration is centralized in RMWC_Legacy_Migration.
            text = text.replace("        $this->migrate_legacy_bookings();\n", '')
            text = re.sub(
                r"\n\s*// Migrate the historic short cron hook to the namespaced hook\.\n"
                r"\s*if \( wp_next_scheduled\( 'clr_hourly_reminders' \) \) \{\n"
                r"\s*wp_clear_scheduled_hook\( 'clr_hourly_reminders' \);\n"
                r"\s*\}\n",
                '\n',
                text,
                count=1,
            )

        if path.name == 'patsch9-rental-engine.php':
            text = text.replace("    wp_clear_scheduled_hook( 'clr_hourly_reminders' ); // Legacy 1.0.x hook.\n", '')

        # Technical namespace: persistent keys, request fields, hooks, error
        # codes, page slugs and matching DOM/CSS selectors all move together.
        text = text.replace('_clr_', '_rmwc_')
        text = text.replace('clr_', 'rmwc_')
        text = text.replace('clr-', 'rmwc-')

        if text != original:
            path.write_text(text, encoding='utf-8')


def patch_metadata() -> None:
    main = ROOT / 'patsch9-rental-engine.php'
    text = main.read_text(encoding='utf-8')
    text = text.replace('Version: 2026.10.0', 'Version: 2026.10.1')
    text = text.replace("define( 'RMWC_VERSION', '2026.10.0' );", "define( 'RMWC_VERSION', '2026.10.1' );")
    text = text.replace('Requires at least: 6.9.5', 'Requires at least: 6.9')
    main.write_text(text, encoding='utf-8')

    readme = ROOT / 'readme.txt'
    text = readme.read_text(encoding='utf-8')
    text = text.replace('Requires at least: 6.9.5', 'Requires at least: 6.9')
    text = text.replace('Stable tag: 2026.10.0', 'Stable tag: 2026.10.1')
    text = text.replace('* WordPress 6.9.5 or newer.', '* WordPress 6.9 or newer.')
    changelog_marker = '== Changelog ==\n\n'
    new_entry = (
        '= 2026.10.1 =\n'
        '* WordPress.org review hardening: canonical rmwc_ namespace with safe migration from historic identifiers.\n'
        '* Corrected WordPress minimum-version metadata and Accounting Bridge integration hook.\n'
        '* Added complete internationalization infrastructure with English source strings and bundled German translation.\n\n'
    )
    if '= 2026.10.1 =' not in text:
        text = text.replace(changelog_marker, changelog_marker + new_entry, 1)
    text = text.replace('== Upgrade Notice ==\n\n= 2026.10.0 =', '== Upgrade Notice ==\n\n= 2026.10.1 =\nMigrates historic internal identifiers to the canonical rmwc_ namespace. Back up before updating from prerelease builds.\n\n= 2026.10.0 =')
    readme.write_text(text, encoding='utf-8')

    changelog = ROOT / 'CHANGELOG.md'
    text = changelog.read_text(encoding='utf-8')
    if '2026.10.1' not in text:
        insertion = (
            '\n## 2026.10.1 – Noch nicht veröffentlicht\n\n'
            '- WordPress.org-Review-Fixes: technischer `rmwc_`-Namespace statt des historischen Kurzpräfixes.\n'
            '- Sichere Migration bestehender Tabellen, Optionen sowie Post-, Order-Item- und HPOS-Metadaten.\n'
            '- `Requires at least` auf Major/Minor korrigiert.\n'
            '- Accounting-Bridge-Integration auf `patsacbr_payment_terms_addition` aktualisiert.\n'
            '- Internationalisierung auf englische Originaltexte mit vollständigem deutschem `de_DE`-Katalog umgestellt.\n'
        )
        lines = text.splitlines()
        # Keep the existing title/introduction and place the unreleased patch first.
        pos = 0
        for i, line in enumerate(lines):
            if line.startswith('## '):
                pos = i
                break
        if pos:
            text = '\n'.join(lines[:pos]) + insertion + '\n' + '\n'.join(lines[pos:]) + '\n'
        else:
            text = text.rstrip() + insertion + '\n'
    changelog.write_text(text, encoding='utf-8')


def patch_integration() -> None:
    path = ROOT / 'includes/v2/modules/class-rmwc-v2-deposits.php'
    text = path.read_text(encoding='utf-8')
    text = text.replace("add_filter( 'wlc_payment_terms_addition'", "add_filter( 'patsacbr_payment_terms_addition'")
    path.write_text(text, encoding='utf-8')


def patch_bootstrap() -> None:
    path = ROOT / 'patsch9-rental-engine.php'
    text = path.read_text(encoding='utf-8')
    marker = "defined( 'ABSPATH' ) || exit;\n"
    bootstrap = """

require_once plugin_dir_path( __FILE__ ) . 'includes/class-rmwc-legacy-migration.php';

/**
 * Load bundled translations for direct/GitHub installs. WordPress.org language
 * packs can override these files through the normal translation update system.
 */
function rmwc_load_textdomain() {
    load_plugin_textdomain( 'patsch9-rental-engine', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'rmwc_load_textdomain' );

"""
    if 'class-rmwc-legacy-migration.php' not in text:
        text = text.replace(marker, marker + bootstrap, 1)

    text = text.replace(
        "function rmwc_activate() {\n    rmwc_install_database();",
        "function rmwc_activate() {\n    RMWC_Legacy_Migration::run();\n    rmwc_install_database();",
        1,
    )
    plugins_loaded = "add_action( 'plugins_loaded', function() {\n"
    if "RMWC_Legacy_Migration::run();\n    if ( get_option( 'rmwc_db_version' )" not in text:
        text = text.replace(
            plugins_loaded,
            plugins_loaded + "    RMWC_Legacy_Migration::run();\n",
            1,
        )
    path.write_text(text, encoding='utf-8')


def patch_distignore() -> None:
    path = ROOT / '.distignore'
    text = path.read_text(encoding='utf-8')
    if '/tools/' not in text:
        text += '/tools/\n'
    path.write_text(text, encoding='utf-8')


def main() -> None:
    replace_namespace()
    patch_metadata()
    patch_integration()
    patch_bootstrap()
    patch_distignore()
    print('Applied WordPress.org review refactor.')


if __name__ == '__main__':
    main()
