#!/usr/bin/env python3
"""Localize the few frontend JavaScript strings left after the first pass."""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CORE = ROOT / 'includes' / 'class-rmwc-plugin.php'
FRONT = ROOT / 'assets' / 'rental.js'


def main() -> None:
    core = CORE.read_text(encoding='utf-8')
    marker = "                'selected'               => __( 'Selected', 'patsch9-rental-engine' ),\n"
    addition = marker + (
        "                'rentalDay'              => __( 'rental day', 'patsch9-rental-engine' ),\n"
        "                'rentalDays'             => __( 'rental days', 'patsch9-rental-engine' ),\n"
        "                'withoutAddonSelected'   => __( 'No add-on selected', 'patsch9-rental-engine' ),\n"
        "                'selectAccessory'        => __( 'Please select at least one accessory option.', 'patsch9-rental-engine' ),\n"
    )
    if "'withoutAddonSelected'" not in core:
        if marker not in core:
            raise RuntimeError('Frontend string localization marker not found')
        core = core.replace(marker, addition, 1)
    CORE.write_text(core, encoding='utf-8')

    js = FRONT.read_text(encoding='utf-8')
    js = js.replace(
        "$('#rmwc_duration_preview').text(`${days} Miettag(e), ${base.label}; ${iso(start)} ${rules.pickupTime} – ${iso(end)} ${rules.returnTime}`);",
        "const dayLabel=days===1?t('rentalDay','rental day'):t('rentalDays','rental days');\n    $('#rmwc_duration_preview').text(`${days} ${dayLabel}, ${base.label}; ${iso(start)} ${rules.pickupTime} – ${iso(end)} ${rules.returnTime}`);"
    )
    js = js.replace(
        "label=$choice.val()==='0'?'Ohne Zusatz gewählt':t('selected','Selected');",
        "label=$choice.val()==='0'?t('withoutAddonSelected','No add-on selected'):t('selected','Selected');"
    )
    js = js.replace(
        "$first.get(0).setCustomValidity(anySelected?'':'Bitte wählen Sie mindestens eine Zubehör-Option aus.');",
        "$first.get(0).setCustomValidity(anySelected?'':t('selectAccessory','Please select at least one accessory option.'));"
    )
    FRONT.write_text(js, encoding='utf-8')
    print('Localized remaining frontend JavaScript strings.')


if __name__ == '__main__':
    main()
