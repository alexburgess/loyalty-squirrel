# Loyalty Squirrel

Loyalty Squirrel is a WordPress plugin for managing Square Loyalty balances from inside WooCommerce. It lets store admins add, remove, set, and review Square Loyalty points for linked WordPress customers while keeping a local audit trail of admin activity.

The plugin was built for stores where WordPress users already have a Square Customer ID stored against their profile.

## Features

- View linked WooCommerce customers and their live Square Loyalty balances.
- Add, remove, or set loyalty balances for an individual customer.
- Apply loyalty points to every customer in a selected WordPress role.
- Exclude customers from a role action before applying points.
- Re-add previously excluded customers from a role action later.
- Automatically enroll linked Square customers into Square Loyalty when possible.
- Identify linked customers who cannot be enrolled because they are missing a phone number.
- Review Square Loyalty event history and local plugin activity.
- Show customers their available loyalty balance, history, and expiry dates in WooCommerce My Account.
- Customize the customer-facing loyalty labels, My Account endpoint slug, and explanatory account text.

## Requirements

- WordPress with WooCommerce installed and active.
- A Square access token with Loyalty API read/write access.
- WordPress users linked to Square customers through user meta.
- A phone number user meta field if you want the plugin to auto-enroll customers into Square Loyalty.

By default, Loyalty Squirrel expects:

- Square customer ID meta key: `square_customer_id`
- Phone number meta key: `billing_phone`
- Square API version: `2026-01-22`

These can be changed in the plugin settings.

## Setup

1. Install and activate the plugin in WordPress.
2. Open **Loyalty Squirrel > Settings**.
3. Add your Square access token.
4. Confirm the Square Customer ID meta key used by your site.
5. Confirm the phone number meta key used for Square Loyalty enrollment.
6. Choose the singular and plural customer-facing labels, such as `Tasting Coupon` and `Tasting Coupons`.
7. Save settings.

The plugin adds admin pages for:

- **Overview**: live balances, linked customers, missing-phone dashboard, and point movement chart.
- **Manage Customer**: search for a customer and apply manual loyalty changes.
- **Apply by Role**: apply loyalty changes to a WordPress role with exclusions.
- **Activity**: review manual actions, role actions, skipped customers, failures, and re-add options.
- **Settings**: configure Square, labels, endpoint behavior, and role dropdown options.
- **About**: plugin and environment details.

## WooCommerce My Account

Customers with a linked Square Loyalty account see a My Account section for their loyalty balance. It shows:

- available balance,
- optional explanatory text from settings,
- recent loyalty history,
- point changes,
- expiry dates where Square provides expiry deadlines.

Customers without a Square Loyalty account do not see the My Account menu item.

## Square Loyalty Behavior

Loyalty Squirrel uses Square as the source of truth for loyalty accounts, balances, adjustments, events, and expiry deadlines. Local WordPress tables are used for plugin-side audit history and role action tracking.

If auto-enrollment is enabled, the plugin will try to create a Square Loyalty account before applying points to a linked customer who does not already have one. Customers without a usable phone number are skipped and surfaced in the admin dashboard.

## Development

The plugin source is self-contained and does not require a build step.

Useful checks:

```bash
php -l square-loyalty-points.php
php -l includes/class-square-loyalty-points-plugin.php
php -l includes/class-square-loyalty-points-manager.php
php -l includes/class-square-loyalty-points-square-api.php
node --check assets/js/admin.js
```

The plugin stores settings in the WordPress option `square_loyalty_points_settings` and creates local audit tables on activation.

## License

GPLv2 or later.
