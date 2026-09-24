=== lxix Email Firewall ===
Contributors: lxix
Tags: email, blocklist, spam, contact form, woocommerce
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: Apache-2.0
License URI: https://www.apache.org/licenses/LICENSE-2.0

Stops emails to blocked addresses and domains: checks every wp_mail() call, form notifications and WooCommerce emails.

== Description ==

lxix Email Firewall keeps your site from sending emails to the addresses and domains you block. Use it to stop mail to fake addresses left in your forms by bots, to spam traps, or to recipients who must not get any more emails from the site.

= How it works =

* Every email sent with `wp_mail()` is checked. Blocked addresses are removed from the To, Cc and Bcc recipients, and an email without any recipient left is not sent at all. The code that sent it sees it as delivered, so forms show no errors.
* Blocking a domain blocks its subdomains too: `example.com` also blocks `mail.example.com`.
* Emails to the administration email address and to administrators can be exempted, so a blocked domain doesn't cut you off from your own site.

= Form integrations =

When a form submission contains a blocked email address, lxix Email Firewall either accepts the submission but sends no emails for it, or rejects it with an error message. Supported form plugins:

* Elementor Pro forms
* WPForms
* Contact Form 7
* Fluent Forms

Each integration can be turned off in the settings.

= WooCommerce =

WooCommerce skips its emails to blocked recipients, without adding "email sent" or "email failed" notes to the order.

= Log =

Blocked emails can be logged with the address, the time, the source (for example `wpforms` or `woocommerce`) and the IP address of the request. Entries older than the retention period (30 days by default) are deleted automatically, and turning logging off deletes the whole log.

= Privacy =

lxix Email Firewall doesn't contact any external service. While logging is on, it stores the blocked email addresses and the IP addresses of the requests that tried to email them in a database table of your site. It adds a suggested text for your privacy policy to the Privacy Policy Guide (Settings > Privacy).

== Installation ==

1. Install the plugin from Plugins > Add New Plugin, or upload the `lxix-email-firewall` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Go to Settings > lxix Email Firewall and enter the email addresses and domains to block.

== Frequently Asked Questions ==

= How do I enter the blocked addresses and domains? =

One per line, or separated by commas. A domain can also be written as `*.example.com`, `@example.com` or `https://example.com/`.

= Does it work with SMTP and email API plugins? =

Yes. Blocked recipients are removed from the email before the mailer plugin gets it. Some mailer plugins report an email as failed when every recipient was blocked; the email is still not sent.

= Do Elementor forms need Elementor Pro? =

Yes, forms are an Elementor Pro feature.

= Which form field is checked? =

Every field of the submission. If any of them holds a blocked email address, the submission is handled as blocked.

= What is removed when I delete the plugin? =

Its settings, its log table and its scheduled cleanup task, on every site of a multisite network.

== Changelog ==

= 1.1.0 =
* New: Contact Form 7 and Fluent Forms integrations.
* New: suggested privacy policy text.
* The plugin is now called lxix Email Firewall. Its folder and text domain are `lxix-email-firewall`.
* Translations are no longer bundled with the plugin: they come from translate.wordpress.org.
* The log stores times in UTC and shows them in the site's timezone. Existing entries are converted on update.
* WooCommerce emails are always checked. The separate WooCommerce setting has been removed.
* Clearing the log works on hosts that don't grant the database DROP privilege.

= 1.0.0 =
* First release.
