<?php
declare(strict_types=1);

namespace Sparkable\MagoBulkUpdate\Model;

use InvalidArgumentException;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\AuthorizationFactory as ProductAuthorizationFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Eav\Model\Entity\Attribute\Backend\Datetime;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Webapi\ServiceInputProcessor;
use MagoAssistant\Mago\Service\Api\InProcess\AdminAuthorizationFactory;
use MagoAssistant\Mago\Service\Api\InProcess\Guard\DesignChangeRefusedException;
use MagoAssistant\Mago\Service\Api\InProcess\Guard\ProductDesignGuard;
use Sparkable\MagoProductFinder\Model\AttributeAccess;
use Sparkable\MagoProductFinder\Model\AttributeOptions;
use Sparkable\MagoProductFinder\Model\AttributeSuggester;

class AttributeValueResolver
{
    private const array BLOCKED_CODES = [
        'url_key',
        'url_path',
        'category_ids',
        'quantity_and_stock_status',
        'tier_price',
        'media_gallery',
        'gallery',
    ];
    private const array BLOCKED_INPUTS = ['media_image', 'gallery', 'weee'];
    private const array OPTION_INPUTS = ['select', 'multiselect', 'boolean'];

    public function __construct(
        private readonly EavConfig $eavConfig,
        private readonly AttributeOptions $attributeOptions,
        private readonly AttributeSuggester $attributeSuggester,
        private readonly AttributeAccess $attributeAccess,
        private readonly ServiceInputProcessor $serviceInputProcessor,
        private readonly ProductAuthorizationFactory $productAuthorizationFactory,
        private readonly AdminAuthorizationFactory $adminAuthorizationFactory,
    ) {
    }

    public static function getParameterSchema(): array
    {
        return [
            'attribute_code' => [
                'type' => 'string',
                'description' => 'Product attribute code.',
            ],
            'value' => [
                'type' => 'string',
                'description' => 'Only for update_products: the new value. Option label or option ID for a dropdown '
                    . 'attribute, comma-separated options for a multiselect attribute, 1 or 0 for a yes/no '
                    . 'attribute, YYYY-MM-DD for a date. An empty string clears the value.',
            ],
        ];
    }

    public function resolve(mixed $attributeCode, mixed $rawValue, ?int $adminUserId = null): ResolvedChange
    {
        $code = is_string($attributeCode) ? trim($attributeCode) : '';
        if ($code === '') {
            throw new InvalidArgumentException('Give the attribute_code of the attribute to set');
        }

        $attribute = $this->getWritableAttribute($code, $rawValue);
        $product = $this->convertLikeTheWebApi($code, $this->readValue($attribute, $rawValue));
        if ($adminUserId !== null) {
            $this->authorizeDesignChange($product, $adminUserId);
        }
        $this->formatDate($attribute, $product);
        try {
            $attribute->getBackend()->validate($product);
        } catch (LocalizedException $e) {
            throw new InvalidArgumentException($e->getMessage(), previous: $e);
        }

        return $this->createChange($attribute, $this->toStoredValue($product->getData($code)));
    }

    private function getWritableAttribute(string $code, mixed $rawValue): AbstractAttribute
    {
        $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);
        if (!$attribute || !$attribute->getAttributeId()) {
            throw new InvalidArgumentException($this->attributeSuggester->describeUnknown(
                $code,
                is_scalar($rawValue) ? (string)$rawValue : ''
            ));
        }
        if (in_array($code, self::BLOCKED_CODES, true)
            || in_array($attribute->getFrontendInput(), self::BLOCKED_INPUTS, true)
            || $attribute->isStatic()
            || $attribute->getIsUnique()
        ) {
            throw new InvalidArgumentException(sprintf('Attribute "%s" does not support a bulk update', $code));
        }
        if (!$this->attributeAccess->maySetValues($attribute)) {
            throw new InvalidArgumentException($this->attributeAccess->describeValuesDenial($attribute));
        }

        return $attribute;
    }

    private function readValue(AbstractAttribute $attribute, mixed $rawValue): ?string
    {
        $value = match (true) {
            is_bool($rawValue) => $rawValue ? '1' : '0',
            is_scalar($rawValue) => trim((string)$rawValue),
            $rawValue === null => '',
            default => throw new InvalidArgumentException('Give the value as text'),
        };
        if ($value === '') {
            if ($attribute->getIsRequired()) {
                throw new InvalidArgumentException(
                    sprintf('Attribute "%s" is required and cannot be cleared', $attribute->getAttributeCode())
                );
            }

            return null;
        }
        if (!in_array($attribute->getFrontendInput(), self::OPTION_INPUTS, true)) {
            return $value;
        }

        $items = $attribute->getFrontendInput() === 'multiselect'
            ? array_filter(array_map(trim(...), explode(',', $value)), strlen(...))
            : [$value];

        return implode(',', array_unique(array_map(
            fn (string $item): string => $this->resolveOption($attribute, $item),
            $items
        )));
    }

    private function resolveOption(AbstractAttribute $attribute, string $value): string
    {
        return $this->attributeOptions->findOptionId($attribute, $value)
            ?? throw new InvalidArgumentException(sprintf(
                '"%s" is not an option of "%s". Options: %s',
                $value,
                $attribute->getAttributeCode(),
                $this->attributeOptions->describe($attribute)
            ));
    }

    private function convertLikeTheWebApi(string $code, ?string $value): Product
    {
        $payload = in_array($code, ProductInterface::ATTRIBUTES, true)
            ? [$code => $value]
            : ['custom_attributes' => [['attribute_code' => $code, 'value' => $value]]];

        try {
            [$product] = $this->serviceInputProcessor->process(
                ProductRepositoryInterface::class,
                'save',
                ['product' => $payload]
            );
        } catch (\Exception $e) {
            throw new InvalidArgumentException($e->getMessage(), previous: $e);
        }

        return $product;
    }

    private function authorizeDesignChange(Product $product, int $adminUserId): void
    {
        $productAuthorization = $this->productAuthorizationFactory
            ->create(['authorization' => $this->adminAuthorizationFactory->create($adminUserId)]);
        try {
            $productAuthorization->authorizeSavingOf($product);
        } catch (AuthorizationException $e) {
            throw new InvalidArgumentException(
                DesignChangeRefusedException::fromCoreRefusal($e, ProductDesignGuard::ACL_RESOURCE)->getMessage(),
                previous: $e
            );
        }
    }

    private function formatDate(AbstractAttribute $attribute, Product $product): void
    {
        $backend = $attribute->getBackend();
        if (!$backend instanceof Datetime) {
            return;
        }

        $code = $attribute->getAttributeCode();
        try {
            $product->setData($code, $backend->formatDate($product->getData($code)));
        } catch (\Exception $e) {
            throw new InvalidArgumentException(sprintf('Attribute "%s": Invalid date', $code), previous: $e);
        }
    }

    private function toStoredValue(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? '1' : '0',
            default => (string)$value,
        };
    }

    private function createChange(AbstractAttribute $attribute, ?string $value): ResolvedChange
    {
        return new ResolvedChange(
            attributeCode: $attribute->getAttributeCode(),
            attributeLabel: (string)$attribute->getDefaultFrontendLabel(),
            value: $value,
            displayValue: $this->getDisplayValue($attribute, $value),
            scope: match (true) {
                (bool)$attribute->isScopeGlobal() => 'global',
                (bool)$attribute->isScopeWebsite() => 'website',
                default => 'store',
            },
            applyTo: array_values(array_filter((array)$attribute->getApplyTo())),
        );
    }

    private function getDisplayValue(AbstractAttribute $attribute, ?string $value): string
    {
        if ($value === null) {
            return '(empty)';
        }
        if (!in_array($attribute->getFrontendInput(), self::OPTION_INPUTS, true)) {
            return $value;
        }

        return implode(', ', array_map(
            fn (string $optionId): string => $this->attributeOptions->getLabel($attribute, $optionId),
            explode(',', $value)
        ));
    }
}
