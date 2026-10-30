# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.2.8] - 2026-10-30

### Changed
- Released under the MIT License: this module is free and open-source software.

## [1.2.7] - 2026-10-06

### Fixed
- Real product, category and CMS URLs no longer return 404 to XHR, fetch and other non-navigation requests (for example when a page is loaded by script right after the page cache was cleaned). The guard on Magento's URL rewrite router now only skips rewrites that redirect (301 or 302) for those requests, and those 404s are no longer written to the 404 log.
- The trailing slash and lowercase redirects no longer apply to Magento system routes (checkout, customer, sales, wishlist, multishipping, paypal, payment, braintree, catalogsearch, rest, soap, graphql, admin, static and media), so /checkout/cart/ is served directly instead of being redirected to /checkout/cart. Non-GET requests were already excluded.
