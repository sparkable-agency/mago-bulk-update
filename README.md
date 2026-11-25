# Mago Bulk Update

A tool for the [Mago](https://github.com/mago-assistant/magento2-mago) admin assistant. It changes one attribute on many products at once, and adds options to a dropdown attribute.

| Action | What it does |
| --- | --- |
| `update_products` | Sets one attribute to one value on every product of a selection, at most 1000 products per call. |
| `add_attribute_options` | Adds options to a select or multiselect attribute, with translations for one store view. |

Example questions:

- "Set the special price of all red gears to 9.50."
- "Add the options Foam and Gel to the grip attribute."

## Installation

```
composer require sparkable/magento-mago-bulk-update
bin/magento module:enable Sparkable_MagoProductFinder Sparkable_MagoBulkUpdate
bin/magento setup:upgrade
```

The module needs `sparkable/magento-mago-product-finder`. Composer installs it.

## How a write works

- The admin user confirms every write on a card. The card shows the product count, the selection in words, the new value and sample SKUs.
- The tool checks a value as the Magento web API does for a product save.
- The tool keeps no copy of the old values.
- The tool refuses SKU, URL key, category, stock, tier price, media and unique attributes.

## Permissions and privacy

- `update_products` needs `Magento_Catalog::update_attributes`. A design attribute also needs `Magento_Catalog::edit_product_design`.
- `add_attribute_options` needs `Magento_Catalog::attributes_attributes`.
- A merchant can close an attribute for the assistant. See "Attribute access" in the product finder module.
- All returned fields are catalog data and cross as they are.

## Tests

```
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist vendor/sparkable/magento-mago-bulk-update/Test/Unit
```
