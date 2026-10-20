=== Unstoppable Site Connector ===
Contributors: paramedic192
Donate link: https://unstoppablesolutions.app/donate
Tags: manage multiple sites, updates, maintenance, remote management, agency
Requires at least: 5.8
Tested up to: 7.1
Stable tag: 0.9.0
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects this site to your web professional's Unstoppable Site Manager dashboard for updates, health checks and monthly care reports.

== Description ==

Unstoppable Site Connector links this site to an **Unstoppable Site Manager** dashboard, which your web designer or agency runs on their own WordPress site (not a service run by Unstoppable Solutions). **Connecting delegates administration of this site to whoever runs that dashboard.** Once connected, they can:

* see this site's health: pending updates, WordPress and PHP versions, SSL certificate expiry;
* update WordPress, plugins and themes;
* install, activate, deactivate and delete plugins;
* open this site's admin area already signed in (one-click sign-in, can be turned off);
* build a monthly care report from this site's activity log;
* put this site in maintenance mode or show a coming soon page: visitors see a short placeholder page while signed-in editors see the site as usual (can be switched off);
* show their name in this site's admin footer instead of "Thank you for creating with WordPress" (admin screens only, never the public site; can be switched off).

https://www.youtube.com/watch?v=8KX296h_x2U

**How the connection is protected**

* No passwords are stored or sent. The site and the dashboard are paired once with a key that works one time and expires after 15 minutes.
* Every request from the dashboard is signed and checked; a request can't be replayed or used after 5 minutes.
* One-click sign-in links work once and expire after 60 seconds. You can turn it off, or choose which administrator account it uses, under Tools > Unstoppable Site Connector. Because it skips the login form, two-factor checks on the login form don't apply to it; turn it off if every sign-in must use two-factor.
* Every sign-in from the dashboard is recorded in the activity log.
* Disconnect at any time under Tools > Unstoppable Site Connector.

**This site has the last word**

The security settings under Tools > Unstoppable Site Connector > Security belong to this site, not to the dashboard. The dashboard can make them stricter, but only an administrator of this site can loosen them.

For the strongest protection, allow only your dashboard's address, and switch off one-click sign-in and installing plugins from a ZIP file or web address unless you use them: each of those gives whoever controls the dashboard full control of this site.

* **Allowed dashboard addresses.** Accept dashboard requests only from your dashboard server's IP address. A request with a valid signature from anywhere else is refused, so a stolen dashboard key is useless elsewhere. The dashboard can set this for you in one click.
* **Permissions.** Choose what the dashboard may do here: run updates, activate and deactivate plugins, install plugins from WordPress.org, install plugins from a ZIP file or web address, delete plugins, and one-click sign-in.
* **Security alert emails.** Get an email when the dashboard installs or deletes a plugin, changes these settings or connects, when a correctly signed request is blocked, and (optionally) on every one-click sign-in.

For extra hardening, set the allowed addresses in wp-config.php so they can't be changed from wp-admin at all: `define( 'USCN_ALLOWED_IPS', '203.0.113.10' );`. If this site is behind a proxy such as Cloudflare and your host doesn't pass on the real address, tell the plugin which header holds it: `define( 'USCN_CLIENT_IP_HEADER', 'HTTP_CF_CONNECTING_IP' );` (only when the site can only be reached through that proxy).

Previously released as FM Connector. Installing Unstoppable Site Connector on a site that has FM Connector takes over its connection (no re-pairing); you can then deactivate and delete FM Connector.

**Bulk connect.** A dashboard owner can download a copy of this plugin made for their dashboard (Add Site > Connect many sites at once) and install it on many sites with a bulk tool. Such a copy connects its site to that dashboard by itself, using a time-limited pass, and the site's settings screen says so. A normal copy from WordPress.org never connects anywhere on its own.

== Installation ==

1. Install and activate Unstoppable Site Connector.
2. Go to Tools > Unstoppable Site Connector and click Generate connection key.
3. Paste the key into Add Site in the Unstoppable Site Manager dashboard. The key works once and expires after 15 minutes.

== Frequently Asked Questions ==

= Do I need this plugin? =

Only if the person who maintains your site uses Unstoppable Site Manager and has asked you to install it.

= What if my web professional's dashboard is hacked? =

Lock this site to the dashboard's address (Tools > Unstoppable Site Connector > Security). Then a stolen key can't be used from anywhere else, and you get an email if someone tries. You can also switch off anything you don't want done remotely, and click Disconnect at any time to cut the connection immediately.

= How do I report a security problem? =

Please email support@unstoppablesolutions.app privately with the details instead of posting publicly. You'll get a reply within two business days.

= Updates fail with a message about FTP credentials =

Your host requires FTP details to change files, so remote updates can't run. Ask your host to allow direct file writes.

== External Services ==

**The dashboard you connect to.** After you paste the connection key into a dashboard, this plugin sends a report to that dashboard's address hourly and shortly after any change. The report contains: the site's name and address; WordPress, PHP and server versions; the installed plugins and themes with versions, active state and available updates; SSL certificate expiry; and the activity log (updates, plugin and theme changes, users created or deleted, administrator sign-ins, with usernames). It also answers the dashboard's signed requests with the same information. Data goes only to the dashboard you paired with, which is run by your web professional, not by Unstoppable Solutions. Disconnect under Tools > Unstoppable Site Connector to stop it.

**WordPress.org.** When the dashboard asks for updates or installs a plugin from the directory, WordPress downloads it from WordPress.org as it normally would. [Privacy Policy](https://wordpress.org/about/privacy/).

**This site itself (loopback).** To run the dashboard's update checks and updates inside this site's admin area, the plugin sends a one-time request from this site to its own `wp-admin/admin-ajax.php`. Nothing leaves the server.

**Security alert emails.** If alerts are on (Tools > Unstoppable Site Connector > Security), this site sends them with WordPress's own mail function to the address set there, or the site's admin email. They include the dashboard's name, the dashboard username and the IP address of the request.

**Plugin ZIPs from your dashboard.** When the dashboard installs a plugin that is not from WordPress.org, this site downloads the ZIP from the connected dashboard's own site, and from nowhere else.

== Changelog ==

= 0.9.0 =
* Maintenance mode and a Coming soon page from the dashboard. Maintenance shows visitors a short "back soon" page (503 with Retry-After, so search engines know it's temporary); Coming soon is for a site that hasn't launched yet (noindex, so the placeholder stays out of search). Signed-in editors see the site as usual. wp-admin, the login screen and the REST API keep working. The dashboard can set it to switch off by itself. The admin bar shows when it is on, Tools > Unstoppable Site Connector has a "Turn off now" button, and switching off "Turn maintenance mode on and off" under Security turns it off and keeps it off. Disconnecting turns it off too.

= 0.8.0 =
* Dashboard moves: your web professional can move this site to a new dashboard address. The new dashboard takes over the connection with a one-time code that only works for that address, the old one can no longer manage the site, and you get an alert email.

= 0.7.0 =
* Admin footer branding: the dashboard can show its name and a link at the bottom of this site's admin screens, in place of "Thank you for creating with WordPress". Plain text only, never on the public site. Switch off "Set the admin footer branding" under Tools > Unstoppable Site Connector > Security to remove it.

= 0.6.0 =
* Bulk connect: a copy of this plugin made by your dashboard connects its site to that dashboard by itself, so many sites can be connected at once with a bulk install tool.

= 0.5.5 =
* WordPress.org review fixes: plugin files are only installed from WordPress.org or the connected dashboard; FM Connector is no longer switched off automatically; connecting now asks you to confirm what it allows; the WordPress version is read without loading a core file.

= 0.5.4 =
* Reports how far its activity log goes, so the dashboard can skip a request when nothing is new (faster refresh).

= 0.5.3 =
* "Tested up to" now lives only in readme.txt, as WordPress.org requires.

= 0.5.2 =
* No more security alert email when the dashboard updates the connector itself.

= 0.5.1 =
* FM Connector is now only switched off after its connection moves across, never deleted automatically; the settings screen says when it can be deleted.
* WordPress.org readiness: Plugin Check fixes, a languages folder, and a security contact.

= 0.5.0 =
* New Security settings that belong to this site and can only be loosened here: allowed dashboard IP addresses, what the dashboard may do (updates, activate/deactivate, installs from WordPress.org, installs from ZIP or web address, deletes), and security alert emails.
* Correctly signed requests from an address that isn't allowed are refused, logged and emailed, with a one-click "my dashboard moved" fix.
* The dashboard can lock this site to its address and switch permissions off, but never back on.
* Optional wp-config.php constants USCN_ALLOWED_IPS and USCN_CLIENT_IP_HEADER (the right-most address in a forwarding header is used).
* Installing with "activate" also needs the activate permission, and replacing an installed plugin also needs the updates permission.

= 0.4.1 =
* Update checks, updates and plugin changes requested by the dashboard now run inside this site's admin area, so paid plugins that only check for updates there (such as Crocoblock's Jet plugins) are found and updated. If the server blocks this, they run as before and the dashboard shows a warning.
* Refreshing no longer drops pending updates that paid plugins reported earlier.

= 0.4.0 =
* Renamed from FM Connector to Unstoppable Site Connector. Takes over FM Connector's connection, settings and activity log, then removes FM Connector.

== Upgrade Notice ==

= 0.9.0 =
Adds maintenance mode that your dashboard can switch on and off. Nothing changes until your dashboard uses it.

= 0.8.0 =
Lets your dashboard move this site to a new dashboard address. Nothing changes until a move is started.

= 0.7.0 =
Adds optional admin footer branding from your dashboard. Nothing changes until your dashboard sets it.

= 0.6.0 =
Adds bulk connect for dashboards moving many sites at once. Nothing changes for sites that are already connected.

= 0.5.0 =
New security settings: lock this site to your dashboard's address, choose what the dashboard may do, and get alert emails.

= 0.4.1 =
Fixes missing updates for paid plugins such as Crocoblock's Jet plugins.

= 0.4.0 =
FM Connector is now Unstoppable Site Connector. Install it and the site stays connected.
