# InsightTrail for PostHog

**PostHog Analytics for WooCommerce** — server-side event tracking, marketing attribution engine, identity stitching, and LTV enrichment.

---

## What InsightTrail Does

InsightTrail connects your WooCommerce store to [PostHog](https://posthog.com) and tracks the complete customer journey — from the first ad click to lifetime value.

| Capability | What You Get |
|---|---|
| **Server-Side Events** | Order Completed, Refunded, Status Changed — reliable, tamper-proof, deduplicated |
| **Frontend Events** | Product Viewed, Added to Cart, Checkout Started, Product Clicked, Coupon Applied, and more |
| **Attribution Engine** | First-touch + last-touch UTMs, gclid/fbclid/ttclid/msclkid/li_fat_id capture, server-side cookies that bypass Safari ITP |
| **Identity Stitching** | Reads PostHog's JS cookie server-side + browser `posthog.identify()` — funnels work end-to-end |
| **LTV Enrichment** | `total_orders`, `lifetime_value`, `avg_order_value` on every PostHog person profile |
| **Consent Mode** | GDPR-ready: opt-out by default, CookieYes + Complianz integration |

---

## Installation

### From GitHub

1. Download the [latest release](https://github.com/hyroescom/insighttrail-for-posthog/releases)
2. Upload the zip via **Plugins > Add New > Upload Plugin** in WordPress
3. Activate InsightTrail for PostHog
4. Go to **WooCommerce > Settings > InsightTrail**
5. Enter your PostHog API key (`phc_...`)
6. Select your region (US or EU)
7. Done — events start flowing immediately

### Manual

```bash
cd wp-content/plugins/
git clone https://github.com/hyroescom/insighttrail-for-posthog.git
```

Activate in WordPress admin.

---

## Configuration

All settings are in **WooCommerce > Settings > InsightTrail**.

| Setting | Description | Default |
|---|---|---|
| **PostHog API Key** | Your project API key (starts with `phc_`) | — |
| **Region** | US or EU PostHog cloud | US |
| **Custom Proxy URL** | Reverse proxy domain for first-party tracking | — |
| **Server-Side Tracking** | Order events via PHP | Enabled |
| **Frontend Tracking** | Browse/cart events via JS | Enabled |
| **Person Profiles** | Always or identified users only | Always |
| **Consent Mode** | Require cookie consent before tracking | Disabled |

---

## Events Tracked

### Server-Side (PHP)

| Event | Trigger | Key Properties |
|---|---|---|
| `Order Completed` | Payment complete or status → completed | total, revenue, products[], payment_method, shipping_method, attribution data |
| `Order Refunded` | Full or partial refund | refund amount, refunded products[], attribution data |
| `Order Status Changed` | Any status transition | previous_status, new_status |

### Frontend (JavaScript)

| Event | Trigger |
|---|---|
| `Product Viewed` | Single product page load + variation selection |
| `Product List Viewed` | Shop, category, tag pages |
| `Product Clicked` | Click product link on list pages |
| `Products Searched` | Product search results |
| `Product Added` | Add to cart (classic + blocks) |
| `Product Removed` | Remove from cart (classic + blocks) |
| `Cart Viewed` | Cart page |
| `Checkout Started` | Checkout page |
| `Coupon Applied` | Apply coupon code |
| `Coupon Removed` | Remove coupon code |
| `Payment Info Entered` | Interact with payment method |

---

## Attribution Engine

InsightTrail captures marketing attribution data on every visit and persists it to orders:

### What Gets Captured

- **UTM parameters**: `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `utm_term`
- **Ad click IDs**: `gclid` (Google), `fbclid` (Meta), `ttclid` (TikTok), `msclkid` (Microsoft), `li_fat_id` (LinkedIn), `gbraid`, `wbraid`
- **Referrer** and **landing page URL**
- **Days to conversion** and **session count**

### How It Works

```
Visit with ?utm_source=google&gclid=abc123
    ↓
Server-side PHP setcookie() — bypasses Safari ITP
    ↓
apha_ft (first-touch, 365 days) — set once, never overwritten
apha_lt (last-touch, 30 days) — overwritten each attributed visit
apha_cid (click IDs, 90 days) — latest click IDs
    ↓
At checkout: cookies → order meta → PostHog event properties
    ↓
Order Completed event includes:
  first_touch_source: "google"
  first_touch_medium: "cpc"
  last_touch_source: "facebook"
  gclid: "abc123"
  days_to_conversion: 3
```

### Safari ITP Bypass

JavaScript-set cookies with tracking parameters (like `fbclid`) are capped at **24 hours** by Safari's Intelligent Tracking Prevention. InsightTrail uses PHP `setcookie()` — these are server-set first-party cookies and persist for the full configured duration (up to 1 year).

### WooCommerce Native Fallback

When InsightTrail's cookies are unavailable (blocked, expired), the plugin reads WooCommerce 8.5+'s built-in `_wc_order_attribution_*` meta as a fallback.

---

## Identity Stitching

The #1 issue with WooCommerce + PostHog setups: browser events and server events use different identities, breaking funnels.

InsightTrail solves this:

| Scenario | Browser Events | Server Events | Connected? |
|---|---|---|---|
| **Guest checkout** | PostHog anon ID | Same ID (read from PH cookie) | Yes |
| **Logged-in user** | `wp_42` (via `posthog.identify`) | `wp_42` | Yes |
| **Login during checkout** | Anon → `wp_42` (merged) | `wp_42` | Yes |

---

## Person Profile Enrichment

After every order, InsightTrail updates the PostHog person profile:

**`$set` (always updated):**
- `email`, `name`, `phone`, `city`, `state`, `country`
- `total_orders`, `lifetime_value`, `avg_order_value`
- `last_order_date`

**`$set_once` (set on first order only):**
- `first_order_date`, `created_at`
- `acquisition_source`, `acquisition_medium`, `acquisition_campaign`

Build powerful PostHog cohorts like:
- "Customers with LTV > $500 acquired from Google Ads"
- "First-time buyers from TikTok in the last 30 days"
- "Repeat customers (3+ orders) from organic search"

---

## Requirements

- WordPress 5.8+
- WooCommerce 7.0+
- PHP 7.4+
- PostHog account ([free tier: 1M events/month](https://posthog.com/pricing))

---

## Compatibility

- WooCommerce HPOS (High-Performance Order Storage)
- WooCommerce Cart and Checkout Blocks
- Classic (shortcode) checkout
- Reverse proxy setups (custom API host + UI host)
- All major payment gateways

---

## Filters

| Filter | Description | Default |
|---|---|---|
| `apha_data_layer` | Modify the frontend data layer before output | — |
| `apha_max_products_in_data_layer` | Cap products in data layer on list pages | `48` |

---

## Contributing

1. Fork the repo
2. Create a feature branch (`git checkout -b feature/my-feature`)
3. Commit your changes
4. Push and open a pull request

---

## License

GPL-2.0-or-later. See [LICENSE](LICENSE) for details.

---

Built by [AGStudio.ai](https://agstudio.ai)
