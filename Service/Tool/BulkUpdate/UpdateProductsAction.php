<?php
declare(strict_types=1);

namespace Sparkable\MagoBulkUpdate\Service\Tool\BulkUpdate;

use InvalidArgumentException;
use Magento\Catalog\Model\Product\Action as ProductAction;
use MagoAssistant\Mago\Api\Skill\CautionedActionInterface;
use MagoAssistant\Mago\Api\Skill\ValidatingActionInterface;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Store\StoreScopeContext;
use Sparkable\MagoBulkUpdate\Model\AttributeValueResolver;
use Sparkable\MagoProductFinder\Model\ProductSelection;

class UpdateProductsAction implements CautionedActionInterface, ValidatingActionInterface
{
    private const int MAX_PRODUCTS = 1000;
    private const int SAMPLE_SIZE = 5;

    public function __construct(
        private readonly ProductSelection $productSelection,
        private readonly AttributeValueResolver $attributeValueResolver,
        private readonly StoreScopeContext $storeScopeContext,
        private readonly ProductAction $productAction,
        private readonly ErrorReporter $errorReporter,
    ) {
    }

    public function getName(): string
    {
        return 'update_products';
    }

    public function getDescription(): string
    {
        return 'Set one attribute value on every product a selection matches (max '
            . self::MAX_PRODUCTS . ' products per call). Call find_products of sparkable_product_finder first';
    }

    public function getParameterSchema(): array
    {
        return ProductSelection::getParameterSchema() + AttributeValueResolver::getParameterSchema();
    }

    public function getAclResource(): ?string
    {
        return 'Magento_Catalog::update_attributes';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function getFieldClassification(): array
    {
        return [
            'success' => [PiiClass::PUBLIC],
            'updated_count' => [PiiClass::PUBLIC],
            'scope' => [PiiClass::PUBLIC],
            'attribute_code' => [PiiClass::PUBLIC],
            'label' => [PiiClass::PUBLIC],
            'value' => [PiiClass::PUBLIC],
            'display_value' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function findRefusal(array $params): ?array
    {
        $plan = $this->plan($params);

        return isset($plan['error']) ? $plan : null;
    }

    public function getCautions(array $params, int $adminUserId): array
    {
        $plan = $this->plan($params, $adminUserId);
        if (isset($plan['error'])) {
            return [];
        }

        $count = count($plan['ids']);
        $lines = [sprintf(
            'Updates %d %s in %s.',
            $count,
            $count === 1 ? 'product' : 'products',
            $this->storeScopeContext->describeStoreTarget($plan['store_id'])
        )];
        $lines[] = 'Selection: ' . implode('; ', $this->productSelection->describe($params)) . '.';
        $change = $plan['change'];
        $lines[] = sprintf(
            'Sets "%s" (%s) to "%s".',
            $change->attributeLabel,
            $change->attributeCode,
            $change->displayValue
        );
        if ($plan['store_id'] !== 0 && $change->scope !== 'store') {
            $lines[] = sprintf(
                '"%s" has the %s scope: every store view%s gets the value.',
                $change->attributeCode,
                $change->scope,
                $change->scope === 'website' ? ' of the website' : ''
            );
        }
        $lines[] = 'Sample: ' . implode(', ', $plan['sample_skus']) . ($count > self::SAMPLE_SIZE ? ', ...' : '');
        $lines[] = 'The old values are not saved.';

        return $lines;
    }

    public function execute(array $params, int $adminUserId): array
    {
        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }
        $plan = $this->plan($params, $adminUserId);
        if (isset($plan['error'])) {
            return $plan;
        }

        $change = $plan['change'];
        try {
            $this->productAction->updateAttributes(
                $plan['ids'],
                [$change->attributeCode => $change->value],
                $plan['store_id']
            );
        } catch (\Exception $e) {
            return ['error' => 'Failed to update the products: '
                . $this->errorReporter->reportToolFailure('sparkable_bulk_update update_products', $e)];
        }

        return [
            'success' => true,
            'updated_count' => count($plan['ids']),
            'scope' => $this->storeScopeContext->describeStoreTarget($plan['store_id']),
        ] + $change->toArray();
    }

    private function plan(array $params, ?int $adminUserId = null): array
    {
        $storeId = (int)($params['store_id'] ?? 0);
        if ($storeId !== 0 && !$this->storeScopeContext->hasStoreView($storeId)) {
            return ['error' => $this->storeScopeContext->getUnknownStoreViewError($storeId)];
        }

        try {
            $selection = $this->productSelection->parse($params);
            if ($selection->isEmpty()) {
                return ['error' => 'Give skus or filters to select products'];
            }
            $change = $this->attributeValueResolver->resolve(
                $params['attribute_code'] ?? null,
                $params['value'] ?? null,
                $adminUserId
            );
        } catch (InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }

        try {
            $collection = $this->productSelection->createCollection($selection, $storeId);
            $collection->setOrder('entity_id', 'ASC');
            $count = (int)$collection->getSize();
            if ($count === 0) {
                return ['error' => 'The selection matches no products'];
            }
            if ($count > self::MAX_PRODUCTS) {
                return ['error' => sprintf(
                    'The selection matches %d products. One update changes at most %d. Add filters to split it.',
                    $count,
                    self::MAX_PRODUCTS
                )];
            }

            $typeIds = array_map(strval(...), $collection->getProductTypeIds());
            $excluded = $change->applyTo ? array_diff($typeIds, $change->applyTo) : [];
            if ($excluded) {
                return ['error' => sprintf(
                    'Attribute "%s" does not apply to product type %s. Add a type_id filter.',
                    $change->attributeCode,
                    implode(', ', $excluded)
                )];
            }

            $ids = array_map(intval(...), $collection->getAllIds());
            $sample = $this->productSelection->createCollection($selection, $storeId);
            $sample->setOrder('entity_id', 'ASC')->setPageSize(self::SAMPLE_SIZE);

            return [
                'store_id' => $storeId,
                'ids' => $ids,
                'change' => $change,
                'sample_skus' => $sample->getColumnValues('sku'),
            ];
        } catch (\Exception $e) {
            return ['error' => 'Failed to select products: '
                . $this->errorReporter->reportToolFailure('sparkable_bulk_update update_products', $e)];
        }
    }
}
