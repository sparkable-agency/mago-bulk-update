<?php
declare(strict_types=1);

namespace Sparkable\MagoBulkUpdate\Model;

final readonly class ResolvedChange
{
    public function __construct(
        public string $attributeCode,
        public string $attributeLabel,
        public ?string $value,
        public string $displayValue,
        public string $scope,
        public array $applyTo,
    ) {
    }

    public function toArray(): array
    {
        return [
            'attribute_code' => $this->attributeCode,
            'label' => $this->attributeLabel,
            'value' => $this->value ?? '',
            'display_value' => $this->displayValue,
        ];
    }
}
