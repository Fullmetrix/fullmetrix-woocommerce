=== Fullmetrix Reporting, Analytics & Marketing for WooCommerce ===
Contributors: fullmetrix
Tags: woocommerce, woocommerce analytics, woocommerce reports, ecommerce analytics, customer segmentation
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.10.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn WooCommerce data into actionable sales, product, customer and marketing insights with reports, segments, cohorts and LTV.

== Description ==

**Fullmetrix is a WooCommerce analytics and reporting platform built to help ecommerce teams understand performance, find growth opportunities and make better marketing decisions.**

Connect your store and explore WooCommerce sales, orders, customers, products, subscriptions and marketing performance from one clear dashboard.

Track the metrics that matter: net revenue, average order value, customer lifetime value, repeat purchases, cohort retention, product performance, refunds, subscriptions and abandoned carts.

Fullmetrix is a hosted analytics service. A Fullmetrix account is required to connect your WooCommerce store.

[![Fullmetrix dashboard](https://ps.w.org/fullmetrix/assets/marketing-revenue.png)](https://fullmetrix.com)

= WooCommerce analytics that lead to action =

Go beyond standard WooCommerce reports and generic web analytics. Fullmetrix transforms your store data into ecommerce insights you can use to improve acquisition, retention, merchandising and profitability.

* Monitor gross and net revenue, orders, refunds and average order value
* Analyze customers, products, variations, categories and countries
* Track customer lifetime value, repeat purchase rate and retention
* Compare cohorts to understand how customer behavior changes over time
* Explore subscription revenue, active subscriptions and recurring customers
* Follow abandoned carts and customer activity in real time
* Create reusable customer, order and product segments
* Consolidate multiple ecommerce stores in one reporting workspace

If you are looking for an alternative to Metorik, Triple Whale, Putler or other ecommerce analytics platforms, Fullmetrix brings reporting, customer intelligence and marketing activation together in one workspace.

= Understand your customers =

[![Cohort retention analysis](https://ps.w.org/fullmetrix/assets/marketing-cohorts.png)](https://fullmetrix.com)

Identify your most valuable customers, first-time buyers, repeat purchasers, inactive customers and customers at risk of churn.

Use ecommerce segmentation to create audiences based on purchasing behavior, order history, products, location, customer value and other WooCommerce data.

[![Customer analytics](https://ps.w.org/fullmetrix/assets/marketing-customers.png)](https://fullmetrix.com)

Build segments for VIP and high-LTV customers, first-time and repeat buyers, abandoned carts, inactive customers, subscription customers and product-based audiences.

[![Audience segmentation](https://ps.w.org/fullmetrix/assets/marketing-segments.png)](https://fullmetrix.com)

= Measure retention with LTV and cohort analysis =

[![Orders dashboard](https://ps.w.org/fullmetrix/assets/marketing-orders.png)](https://fullmetrix.com)

Revenue alone does not tell you whether your store is building lasting growth. Fullmetrix helps you measure customer lifetime value, repeat purchases and cohort retention.

Compare customer groups by acquisition period and see when they return, how often they order and how much revenue they generate over time.

[![Top sellers](https://ps.w.org/fullmetrix/assets/marketing-top-sellers.png)](https://fullmetrix.com)

= Analyze products and merchandising =

Discover which products, variations and categories generate the most sales. Explore product performance, customer purchase patterns, frequently bought-together products, sales by country and historical pricing data.

= Connect analytics with marketing =

Turn ecommerce insights into marketing audiences. Create customer segments and use them across acquisition and retention workflows, including audience activation for Meta Ads, Google Ads and TikTok Ads.

Connect complementary data sources such as GA4, Google Search Console and advertising platforms to understand store and campaign performance from the same workspace.

= Built for WooCommerce performance =

Fullmetrix synchronizes store data in the background and performs reporting outside your WordPress database. The connector supports WooCommerce HPOS and WooCommerce Subscriptions.

= Get started in 2 minutes =

1. Create your Fullmetrix account on [fullmetrix.com](https://fullmetrix.com)
2. Install this plugin and paste your connection code
3. Open your Fullmetrix dashboard and start growing


== Installation ==

1. In your WordPress dashboard, go to Plugins > Add New
2. Search for "Fullmetrix" and click Install
3. Activate the plugin
4. Go to WooCommerce > Fullmetrix
5. Enter your connection code from [fullmetrix.com](https://fullmetrix.com)
6. Your store data starts syncing automatically

== Frequently Asked Questions ==

= Is Fullmetrix free? =

Yes, you can get started for free at [fullmetrix.com](https://fullmetrix.com). No credit card required to create your account and connect your store.

= How long does setup take? =

Less than 2 minutes. Install the plugin, paste your connection code, and your store data starts flowing into your dashboard automatically.

= Will Fullmetrix slow down my store? =

No. All syncing happens silently in the background. Your customers will not notice anything and your site speed stays exactly the same.

= What kind of insights will I get? =

Revenue trends, conversion data, top-selling products, customer lifetime value, cohort retention, abandoned cart recovery, audience segments, and much more — everything you need to make smarter marketing decisions and grow your store.

= Can I use Fullmetrix to run ads? =

Yes. Build customer segments inside Fullmetrix and sync them directly to Meta Ads, Google Ads and TikTok Ads to lower your customer acquisition cost and reach the right audience.

= Does it work with WooCommerce Subscriptions? =

Yes. Subscription revenue and recurring customers are tracked automatically alongside your one-time orders.

= What external service does the plugin connect to? =

The plugin connects your WooCommerce store to the Fullmetrix service at [fullmetrix.com](https://fullmetrix.com). Store data required for analytics and reporting is transmitted after a store administrator explicitly configures the connection. Review the [Fullmetrix privacy policy](https://fullmetrix.com/privacy) and [terms of service](https://fullmetrix.com/terms) before connecting your store.

== Screenshots ==

1. Dashboard overview with revenue, orders, top products and top categories
2. Detailed sales, orders, products and customers analytics
3. Revenue analytics with gross and net revenue breakdown
4. Revenue by country and customer type
5. Orders list with status, customer and value
6. Customers list with lifetime value and order history
7. Cohort analysis with retention curves
8. Custom segments builder for targeted audiences
9. Top sellers across products, variations and categories

== Changelog ==

= 1.10.0 =
* Improved: order, product, customer and coupon updates are sent to Fullmetrix in the background through Action Scheduler instead of during the shopper's request
* Improved: every call to Fullmetrix uses a short timeout and a circuit breaker that pauses calls for 5 minutes after repeated failures, with separate breakers for storefront calls, cart recovery links and background jobs
* Improved: background updates that cannot reach Fullmetrix are retried later instead of being dropped
* Improved: the plugin settings keep their last valid copy during an outage, a failed refresh is retried after 10 minutes and times out after 2 seconds, and the settings are cleared when the store is disconnected
* Improved: server cart events are sent after the page has been delivered to the shopper
* Improved: checkout consent is signed and sent in the background through Action Scheduler, and retried until Fullmetrix confirms it, so a consent given during an outage is no longer lost
* Fixed: a free gift coupon whose gift is out of stock no longer adds a stock error that blocked the checkout
* Fixed: the Blocks checkout no longer saves the order a second time and no longer sends consent on each draft update
* Fixed: displayed product prices sent to Fullmetrix are always computed for the store address, whoever triggers the update
* Fixed: order storage detection no longer counts every order when WooCommerce reports the storage in use
* Added: Fullmetrix can switch off webhooks, server cart events, checkout consent and free gift additions remotely

= 1.9.1 =
* Fixed: stores that switched back from HPOS to WordPress posts storage export their new orders again, the exporter now follows the order storage selected in WooCommerce
* Fixed: variable, grouped and external products are exported with their real product type instead of simple
* Fixed: store counts exclude checkout drafts and auto-draft products, matching the exported data

= 1.9.0 =
* Fixed: a full import now resumes exactly where it stopped when the store server truncates an oversized response, instead of restarting and hitting the same cut
* Fixed: orders, refunds and subscriptions are exported in ascending id order, aligned with every other entity
* Fixed: a lost database connection no longer loops an export indefinitely

= 1.6.3 =
* Added: displayed customer prices, regular prices and sale prices are included for products and variations
* Added: order lines include tax-inclusive pre-coupon prices for reliable historical price reconstruction

= 1.6.2 =
* Added: customer WordPress role and roles are now included in customer sync payloads, enabling role-based segmentation
* Added: the tracking script now respects the tracker on/off toggle configured in the Fullmetrix dashboard

= 1.6.1 =
* Renamed: plugin title updated to comply with the WordPress.org plugin directory naming guidelines

= 1.6.0 =
* Fixed: stream truncation on long product catalogs caused by PHP ob_gzhandler; gzip is now delegated to the web server (Apache mod_deflate / nginx) like the PrestaShop connector already does

= 1.5.1 =
* Renamed: plugin title updated to reflect the full feature set

= 1.5.0 =
* Added: brand export from native product_brand taxonomy (WooCommerce 9.4+), Perfect Brands (pwb-brand) and YITH brands
* Added: fallback to _brand and pa_marque post meta, plus brand/marque product attributes
* Added: variations inherit the parent product brand

= 1.4.3 =
* Added: order line item defense — woocommerce_checkout_create_order_line_item hook forces gift line to qty=1 + total=0 even if the cart was tampered with before checkout

= 1.4.2 =
* Improved: Gift product line now displays at 0 EUR directly in the cart instead of full price plus a separate discount line

= 1.4.1 =
* Fixed: Gift coupons no longer set product_ids, so WooCommerce validation passes on an empty cart and the gift product is auto-added on apply
* Improved: only the flagged gift line receives the 100% discount, regardless of other items in the cart

= 1.4.0 =
* Fixed: Gift coupon now auto-adds the offered product to the cart on apply, instead of failing validation when the cart is empty
* Added: Anti-cheat for gift coupons — gift line is locked to quantity 1, only the flagged line receives the 100% discount, manually-added copies of the same product keep their full price
* Improved: Variation gift coupons store the variation ID directly so the correct variation is added to the cart

= 1.3.0 =
* Added: Native marketing consent checkbox on classic and Blocks checkout
* Added: Consent persisted as order meta `_fullmetrix_marketing_opt_in_consent` and forwarded to Fullmetrix
* Added: WC Blocks integration via Store API extension namespace `fullmetrix-checkout`
* Fixed: Signed plugin config endpoint used seconds instead of milliseconds for HMAC timestamp

= 1.2.1 =
* Added: Auto-add gift product to cart when a Fullmetrix gift coupon is applied
* Added: Coupon meta `_fullmetrix_gift_product` for marking gift coupons
* Improved: Only auto-added gift items are removed when the coupon is removed (manually-added items preserved)

= 1.1.0 =
* Added: Multi-language support (DE, ES, FR, IT, NL)
* Added: Permalink support for Fullmetrix dashboard URLs
* Added: External services documentation in readme
* Improved: SEO optimizations and admin UI polish
* Improved: Tracking and webhook reliability
* Improved: Plugin slug and text domain alignment
* Fixed: Removed unnecessary page auto-reload and verbose logs
* Fixed: PHP syntax error in API class
* Fixed: Summary cards padding

= 1.0.3 =
* Security: HMAC-SHA256 signed cart recovery URLs
* Improved: Cart recovery link validation

= 1.0.0 =
* Initial public release on WordPress.org
* Automatic sync of orders, customers, products, categories, coupons, and refunds
* High-performance streaming export with keyset pagination
* Incremental sync support for optimal performance
* Abandoned cart tracking
* HPOS (High-Performance Order Storage) fully compatible
* HMAC-SHA256 authenticated API communication
* WooCommerce Subscriptions support

== External services ==

This plugin connects your WooCommerce store to the Fullmetrix platform ([fullmetrix.com](https://fullmetrix.com)). It relies on the following external service:

= Fullmetrix API =

**What it is:** Fullmetrix is an e-commerce analytics platform that aggregates and analyzes your store data.

**What data is sent and when:**

* **On connection (one-time):** Your store URL, plugin version, WooCommerce version, currency, timezone, and locale are sent to `https://fullmetrix.com/api/plugin/register` when you enter your connection code and click "Connect".
* **On page load (frontend):** The plugin loads a JavaScript tracker (`t.js`) from `https://fullmetrix.com/t.js` on all public pages. This script collects anonymous visitor browsing data (page views, cart activity) and sends it to `https://fullmetrix.com/api/webhooks/events`.
* **On configuration check:** The plugin periodically fetches its remote configuration from `https://fullmetrix.com/api/plugin/config` (cached for 5 minutes).
* **On data sync (triggered from Fullmetrix dashboard):** Orders, customers, products, categories, coupons, refunds, and subscription data are exported to Fullmetrix when a sync is initiated.
* **On real-time changes:** When orders, customers, products, or coupons are created or updated in WooCommerce, a webhook notification is sent to `https://fullmetrix.com/api/webhooks/ecommerce`.

All communications use HTTPS and are authenticated with HMAC-SHA256 signatures. No data is sent to any third party other than the Fullmetrix service.

* [Fullmetrix Terms of Service](https://fullmetrix.com/terms)
* [Fullmetrix Privacy Policy](https://fullmetrix.com/privacy)

== Privacy Policy ==

This plugin connects your WooCommerce store to the Fullmetrix service ([fullmetrix.com](https://fullmetrix.com)). See the "External services" section above for full details on data transmission. See the [Fullmetrix Privacy Policy](https://fullmetrix.com/privacy) for more details.

== Upgrade Notice ==

= 1.10.0 =
Store updates are now sent in the background and every call to Fullmetrix is time-limited, so the plugin cannot slow down your storefront. A full re-sync runs automatically after the update.

= 1.9.1 =
Stores that moved back from HPOS to WordPress posts storage export their new orders again, and product types are exported correctly. A full re-sync runs automatically after the update.

= 1.3.0 =
Adds a native marketing consent checkbox on the WooCommerce checkout (classic and Blocks).

= 1.1.0 =
Adds multi-language support, permalink support, and several reliability improvements.

= 1.0.3 =
Security improvement: cart recovery URLs are now signed with HMAC-SHA256.

= 1.0.0 =
Initial public release on WordPress.org.
