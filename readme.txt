=== AirLift - Ultimate WordPress Optimization ===
Contributors: AirLift, BlogVault Backup, akshatc
Tags: performance, cache, CDN, optimization, speed
Donate link: https://app.airlift.net/users/signup
Plugin URI: https://airlift.net/
Requires at least: 4.0
Tested up to: 7.1
Requires PHP: 7.0
Stable tag: 6.71
License: GPLv2 or later
License URI: [http://www.gnu.org/licenses/gpl-2.0.html](http://www.gnu.org/licenses/gpl-2.0.html)

Airlift - Ultimate WordPress Optimization Plugin

== DESCRIPTION ==
Boost your WordPress site’s performance with Airlift. This all-in-one solution includes advanced caching, CDN integration, image optimization, Used CSS, Critical CSS etc. Improve your site speed and SEO with features like page caching, minification, and Core Web Vitals optimization. Compatible with Cloudflare, AWS, and Azure. Airlift supports Redis, Memcached, and Varnish for robust object caching. Achieve faster load time, better search ranking, and a superior user experience with Airlift.

== Benefits of Using Airlift ==

* **Enhanced Performance**
	Achieve lightning-fast load time with advanced caching and CDN integration.
* **SEO Optimization**
	Improve search engine ranking with optimized Core Web Vitals and page speed enhancements.
* **Comprehensive Optimization**
	Features image optimization, file compression, Dynamic Used CSS, Crtical CSS, etc.
* **Robust Compatibility**
	Works seamlessly with Cloudflare, AWS, Azure, Redis, Memcached, and Varnish.
* **User-Friendly Interface**
	Easy-to-use dashboard for managing all performance settings efficiently.

== Why Choose Airlift Services? ==

* **All-in-One Solution**
	Combines multiple optimization tools into a single powerful plugin.
* **Proven Results**
	Increases site speed and performance, directly impacting user experience and SEO.
* **Scalable and Flexible**
	Suitable for all types of websites, from small blogs to large e-commerce sites.
* **Expert Support**
	A dedicated support team to help you maximize the plugin’s capabilities.
* **Continuous Improvement**
	Regular updates and new features to keep your site ahead of the curve.

For more details, visit [Airlift](https://airlift.net/).

== FREQUENTLY ASKED QUESTIONS ==
=How to find my key?=
Follow the following steps:
* Install and activate the Airlift plugin on the site
* Click on Airlift in the side menubar
* Copy the key from the Airlift WP-Admin page
=Do I need to pay for support and help?=
Never! We will be with you for any queries at any time. **[Click here](https://airlift.net/contact/)** to get in touch with us!

=Where can I find the Airlift Terms of Use and Privacy Policy?=
These are available on our website: [Terms of Service](https://airlift.net/tos/) and [Privacy Policy](https://airlift.net/privacy/)


== CHANGELOG ==
= 6.71 =
* stop replacing font-face rules for other pages.
* balanced mode introduced for fonts.

= 6.68 =
* Fixed Airlift runtime insertion when an HTML response is missing its closing body tag.

= 6.67 =
* Fixed translated pages being cached under the default-language URL when multilingual plugins rewrite the request URI.

= 6.66 =
* Improved CSS and JavaScript optimization reliability after plugin or theme updates.

= 6.64 =
* New Purge Cache Architecture.
* Template Selection logic for singular and non-singular post improvement
* Improved img tag handling in case optimized image is not present.

= 6.63 = 
* Improved HTML comment removal and moved meta tags to the <head> section.

= 6.61 =
* Improved font and background image optimization for inline style tags present in the buffered HTML.

= 6.59 =
* Preserved original script tags when excluded from JavaScript delay.

= 6.57 =
* Improved image preloading with device-specific media conditions.
* Avoided generated sizes for unoptimized images while preserving existing sizes.
* Improved image optimization performance, compatibility, and reliability.

= 6.56 =
* Improved default image optimization handling for responsive and lazyloaded images.

= 6.54 =
* broken src handling in img tag.  

= 6.53 =
* Added image handling accross the site through rules from configuration.
* Added grouping logic fields handling.

= 6.52 =
* Added cache compatibility handling for language and currency plugins including Weglot, Polylang, GTranslate, WCML, and Aelia Currency Switcher.

= 6.51 =
* Fixed lazyloaded image placeholders to preserve image aspect ratios while keeping site-safe img dimension handling intact.

= 6.49 =
* Added CDN url preconnect.
* Added Elementor hooks compatibility.

= 6.48 =
* Added url specific purge cache option in admin bar.

= 6.47 =
* Fix: Prevent stale WordPress core update cleanup rules from deleting files added by newer WordPress core packages.

= 6.46 =
* Avif image handling added.

= 6.45 =
* Added width and height handling for non viewport lazyloaded images to prevent cls.

= 6.44 =
* Tweak: Improved the WP core updates flow.
* Tweak: Improved the plugin auto-install flow.

= 6.43 =
* Added width and height handling for viewport images to prevent cls. 

= 6.42 =
* Added inline CSS image lazyloading handling for critical css with delay styles.

= 6.41 =
* Viewport Img tag handling added.

= 6.39 =
* Tweak: Code Restructuring

= 6.38 =
* Storing resized images only in webp.

= 6.37 =
* font-face handling improved for v2 critical css

= 6.35 =
* Improved reliability of JS optimization for sites with large page sizes

= 6.34 =
* Added support for selectively ignoring request URIs from caching while still optimizing them

= 6.32 =
* Preserve link tags present inside script tags.

= 6.29 =
* Non matching script attributes handling fix.

= 6.28 =
* Critical CSS replace with first matching head tag fix.

= 6.27 =
* Improved document's ready state handling for delay javascripts

= 6.26 =
* Iframes handling for nested elements present

= 6.25 =
* Mandatory cookies handling code added

= 6.24 =
* MultiLingual Plugins handling added
* Support to cache dynamic cookies

= 6.23 =
* Javascript matching config bug fix.

= 6.22 =
* Critical CSS replacement code fixed.
* In Critical CSS link tag replacement bug fix.

= 6.21 =
* Added Defer-Scripts handling

= 6.19 =
* Added buffer-based compression for sites where server-side compression is not enabled.

= 6.18 =
* Improved background image lazyloading reliability by fixing quote conflicts in SVG placeholders

= 6.17 =
* Improved JavaScript enqueuing for better performance and WordPress standards compliance
* Added opcache invalidation for rename file
* Improved stability by ensuring optimization only runs when the plugin is fully loaded
* Enhanced image handling by ensuring only valid images are processed

= 6.15 =
* Meta tags handling: Only reorder meta tags inside the head; body meta tags are not affected

= 6.14 =
* **Improved Caching and Optimization Logic**: Enhanced handling of user agents, cookies, and query parameters for better performance
* **Fixed White Screen Issues**: Resolved white screen problems by proper handling of gzip encoding
* **Pantheon Purge Cache**: Added support for Pantheon cache purging functionality

= 6.13 =
* Fix: Introduced critical css replace rules

= 6.12 =
* Fix: Improved head tag processing

= 6.02 =
* Tweak: Improved Authentication

= 5.93 =
* Tweak: Improvements in bulk upgrade

= 5.92 =
* Tweak: Improvements in fetching File Stats

= 5.91 =
* Tweak: Code Restructuring

= 5.87 =
* Fix: Resolved compatibility issues with WordPress versions below 6.2.

= 5.68 =
* Tweak: DB Version Update
* New: Handling Dynamic Javascripts present in the Buffer

= 6.1 =
* First Release
