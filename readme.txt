=== Patsch9 Rental Engine for WooCommerce ===
Contributors: patsch9
Tags: woocommerce, rental, booking, deposits, inventory
Requires at least: 6.9.5
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 2026.10.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Rental and equipment booking workflows for WooCommerce with availability, pricing, deposits, documents, inventory, and handover/return management.

== Description ==

Patsch9 Rental Engine adds rental and equipment booking workflows to WooCommerce. Rental products can use date-based availability, configurable pricing, refundable deposits, rental terms, documents, accessories, physical inventory assignments, and handover/return workflows.

The plugin supports the classic WooCommerce checkout as well as Cart and Checkout Blocks and declares High-Performance Order Storage (HPOS) compatibility.

**Trademark notice:** WooCommerce® is a trademark of Automattic Inc. This is an independent third-party extension and is not produced, sponsored, or endorsed by Automattic.

== Features ==

* Rental periods with start/end dates and configurable times.
* Server-side availability and capacity validation.
* Weekday and weekend pricing, blocked weekdays, and advance booking limits.
* Refundable deposits with multiple deposit handling options.
* Versioned global and product-specific rental terms.
* Rental contracts, deposit receipts, and handover/return protocols as PDF documents.
* Accessories, consumables, and rental-bound add-on products.
* Physical inventory assignment and handover/return workflow.
* Customer pickup and optional delivery/collection workflows.
* Classic Checkout and WooCommerce Cart/Checkout Blocks.
* HPOS compatibility.

== Requirements ==

* WordPress 6.9.5 or newer.
* PHP 8.2 or newer.
* WooCommerce 10.9.4 or newer.

== Installation ==

1. Install and activate WooCommerce.
2. Upload and activate this plugin.
3. Edit a WooCommerce product.
4. Enable and configure the rental option in the product data section.

== Configuration ==

Most rental settings are configured directly on the WooCommerce product. These include rental duration, times, prices, deposits, accessories, delivery options, and handover rules. Global rental terms, inventory, document, and workflow settings are available in the plugin's WooCommerce administration screens.

== External Services ==

= Google Routes API =

The optional automatic delivery-distance calculation uses the Google Routes API at `https://routes.googleapis.com/directions/v2:computeRoutes`. This feature is disabled by default.

When enabled, the configured origin address and the delivery address entered by the customer are sent to Google together with the technical connection data required for the request. Route responses are not persistently cached by this plugin.

Google Maps Platform Terms: https://cloud.google.com/maps-platform/terms
Google Privacy Policy: https://policies.google.com/privacy

The site operator is responsible for providing the required privacy information and for complying with the applicable Google terms.

== Privacy ==

Core rental functionality runs locally in WordPress and WooCommerce. Delivery addresses are sent to Google only when the optional automatic route calculation is explicitly enabled.

The plugin stores rental booking data required for availability management and may create immutable business-document snapshots containing order, rental, deposit, and accepted-terms data.

== Compatibility ==

* WooCommerce HPOS compatibility is declared.
* Classic Checkout and Cart/Checkout Blocks are supported.
* Historical internal storage identifiers are retained for backward compatibility.

== Frequently Asked Questions ==

= Are rental products normal WooCommerce shipping products? =

No. Handover, customer pickup, and optional delivery or collection by the rental operator are managed by the rental workflow.

= Can rental-bound accessories be purchased separately? =

No. Accessories configured as rental-bound items are offered only in connection with a compatible rental product.

= Is a refundable deposit treated as normal rental revenue? =

The plugin handles refundable deposits separately from normal rental pricing. The legal and tax treatment remains the responsibility of the site operator.

== Changelog ==

= 2026.10.0 =
* First stable public release using the project-wide `YYYY.M.PATCH` versioning scheme.
* Consolidates the functional and security changes from all previous internal prerelease versions through 2.0.29.
* Revalidated Checkout Blocks integration, HPOS paths, protected document downloads, pricing and availability checks, and the optional routes integration.

== Upgrade Notice ==

= 2026.10.0 =
First stable release of the new public version line. Back up existing prerelease installations and perform a functional rental checkout test after upgrading.
