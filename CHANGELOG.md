# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.2.5] - 2026-10-03

### Fixed
- Maintenance rules now return their message exactly as entered. Previously a request path that matched only part of the pattern (for example /checkout/cart for the pattern /checkout) produced a 503 body mixed with the rest of the path, and text such as $1 in the message was replaced.
- Storefront pages whose path only starts with the admin name, such as /administration-services, are redirected again. Only the configured admin path and /admin as a full path segment are excluded.
- When a category is deleted, the automatic redirect for each store view now points to the parent category URL of that same store view instead of the URL of whichever store was found first.
