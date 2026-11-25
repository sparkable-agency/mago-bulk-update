<?php
declare(strict_types=1);

namespace Sparkable\MagoBulkUpdate\Service\Tool\BulkUpdate;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\Source\Table;
use MagoAssistant\Mago\Api\InternalApiClientInterface;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Api\Skill\ValidatingActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Store\StoreScopeContext;
use Sparkable\MagoProductFinder\Model\AttributeAccess;
use Sparkable\MagoProductFinder\Model\AttributeOptions;
use Sparkable\MagoProductFinder\Model\AttributeSuggester;

class AddAttributeOptionsAction implements ActionInterface, ValidatingActionInterface
{
    private const int MAX_OPTIONS = 100;

    public function __construct(
        private readonly EavConfig $eavConfig,
        private readonly AttributeOptions $attributeOptions,
        private readonly AttributeSuggester $attributeSuggester,
        private readonly AttributeAccess $attributeAccess,
        private readonly InternalApiClientInterface $apiClient,
        private readonly StoreScopeContext $storeScopeContext,
    ) {
    }

    public function getName(): string
    {
        return 'add_attribute_options';
    }

    public function getDescription(): string
    {
        return 'Add dropdown options to one select or multiselect product attribute (max '
            . self::MAX_OPTIONS . ' per call). Skips labels that exist';
    }

    public function getParameterSchema(): array
    {
        return [
            'attribute_code' => [
                'type' => 'string',
                'description' => 'Product attribute code.',
            ],
            'labels' => [
                'type' => 'string',
                'description' => 'Only for add_attribute_options: admin labels of the new options, '
                    . 'comma-separated, in display order.',
            ],
            'translations' => [
                'type' => 'string',
                'description' => 'Only for add_attribute_options: the labels for one store view, comma-separated, '
                    . 'in the order of labels. Leave out to use the admin labels.',
            ],
            'translation_store_id' => [
                'type' => 'integer',
                'description' => 'Only for add_attribute_options: store view ID of the translations.',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return 'Magento_Catalog::attributes_attributes';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function getFieldClassification(): array
    {
        return [
            'success' => [PiiClass::PUBLIC],
            'attribute_code' => [PiiClass::PUBLIC],
            'added_count' => [PiiClass::PUBLIC],
            'skipped_existing' => [PiiClass::PUBLIC],
            'label' => [PiiClass::PUBLIC],
            'option_id' => [PiiClass::PUBLIC],
            'added' => [PiiClass::PUBLIC],
            'message' => [PiiClass::PUBLIC],
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

    public function execute(array $params, int $adminUserId): array
    {
        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }
        $plan = $this->plan($params);
        if (isset($plan['error'])) {
            return $plan;
        }

        $endpoint = 'products/attributes/' . urlencode($plan['code']) . '/options';
        $results = [];
        $addedCount = 0;
        foreach ($plan['options'] as $index => $option) {
            $result = $this->apiClient->post(
                $endpoint,
                ['option' => $option + ['sort_order' => $plan['sort_order'] + $index]],
                $adminUserId
            );
            $isAdded = !isset($result['error']);
            $results[] = [
                'label' => $option['label'],
                'added' => $isAdded,
                'option_id' => $isAdded ? preg_replace('/^id_/', '', (string)($result['result'] ?? '')) : '',
                'message' => $isAdded ? '' : (string)$result['error'],
            ];
            $addedCount += (int)$isAdded;
        }

        return [
            'success' => $addedCount > 0 || !$plan['options'],
            'attribute_code' => $plan['code'],
            'added_count' => $addedCount,
            'skipped_existing' => $plan['skipped'],
            'results' => $results,
        ];
    }

    private function plan(array $params): array
    {
        $code = is_string($params['attribute_code'] ?? null) ? trim($params['attribute_code']) : '';
        $attribute = $code === '' ? null : $this->eavConfig->getAttribute(Product::ENTITY, $code);
        if (!$attribute || !$attribute->getAttributeId()) {
            return ['error' => $this->attributeSuggester->describeUnknown($code)];
        }
        if (!in_array($attribute->getFrontendInput(), ['select', 'multiselect'], true)
            || !$attribute->getSource() instanceof Table
        ) {
            return ['error' => sprintf('Attribute "%s" has no editable dropdown options', $code)];
        }
        if (!$this->attributeAccess->mayAddOptions($attribute)) {
            return ['error' => $this->attributeAccess->describeOptionsDenial($attribute)];
        }

        $labels = $this->splitList($params['labels'] ?? null);
        if (!$labels) {
            return ['error' => 'Give at least one label in "labels"'];
        }
        if (count($labels) > self::MAX_OPTIONS) {
            return ['error' => sprintf('One call adds at most %d options', self::MAX_OPTIONS)];
        }
        if (count($labels) !== count(array_unique(array_map(mb_strtolower(...), $labels)))) {
            return ['error' => 'A label is listed twice'];
        }

        $translations = $this->splitList($params['translations'] ?? null);
        $storeId = (int)($params['translation_store_id'] ?? 0);
        if ($translations && count($translations) !== count($labels)) {
            return ['error' => 'Give one translation for each label, in the same order'];
        }
        if ($translations && ($storeId === 0 || !$this->storeScopeContext->hasStoreView($storeId))) {
            return ['error' => $this->storeScopeContext->getUnknownStoreViewError($storeId)];
        }

        $options = [];
        $skipped = [];
        foreach ($labels as $index => $label) {
            if ($this->attributeOptions->hasLabel($attribute, $label)) {
                $skipped[] = $label;
                continue;
            }
            $options[] = [
                'label' => $label,
                'store_labels' => $translations
                    ? [['store_id' => $storeId, 'label' => $translations[$index]]]
                    : [],
            ];
        }

        return [
            'code' => $code,
            'options' => $options,
            'skipped' => $skipped,
            'sort_order' => $this->attributeOptions->count($attribute) + 1,
        ];
    }

    private function splitList(mixed $value): array
    {
        return is_scalar($value)
            ? array_values(array_filter(array_map(trim(...), explode(',', (string)$value)), strlen(...)))
            : [];
    }
}
