<?php
declare(strict_types=1);

namespace Sparkable\MagoBulkUpdate\Service\Tool;

use MagoAssistant\Mago\Api\Tool\PresentableToolInterface;
use MagoAssistant\Mago\Service\Skills\AbstractSkill;

class BulkUpdate extends AbstractSkill implements PresentableToolInterface
{
    public function getName(): string
    {
        return 'sparkable_bulk_update';
    }

    public function getDisplayName(): string
    {
        return 'Bulk update';
    }

    public function getStatusMessage(string $action, array $input): ?string
    {
        return match ($action) {
            'update_products' => 'Updating products...',
            'add_attribute_options' => 'Adding attribute options...',
            default => null,
        };
    }

    public function getMagentoAcl(array $input = []): string
    {
        return 'Magento_Catalog::catalog';
    }

    protected function getBaseDescription(): string
    {
        return 'Change attribute values on many products at once, or add dropdown options to an attribute. '
            . 'Does not create products, change stock, categories, images or SKUs. Read with '
            . 'sparkable_product_finder first.';
    }

    protected function getBaseInstructions(): string
    {
        return <<<'TEXT'
Product updates:
- Call "find_products" of sparkable_product_finder with the same skus, filters and store_id before
  "update_products". Tell the user the total and a few sample products. Then propose the update.
- One "update_products" call sets one attribute to one value on every selected product. Use one call per
  attribute and per distinct value.
- store_id 0 writes the global value. A store view ID writes a value for that store view only.
- The tool keeps no copy of the old values. Report the old values from "find_products" when the user asks.
Attribute options:
- "add_attribute_options" adds new dropdown options. When "update_products" refuses a value because the
  option does not exist, ask the user, add the option, then repeat the update.
- The tool does not rename or delete options.
After a write, report the count and the scope in one or two sentences.
TEXT;
    }
}
