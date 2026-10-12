# Unstoppable Site Connector

Connects a WordPress site to an **Unstoppable Site Manager** dashboard, the self-hosted dashboard your web professional runs on their own WordPress site. Once connected, the dashboard can check health, run updates, manage plugins, sign in with one click and build monthly care reports.

**Connecting delegates administration of the site to whoever runs that dashboard.** Unstoppable Solutions runs no servers in this flow and never receives site data.

## Install and connect

1. Download the latest ZIP from [Releases](../../releases) and install it on the client site (Plugins > Add New > Upload Plugin), then activate it.
2. Go to **Tools > Unstoppable Site Connector**, confirm what connecting allows, and click **Generate connection key**.
3. Paste the key into the dashboard's **Add Site** screen. The key works once and expires after 15 minutes.

Connecting many sites at once? The dashboard can make a **bulk-connect** copy of this plugin that connects its site by itself. See *Bulk connect* below.

Requires WordPress 5.8+ and PHP 7.4+.

## Security model

- **Pairing:** a one-time code (15 minutes) made in this site's wp-admin.
- **Requests:** every dashboard request is signed with Ed25519 over `METHOD\nPATH\nTIMESTAMP\nNONCE\nsha256(body)`, must be under 5 minutes old, and each nonce works once.
- **No credentials:** no usernames or passwords are stored or sent.
- **Site-owned settings** (Tools > Unstoppable Site Connector > Security):
  - *Allowed dashboard addresses.* A correctly signed request from any other IP is refused, logged and emailed.
  - *Permissions:* updates, activate/deactivate, installs from WordPress.org, installs from ZIP/URL, deletes, one-click login.
  - *Security alert emails.*
  - The dashboard can only make these stricter; loosening them requires this site's own wp-admin.
- **Plugin sources:** files are only installed from WordPress.org or the connected dashboard's own site.
- **One-click login:** single-use links, valid for 60 seconds, logged.
- **Revocation:** click **Disconnect** at any time.

Optional `wp-config.php` constants:

```php
define( 'USCN_ALLOWED_IPS', '203.0.113.10' );            // lock the allowed dashboard addresses
define( 'USCN_CLIENT_IP_HEADER', 'HTTP_CF_CONNECTING_IP' ); // only if the site is reachable solely through that proxy
```

## Bulk connect

A dashboard owner can download a copy of this plugin from **Add Site > Connect many sites at once**. It is the same plugin with an extra `enroll.json` file holding the dashboard's address and a time-limited, cancellable pass.

When that copy is active on a site that isn't connected yet, the site:

1. makes its own one-time pairing code,
2. sends the code and the pass to the dashboard,
3. lets the dashboard pair with it normally.

The site's settings screen and alert email say how it was connected. A normal copy of this plugin never connects anywhere on its own.

## Security

Please report security issues privately. See [SECURITY.md](SECURITY.md).

## License

GPL-2.0-or-later. See [license.txt](license.txt).

Made by [Unstoppable Solutions](https://unstoppablesolutions.app).
