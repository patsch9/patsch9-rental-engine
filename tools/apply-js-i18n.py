#!/usr/bin/env python3
"""Move JavaScript UI strings behind WordPress-translated localized data."""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CORE = ROOT / 'includes' / 'class-rmwc-plugin.php'
ADMIN = ROOT / 'assets' / 'admin.js'
FRONT = ROOT / 'assets' / 'rental.js'


def replace_once(text: str, old: str, new: str, label: str) -> str:
    if text.count(old) != 1:
        raise RuntimeError(f'{label}: expected exactly one match, got {text.count(old)}')
    return text.replace(old, new, 1)


def patch_php() -> None:
    text = CORE.read_text(encoding='utf-8')
    admin_marker = "            wp_enqueue_script( 'patsch9-rental-engine-admin-product', RMWC_URL . 'assets/admin.js', [ 'jquery', 'wc-enhanced-select' ], RMWC_VERSION, true );\n"
    admin_block = admin_marker + """            wp_localize_script( 'patsch9-rental-engine-admin-product', 'RMWCAdminData', [
                'strings' => [
                    'addonMaterial'          => __( 'Material', 'patsch9-rental-engine' ),
                    'addonFor20People'       => __( 'for 20 people', 'patsch9-rental-engine' ),
                    'once'                   => __( 'once', 'patsch9-rental-engine' ),
                    'perRentalUnit'          => __( 'per rental unit', 'patsch9-rental-engine' ),
                    'perRentalDay'           => __( 'per rental day', 'patsch9-rental-engine' ),
                    'upTo10Km'               => __( 'up to 10 km', 'patsch9-rental-engine' ),
                    'rentalAccessoryProduct' => __( 'Rental accessory product', 'patsch9-rental-engine' ),
                    'searchRentalAccessory'  => __( 'Search rental accessories…', 'patsch9-rental-engine' ),
                    'productSourceNote'      => __( 'Price, tax, stock, and the “Sold individually” setting are taken directly from this WooCommerce product.', 'patsch9-rental-engine' ),
                    'customerDisplay'        => __( 'Customer display', 'patsch9-rental-engine' ),
                    'sweetPopcornExample'    => __( 'e.g. for approx. 1 kg sweet popcorn', 'patsch9-rental-engine' ),
                    'optionalProductName'    => __( 'Optional. Empty = use product name.', 'patsch9-rental-engine' ),
                    'calculation'            => __( 'Calculation', 'patsch9-rental-engine' ),
                    'oncePerBooking'         => __( '1× per booking', 'patsch9-rental-engine' ),
                    'removeAccessory'        => __( 'Remove rental accessory from group', 'patsch9-rental-engine' ),
                    'groupName'              => __( 'Group name', 'patsch9-rental-engine' ),
                    'selectionType'          => __( 'Selection type', 'patsch9-rental-engine' ),
                    'singleSelection'        => __( 'Single selection – at most one option', 'patsch9-rental-engine' ),
                    'multipleSelection'      => __( 'Multiple selection – multiple options allowed', 'patsch9-rental-engine' ),
                    'requiredSelection'      => __( 'Required selection', 'patsch9-rental-engine' ),
                    'atLeastOneOption'       => __( 'At least one option must be selected', 'patsch9-rental-engine' ),
                    'addAccessory'           => __( '+ Add accessory', 'patsch9-rental-engine' ),
                    'removeAccessoryGroup'   => __( 'Remove accessory group', 'patsch9-rental-engine' ),
                    'emptyAccessoryGroup'    => __( 'No accessories in this group yet. Add at least one product.', 'patsch9-rental-engine' ),
                    'removeChecklistItem'    => __( 'Remove checklist item', 'patsch9-rental-engine' ),
                ],
            ] );
"""
    if 'RMWCAdminData' not in text:
        text = replace_once(text, admin_marker, admin_block, 'admin localization')

    locale_marker = "            'locale'         => str_replace( '_', '-', get_locale() ),\n"
    front_strings = locale_marker + """            'strings'        => [
                'weekdayMon'             => __( 'Mon', 'patsch9-rental-engine' ),
                'weekdayTue'             => __( 'Tue', 'patsch9-rental-engine' ),
                'weekdayWed'             => __( 'Wed', 'patsch9-rental-engine' ),
                'weekdayThu'             => __( 'Thu', 'patsch9-rental-engine' ),
                'weekdayFri'             => __( 'Fri', 'patsch9-rental-engine' ),
                'weekdaySat'             => __( 'Sat', 'patsch9-rental-engine' ),
                'weekdaySun'             => __( 'Sun', 'patsch9-rental-engine' ),
                'noPickupThisWeekday'    => __( 'No pickup or rental start on this weekday', 'patsch9-rental-engine' ),
                'noReturnThisWeekday'    => __( 'No return on this weekday', 'patsch9-rental-engine' ),
                'previousMonth'          => __( 'Previous month', 'patsch9-rental-engine' ),
                'nextMonth'              => __( 'Next month', 'patsch9-rental-engine' ),
                'partiallyBooked'        => __( 'partially booked', 'patsch9-rental-engine' ),
                'returnNotPossible'      => __( 'Returns are not possible on this weekday.', 'patsch9-rental-engine' ),
                'periodNotBookable'      => __( 'This period cannot be booked, does not meet the permitted rental duration, or there is not enough stock available.', 'patsch9-rental-engine' ),
                'weekendRate'            => __( 'Weekend rate', 'patsch9-rental-engine' ),
                'tieredRate'             => __( 'Tiered rate', 'patsch9-rental-engine' ),
                'weekdayRate'            => __( 'Weekday rate', 'patsch9-rental-engine' ),
                'standardRate'           => __( 'Standard rate', 'patsch9-rental-engine' ),
                'enterDeliveryAddress'   => __( 'Please enter the delivery address first.', 'patsch9-rental-engine' ),
                'deliveryQuoteFailed'    => __( 'Delivery costs could not be calculated.', 'patsch9-rental-engine' ),
                'calculateTransport'     => __( 'Calculate transport costs', 'patsch9-rental-engine' ),
                'select'                 => __( 'Select', 'patsch9-rental-engine' ),
                'withoutAddon'           => __( 'Without add-on', 'patsch9-rental-engine' ),
                'selected'               => __( 'Selected', 'patsch9-rental-engine' ),
            ],
"""
    if "'weekdayMon'" not in text:
        text = replace_once(text, locale_marker, front_strings, 'frontend localization')
    CORE.write_text(text, encoding='utf-8')


def patch_admin_js() -> None:
    text = ADMIN.read_text(encoding='utf-8')
    if not text.startswith('function rmwcAdminString'):
        text = """function rmwcAdminString(key,fallback){
  const strings=(window.RMWCAdminData&&RMWCAdminData.strings)||{};
  return Object.prototype.hasOwnProperty.call(strings,key)?strings[key]:fallback;
}

""" + text
    replacements = {
        'placeholder="Material"': 'placeholder="${rmwcAdminString(\'addonMaterial\',\'Material\')}"',
        'placeholder="für 20 Personen"': 'placeholder="${rmwcAdminString(\'addonFor20People\',\'for 20 people\')}"',
        '>einmalig</option>': '>${rmwcAdminString(\'once\',\'once\')}</option>',
        '>pro Mieteinheit</option>': '>${rmwcAdminString(\'perRentalUnit\',\'per rental unit\')}</option>',
        '>pro Miettag</option>': '>${rmwcAdminString(\'perRentalDay\',\'per rental day\')}</option>',
        'placeholder="bis 10 km"': 'placeholder="${rmwcAdminString(\'upTo10Km\',\'up to 10 km\')}"',
        '<label>Miet-Zubehörprodukt</label>': '<label>${rmwcAdminString(\'rentalAccessoryProduct\',\'Rental accessory product\')}</label>',
        'data-placeholder="Miet-Zubehör suchen…"': 'data-placeholder="${rmwcAdminString(\'searchRentalAccessory\',\'Search rental accessories…\')}"',
        '<p class="description">Preis, Steuer, Bestand und „Nur einzeln verkaufen“ stammen direkt aus diesem WooCommerce-Artikel.</p>': '<p class="description">${rmwcAdminString(\'productSourceNote\',\'Price, tax, stock, and the “Sold individually” setting are taken directly from this WooCommerce product.\')}</p>',
        '<label>Anzeige für Kunden</label>': '<label>${rmwcAdminString(\'customerDisplay\',\'Customer display\')}</label>',
        'placeholder="z. B. für ca. 1 kg süßes Popcorn"': 'placeholder="${rmwcAdminString(\'sweetPopcornExample\',\'e.g. for approx. 1 kg sweet popcorn\')}"',
        '<p class="description">Optional. Leer = Produktname verwenden.</p>': '<p class="description">${rmwcAdminString(\'optionalProductName\',\'Optional. Empty = use product name.\')}</p>',
        '<label>Berechnung</label>': '<label>${rmwcAdminString(\'calculation\',\'Calculation\')}</label>',
        '>1× je Buchung</option>': '>${rmwcAdminString(\'oncePerBooking\',\'1× per booking\')}</option>',
        '>je Mietgerät</option>': '>${rmwcAdminString(\'perRentalUnit\',\'per rental unit\')}</option>',
        'aria-label="Miet-Zubehör aus Gruppe entfernen"': 'aria-label="${rmwcAdminString(\'removeAccessory\',\'Remove rental accessory from group\')}"',
        '<label>Gruppenname</label>': '<label>${rmwcAdminString(\'groupName\',\'Group name\')}</label>',
        '<label>Auswahlart</label>': '<label>${rmwcAdminString(\'selectionType\',\'Selection type\')}</label>',
        '>Einfachauswahl – maximal eine Option</option>': '>${rmwcAdminString(\'singleSelection\',\'Single selection – at most one option\')}</option>',
        '>Mehrfachauswahl – mehrere Optionen möglich</option>': '>${rmwcAdminString(\'multipleSelection\',\'Multiple selection – multiple options allowed\')}</option>',
        '<span class="rmwc-v2-label">Pflichtauswahl</span>': '<span class="rmwc-v2-label">${rmwcAdminString(\'requiredSelection\',\'Required selection\')}</span>',
        '<span>Mindestens eine Option muss gewählt werden</span>': '<span>${rmwcAdminString(\'atLeastOneOption\',\'At least one option must be selected\')}</span>',
        '>+ Zubehör hinzufügen</button>': '>${rmwcAdminString(\'addAccessory\',\'+ Add accessory\')}</button>',
        'aria-label="Zubehörgruppe entfernen"': 'aria-label="${rmwcAdminString(\'removeAccessoryGroup\',\'Remove accessory group\')}"',
        '>Noch kein Zubehör in dieser Gruppe. Füge mindestens einen Artikel hinzu.</p>': '>${rmwcAdminString(\'emptyAccessoryGroup\',\'No accessories in this group yet. Add at least one product.\')}</p>',
        'aria-label="Prüfpunkt entfernen"': 'aria-label="${rmwcAdminString(\'removeChecklistItem\',\'Remove checklist item\')}"',
    }
    for old,new in replacements.items():
        text=text.replace(old,new)
    ADMIN.write_text(text,encoding='utf-8')


def patch_front_js() -> None:
    text = FRONT.read_text(encoding='utf-8')
    anchor = "  if (!box.length) return;\n\n"
    if 'const t = (key,fallback)' not in text:
        text = replace_once(text, anchor, anchor + "  const t = (key,fallback) => (RMWCData.strings && Object.prototype.hasOwnProperty.call(RMWCData.strings,key)) ? RMWCData.strings[key] : fallback;\n\n", 'frontend t helper')
    text = text.replace("const names=['Mo','Di','Mi','Do','Fr','Sa','So'];", "const names=[t('weekdayMon','Mon'),t('weekdayTue','Tue'),t('weekdayWed','Wed'),t('weekdayThu','Thu'),t('weekdayFri','Fri'),t('weekdaySat','Sat'),t('weekdaySun','Sun')];")
    text = text.replace("first.toLocaleDateString('de-DE'", "first.toLocaleDateString(RMWCData.locale||'en-US'")
    text = text.replace("title='Keine Abholung / kein Mietbeginn an diesem Wochentag';", "title=t('noPickupThisWeekday','No pickup or rental start on this weekday');")
    text = text.replace("title='Keine Rückgabe an diesem Wochentag';", "title=t('noReturnThisWeekday','No return on this weekday');")
    text = text.replace('aria-label="Vorheriger Monat"', 'aria-label="${t(\'previousMonth\',\'Previous month\')}"')
    text = text.replace('> teilweise/zeitweise belegt</span>', '>${t(\'partiallyBooked\',\'partially booked\')}</span>')
    text = text.replace('aria-label="Nächster Monat"', 'aria-label="${t(\'nextMonth\',\'Next month\')}"')
    text = text.replace("alert('An diesem Wochentag ist keine Rückgabe möglich.');", "alert(t('returnNotPossible','Returns are not possible on this weekday.'));")
    text = text.replace("alert('Dieser Zeitraum ist nicht buchbar, hat nicht die erlaubte Mietdauer oder es ist nicht genügend Bestand frei.');", "alert(t('periodNotBookable','This period cannot be booked, does not meet the permitted rental duration, or there is not enough stock available.'));")
    text = text.replace("label:'Wochenendtarif'", "label:t('weekendRate','Weekend rate')")
    text = text.replace("label:'Preisstaffel'", "label:t('tieredRate','Tiered rate')")
    text = text.replace("usedOverride?'Wochentagstarif':'Standardtarif'", "usedOverride?t('weekdayRate','Weekday rate'):t('standardRate','Standard rate')")
    text = text.replace("label:'Standardtarif'", "label:t('standardRate','Standard rate')")
    text = text.replace("RMWCData.locale||'de-DE'", "RMWCData.locale||'en-US'")
    text = text.replace("alert('Bitte zuerst die Lieferadresse eingeben.');", "alert(t('enterDeliveryAddress','Please enter the delivery address first.'));")
    text = text.replace("'Lieferkosten konnten nicht berechnet werden.'", "t('deliveryQuoteFailed','Delivery costs could not be calculated.')")
    text = text.replace("'Transportkosten berechnen'", "t('calculateTransport','Calculate transport costs')")
    text = text.replace("'Auswählen'", "t('select','Select')")
    text = text.replace("'Ohne Zusatz'", "t('withoutAddon','Without add-on')")
    text = text.replace("'Ausgewählt'", "t('selected','Selected')")
    FRONT.write_text(text,encoding='utf-8')


def main() -> None:
    patch_php()
    patch_admin_js()
    patch_front_js()
    print('Applied JavaScript i18n refactor.')

if __name__ == '__main__':
    main()
