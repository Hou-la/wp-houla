# WP-Houla - Sync WooCommerce with the Hou.la Marketplace & Live Shopping

Connect your WooCommerce store to [Hou.la](https://hou.la) - the social network, content-creation and live-shopping marketplace. Your products stay in sync **both ways**, and orders placed on Hou.la (marketplace or live) flow back into WooCommerce with full stock management. Short links and QR codes are included as a bonus.

---

## Table of Contents

- [Overview](#overview)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Authentication](#authentication)
- [Usage](#usage)
  - [Automatic Short Links](#automatic-short-links)
  - [QR Codes](#qr-codes)
  - [Shortcode](#wphoula-shortcode)
  - [WooCommerce Sync](#woocommerce-sync)
  - [Order Reception](#order-reception)
- [Settings Page](#settings-page)
- [Editor Metabox](#editor-metabox)
- [Uninstallation](#uninstallation)
- [FAQ](#faq)
- [Hooks & Filters](#hooks--filters)
- [Architecture](#architecture)
- [Development](#development)
- [Support](#support)
- [License](#license)

---

## Overview

**WP-Houla** is a WordPress plugin that integrates your site with the [Hou.la](https://hou.la) marketing platform for short links, QR codes, and bio pages with built-in social commerce.

The plugin covers two primary use cases:

1. **Short links & QR codes** - every published post, page, or custom post type automatically receives a Hou.la short link and a QR code. The native WordPress shortlink (`get_shortlink()`) is replaced by the Hou.la link. Click statistics are available directly in the editor sidebar.

2. **Social commerce via WooCommerce** - your WooCommerce products are synchronized in real time with your Hou.la bio page. Orders placed by customers through Hou.la Pay (Stripe) are automatically created in WooCommerce with stock management and refund handling.

---

## Features

### Short Links

- Automatic generation on publish for all public post types (posts, pages, CPTs)
- Replaces the native WordPress shortlink - "Get Shortlink" returns the Hou.la link
- One-click copy from the editor metabox
- Manual regeneration when needed (URL change, etc.)

### QR Codes

- QR code generated automatically with each short link
- QR code preview in the editor metabox
- Direct download as image
- Embed in content via the `[wphoula qrcode=1]` shortcode

### Statistics

- Total click count
- Clicks today
- QR code scan count
- Displayed in the sidebar metabox for each post/page

### [wphoula] Shortcode

- `[wphoula]` - displays the current post's short link
- `[wphoula text="Click here"]` - custom link text
- `[wphoula qrcode=1]` - displays the QR code image
- `[wphoula post_id="42"]` - displays a specific post's short link

### WooCommerce Sync

- Automatic sync on product create, update, and delete
- Real-time stock synchronization (simple and variable products)
- Batch sync of the entire catalog in one click
- Data synced: name, description, prices (regular, sale), images, gallery, categories, tags, SKU, dimensions, weight, variations, attributes

### Order Reception

- Secured webhook endpoint (`POST /wp-json/wp-houla/v1/webhook`)
- HMAC-SHA256 verification on every incoming request
- Automatic WooCommerce order creation with "processing" status
- Automatic stock decrement
- Refund handling with automatic restocking
- Duplicate order detection

### Security

- OAuth 2.0 with PKCE (Proof Key for Code Exchange) for initial connection
- **Persistent API key** (`houla_sk_...`) generated after OAuth, used for all subsequent requests
- API key stored encrypted (AES-256-CBC) in WordPress options
- Automatic fallback to OAuth token refresh if API key is revoked
- Automatic API key rotation on reconnection (old key revoked, new one generated)
- CSRF protection via OAuth `state` parameter
- HMAC-SHA256 verification on all incoming webhooks

---

## Requirements

| Component | Minimum Version |
|-----------|----------------|
| WordPress | 5.8 |
| PHP | 7.4 |
| WooCommerce | 7.0 |
| OpenSSL PHP extension | required |

A Hou.la account (free or Pro) is required. Create an account at [hou.la](https://hou.la).

---

## Installation

### From a ZIP file

1. Download the latest release from [GitHub Releases](https://github.com/Hou-la/wp-houla/releases)
2. In the WordPress admin, go to **Plugins > Add New**
3. Click **Upload Plugin**
4. Select the `wp-houla-x.x.x.zip` file
5. Click **Install Now** then **Activate**

### From source

1. Clone the repository:
   ```bash
   git clone https://github.com/Hou-la/wp-houla.git
   ```
2. Copy the repository folder to `wp-content/plugins/wp-houla/`
3. Activate the plugin from the **Plugins** menu

### From the WordPress.org directory (coming soon)

1. In **Plugins > Add New**, search for "WP-Houla"
2. Click **Install Now** then **Activate**

---

## Configuration

### 1. Connect to Hou.la

1. Go to **WooCommerce > Hou.la**
2. Click **Connect to Hou.la**
3. Authorize the application in the Hou.la window
4. You are redirected automatically with a "Connected" status

The workspace name and account email are displayed in the settings.

After OAuth, the plugin automatically provisions a persistent API key (`houla_sk_...`) that is used for all subsequent API requests. This key does not expire and survives OAuth token expiration. See [Authentication](#authentication) for details.

### 2. Sync Settings

In the **Sync** tab:

- **Auto-sync** (enabled by default) - WooCommerce products are pushed to Hou.la on every change.
- **Sync on publish** (enabled by default) - newly published products are synced immediately.
- **Category filter** - select which WooCommerce categories to sync. Leave all unchecked to sync all products.
- **Batch sync** - push the entire catalog in one click (useful after initial installation or import).

### 3. Orders

Orders are received via webhook. No manual configuration is required. The webhook URL is registered automatically during OAuth connection.

### 4. Debug

Enable debug mode in the **Debug** tab to log API calls to the WordPress debug log (`WP_DEBUG_LOG`).

### 5. Development Mode

For local development or staging environments, set a custom API URL in the **Debug** tab:

1. Go to the **Debug** tab
2. Enter your API URL in the **API URL** field (e.g. `http://localhost:53001`, `http://192.168.1.100:53001`, or an ngrok URL)
3. Click **Save Settings**

The plugin will use this URL for all API calls instead of the production `https://hou.la`.

**Options for local testing:**

| Scenario | API URL |
|----------|---------|
| API runs locally, WP runs on the same machine | `http://localhost:53001` |
| API runs locally, WP runs on a remote server | Use [ngrok](https://ngrok.com): `https://abc123.ngrok-free.app` |
| API runs on local network | `http://192.168.1.100:53001` |
| Staging server | `https://staging.hou.la` |

Leave the field empty to use the production API (`https://hou.la`).

---

## Authentication

### How it works

WP-Houla uses a two-phase authentication strategy:

1. **Initial connection** -- OAuth 2.0 + PKCE flow to authorize the plugin with a Hou.la account. This creates a short-lived JWT access token (15 min) and a refresh token (7 days).

2. **Persistent API key** -- immediately after the OAuth token exchange, the plugin calls `POST /api/ecommerce/api-key` to generate a persistent API key (`houla_sk_...`). This key is stored encrypted in WordPress options and used for all subsequent API requests via the `X-Api-Key` header.

### Why not just OAuth tokens?

OAuth access tokens expire after 15 minutes. The refresh token lasts 7 days but can also fail (server restart, token rotation, user password change). When both expire, the plugin shows "Not connected" and all sync/shortlink operations stop until manual reconnection.

The API key never expires. It can only be revoked manually from the Hou.la dashboard. This ensures the plugin keeps working indefinitely after the initial connection.

### Failure handling

| Scenario | Behavior |
|----------|----------|
| API key valid | Request succeeds via `X-Api-Key` header |
| API key revoked (401) | Key cleared, falls back to OAuth token refresh |
| OAuth refresh succeeds | Request retried with new Bearer token |
| Both fail | Plugin marked as disconnected, admin notice shown |
| Reconnection via OAuth | New API key generated, old one automatically revoked |
| No API key (legacy install) | Falls back to OAuth Bearer token transparently |

### Request flow diagram

```
request()
  |-- resolve_auth_headers()
  |     |-- get_api_key() -> X-Api-Key header     (preferred)
  |     |-- get_access_token() -> Bearer header   (fallback)
  |
  |-- HTTP request
  |     |-- 200 OK -> return response
  |     |-- 401 Unauthorized
  |           |-- was API key? -> clear key, try refresh_token()
  |           |     |-- refresh OK -> retry request with Bearer
  |           |     |-- refresh fail -> disconnect()
  |           |-- was Bearer? -> try refresh_token()
  |                 |-- refresh OK -> retry request
  |                 |-- refresh fail -> disconnect()
```

### API key details

| Property | Value |
|----------|-------|
| Format | `houla_sk_` + 24 random bytes (base64url) |
| Storage | AES-256-CBC encrypted in `wp_options` (`wphoula-options.api_key`) |
| Name on Hou.la | `wp-houla: <site_url>` |
| Expiration | Never (revocable from Hou.la dashboard) |
| Scope | Full API access for the connected user |
| Rotation | Automatic on reconnection (old key revoked) |

---

## Usage

### Automatic Short Links

Once the plugin is connected:

- **On publish** - a short link is generated automatically when content is published.
- **Existing content** - the link is generated on first access or via the "Generate short link" button in the metabox.
- **WordPress shortlink** - `get_shortlink()` now returns the Hou.la link.

Links are stored in post meta and do not generate additional API calls after creation.

### QR Codes

Each short link generates a QR code visible in the editor metabox:

- **Preview** - QR code image in the sidebar
- **Download** - "Download QR" button
- **Embed** - `[wphoula qrcode=1]` shortcode in content

### [wphoula] Shortcode

| Example | Result |
|---------|--------|
| `[wphoula]` | Clickable short link for the current post |
| `[wphoula text="View"]` | Link with custom text |
| `[wphoula qrcode=1]` | QR code image (200x200) |
| `[wphoula post_id="42"]` | Short link for a specific post |

**Parameters:**

| Parameter | Description | Default |
|-----------|-------------|---------|
| `text` | Link text | Short link URL |
| `title` | Title attribute | Post title |
| `post_id` | Post ID | Current post |
| `qrcode` | Display QR code | `false` |
| `before` | HTML before the link | empty |
| `after` | HTML after the link | empty |

### WooCommerce Sync

Three modes:

1. **Automatic** - every create/update/delete pushes changes to Hou.la.
2. **Manual** - "Sync" and "Remove" buttons in the product metabox.
3. **Batch** - full catalog sync from settings (products processed in pages of 50).

**Category filtering:** optionally limit sync to specific WooCommerce product categories via the Sync tab. Products outside the selected categories are skipped during both automatic hooks and batch sync.

**Synced data:**

| Field | Description |
|-------|-------------|
| Name | Product name |
| Description | Short description (or full if empty) |
| URL | Permalink |
| Prices | Current, regular, sale |
| Currency | Site currency |
| Images | Featured image + gallery |
| Categories | Product categories |
| Tags | Product tags |
| SKU | Stock keeping unit |
| Weight | Product weight |
| Dimensions | Length, width, height |
| Stock | Quantity + status |
| Variations | Price, image, SKU, stock, attributes |
| Attributes | Visible attributes |

### Order Reception

When a customer purchases through your Hou.la bio page:

1. Stripe processes the payment via Hou.la Pay
2. Hou.la sends a webhook to your site
3. The plugin verifies the HMAC-SHA256 signature
4. A WooCommerce order is created (status "processing", method "Hou.la Pay (Stripe)")
5. Stock is decremented

On refund, an `order.refunded` webhook triggers WooCommerce refund with automatic restocking.

---

## Settings Page

Accessible via **WooCommerce > Hou.la**:

| Tab | Content |
|-----|---------|
| Connection | Status, workspace, email, connect/disconnect button, post type filter |
| Sync | Auto-sync options, category filter, last sync date, counter, batch button |
| Orders | Order counter, last order date, webhook URL |
| Debug | Log toggle, custom API URL, version info |

---

## Editor Metabox

### Posts, Pages, and CPTs

"Hou.la" metabox in the sidebar:
- Short link + copy button
- QR code preview + download
- Statistics (total clicks, clicks today, QR scans)
- Generate/regenerate button

### WooCommerce Products

Product metabox with additional features:
- Sync status and date
- Commerce statistics (views, clicks, sales, revenue)
- Sync/remove buttons
- Short link + QR code

---

## Uninstallation

Deleting the plugin via the Plugins menu cleans up:

- Options (`wphoula-options`, `wphoula-authorized`)
- Post meta (links, product IDs, sync status)
- Order meta (Hou.la order IDs, transaction IDs)
- Transients

Short links on Hou.la remain active. Manage them from your Hou.la dashboard.

---

## FAQ

### Does the plugin work without WooCommerce?

Yes. Short links and QR codes work on all public post types regardless of WooCommerce. The commerce features (product sync, order reception, webhook) require WooCommerce 7.0+.

### What happens if the OAuth token expires?

Nothing. After the initial connection, the plugin uses a persistent API key that never expires. The API key is generated automatically during the OAuth flow and stored encrypted in WordPress. Even if the OAuth tokens expire or the refresh fails, the API key continues to work.

### Can the API key be revoked?

Yes, from the Hou.la dashboard (Settings > API Keys). If the key is revoked, the plugin will attempt to refresh the OAuth token as a fallback. If both fail, the plugin will ask you to reconnect.

### Is a paid account required?

No. The free plan provides all features. The only difference is the commission on sales: 8% (free) vs 3% (Pro). Short links and QR codes are free and unlimited.

### How are payments processed?

Payments are handled entirely by Hou.la via Stripe Connect. The WordPress plugin never touches payment data. Stripe ensures PCI DSS compliance.

### Do short links survive uninstallation?

Yes. Links on Hou.la remain active. Only the local WordPress metadata is removed.

### How do I regenerate a short link?

Click "Regenerate" in the Hou.la metabox. A new link is created; the previous one remains active.

### Do drafts get short links?

No. Links are generated only for published, scheduled, or private content.

### Can the batch sync be run multiple times?

A 10-minute lock prevents simultaneous syncs. Wait for the current sync to finish or for the lock to expire.

### Which content types support short links?

All public types: posts, pages, and any CPT with `public => true`. Customize with the `wphoula_allowed_post_types` filter.

### How do I customize supported content types?

```php
add_filter( 'wphoula_allowed_post_types', function( $types ) {
    // Remove pages
    $types = array_diff( $types, array( 'page' ) );
    return $types;
} );
```

### Do webhooks work behind Cloudflare / Nginx?

Yes, as long as the `X-Houla-Signature` header is forwarded. Most CDNs and reverse proxies do this by default.

---

## Hooks & Filters

### Filters

| Filter | Description | Parameters |
|--------|-------------|------------|
| `wphoula_allowed_post_types` | Post types for short link generation | `array $types` |
| `wphoula_default_options` | Default plugin options | `array $defaults` |

---

## Architecture

```
wp-houla/
  wp-houla.php                      Main plugin file (constants, bootstrap)
  uninstall.php                     Cleanup on uninstall
  includes/
    class-wp-houla.php              Core class (dependencies, hooks)
    class-wp-houla-loader.php       Hook queue manager
    class-wp-houla-i18n.php         Text domain loader
    class-wp-houla-options.php      Options CRUD + AES-256-CBC encryption
    class-wp-houla-activator.php    Activation (PHP check, webhook secret)
    class-wp-houla-deactivator.php  Deactivation (transient cleanup)
    class-wp-houla-auth.php         OAuth 2.0 + PKCE + API key provisioning
    class-wp-houla-api.php          HTTP client (API key + Bearer fallback)
    class-wp-houla-shortlink.php    Short link generation
    class-wp-houla-post-metabox.php Post metabox (link + QR + stats)
    class-wp-houla-sync.php         Product sync (WC -> Hou.la)
    class-wp-houla-orders.php       Order creation (Hou.la -> WC)
    class-wp-houla-webhook.php      REST endpoint + HMAC verification
    class-wp-houla-metabox.php      Product metabox (sync + stats)
  admin/
    class-wp-houla-admin.php        Admin menu, settings, AJAX handlers
    css/wp-houla-admin.css          Admin styles
    js/wp-houla-admin.js            Admin JavaScript
    images/houla-icon.svg           Plugin icon
    partials/
      settings-page.php             Settings template (4 tabs)
      metabox-product.php           Product metabox template
  languages/
    wp-houla.pot                    Translation template
    wp-houla-fr_FR.po               French translation
    wp-houla-fr_FR.mo               French translation (compiled)
```

### Post Meta

| Key | Scope | Description |
|-----|-------|-------------|
| `_wphoula_shortlink` | any post | Short link URL |
| `_wphoula_link_id` | any post | Link ID on Hou.la |
| `_wphoula_qrcode` | any post | QR code image URL |
| `_wphoula_product_id` | product | Product ID on Hou.la |
| `_wphoula_synced` | product | Sync flag |
| `_wphoula_sync_at` | product | Last sync date |
| `_houla_order_id` | order | Hou.la order ID |
| `_houla_transaction_id` | order | Stripe transaction ID |

---

## Development

### Running Tests

```bash
composer install
vendor/bin/phpunit                       # all tests
vendor/bin/phpunit --testsuite unit      # unit tests only
vendor/bin/phpunit --testsuite integration # integration tests only
```

### Code Standards

```bash
composer run phpcs
```

### Building the Package

```bash
# Windows PowerShell
.\build.ps1
# The ZIP is generated in releases/
```

### Contributing

1. Fork the repository
2. Create a branch (`git checkout -b feature/my-feature`)
3. Commit (`git commit -m 'feat: description'`)
4. Push (`git push origin feature/my-feature`)
5. Open a Pull Request

---

## Support

- Website: [hou.la](https://hou.la)
- Report a bug: [GitHub Issues](https://github.com/Hou-la/wp-houla/issues)
- Email: hello@hou.la

---

## License

GPLv2 or later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
