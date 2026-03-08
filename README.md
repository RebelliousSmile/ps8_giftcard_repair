# ps8_giftcard_repair

PrestaShop 8 module — Diagnostic and repair tool for `thegiftcard` module data anomalies.

## Problem solved

After a PrestaShop migration, the `thegiftcard` table may have missing or incomplete entries
while the associated `cart_rule` and `order_cart_rule` data is intact. This module detects
and rebuilds the missing gift card table entries from existing cart rule data.

## Features

- Detects missing/orphaned entries in the `thegiftcard` table
- Rebuilds missing entries from `cart_rule` + `order_cart_rule` data
- Calculates remaining balance per gift card
- Dry-run preview before applying any fix
- Graceful degradation if `thegiftcard` module is not installed
- CSRF protection on all write operations

## Requirements

- PrestaShop 8.x
- PHP 8.1+
- `thegiftcard` module installed and active
- Doctrine DBAL (provided by PrestaShop)

## Installation

Upload to `modules/sc_giftcard_repair/` and install from Back Office > Modules.

The module registers under **Advanced Parameters > Scriptami** using the shared `AdminScriptami` parent tab.

## Architecture

```
src/
├── Controller/Admin/     # GiftCardRepairController
├── Service/              # GiftCardFixer, GiftCardConstants, AbstractFixer
└── Traits/               # HaveScriptamiTab
```

## Tests

```bash
composer install
./vendor/bin/phpunit --testdox
```

18 tests, 86 assertions.

## Part of the Scriptami Suite

- [ps8_verify_multishop](https://github.com/RebelliousSmile/ps8_verify_multishop) — Multishop data integrity
- [ps8_replace_text](https://github.com/RebelliousSmile/ps8_replace_text) — Find & replace across the database
- [ps8_giftcard_repair](https://github.com/RebelliousSmile/ps8_giftcard_repair) — Gift card data repair
- [ps8_iqit_repair](https://github.com/RebelliousSmile/ps8_iqit_repair) — IQIT Warehouse theme module repair
