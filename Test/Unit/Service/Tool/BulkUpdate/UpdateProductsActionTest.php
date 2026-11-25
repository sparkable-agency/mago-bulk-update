<?php
declare(strict_types=1);

namespace Sparkable\MagoBulkUpdate\Test\Unit\Service\Tool\BulkUpdate;

use InvalidArgumentException;
use Magento\Catalog\Model\Product\Action as ProductAction;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use MagoAssistant\Mago\Service\Store\StoreScopeContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sparkable\MagoBulkUpdate\Model\AttributeValueResolver;
use Sparkable\MagoBulkUpdate\Model\ResolvedChange;
use Sparkable\MagoBulkUpdate\Service\Tool\BulkUpdate\UpdateProductsAction;
use Sparkable\MagoProductFinder\Model\ProductSelection;
use Sparkable\MagoProductFinder\Model\Selection;

class UpdateProductsActionTest extends TestCase
{
    private const array PARAMS = ['skus' => ['A', 'B'], 'changes' => [['attribute_code' => 'color', 'value' => 'Red']]];

    private ProductSelection&MockObject $productSelection;
    private AttributeValueResolver&MockObject $valueResolver;
    private Collection&MockObject $collection;
    private ProductAction&MockObject $productAction;
    private UpdateProductsAction $action;

    protected function setUp(): void
    {
        $this->collection = $this->createMock(Collection::class);
        $this->collection->method('setOrder')->willReturnSelf();
        $this->collection->method('setPageSize')->willReturnSelf();
        $this->productSelection = $this->createMock(ProductSelection::class);
        $this->productSelection->method('createCollection')->willReturn($this->collection);
        $this->valueResolver = $this->createMock(AttributeValueResolver::class);
        $this->productAction = $this->createMock(ProductAction::class);
        $storeScopeContext = $this->createMock(StoreScopeContext::class);
        $storeScopeContext->method('hasStoreView')->willReturnCallback(static fn (int $id): bool => $id === 3);
        $storeScopeContext->method('getUnknownStoreViewError')->willReturn('Unknown store view');
        $storeScopeContext->method('describeStoreTarget')->willReturnCallback(
            static fn (int $id): string => $id === 0 ? 'all store views' : 'store view 3'
        );

        $this->action = new UpdateProductsAction(
            $this->productSelection,
            $this->valueResolver,
            $storeScopeContext,
            $this->productAction,
            $this->createMock(ErrorReporter::class)
        );
    }

    public function testExecuteWritesTheResolvedValuesToTheSelectedProducts(): void
    {
        $this->selectProducts(ids: ['5', '6'], types: ['simple']);
        $this->resolveChange($this->createChange('color', '10', 'Red'));
        $this->productAction->expects(self::once())->method('updateAttributes')->with([5, 6], ['color' => '10'], 3);

        $result = $this->action->execute(self::PARAMS + ['store_id' => 3], 1);

        self::assertTrue($result['success']);
        self::assertSame(2, $result['updated_count']);
        self::assertSame('store view 3', $result['scope']);
        self::assertSame('color', $result['attribute_code']);
        self::assertSame('Red', $result['display_value']);
    }

    #[DataProvider('scopeCautionsProvider')]
    public function testCautionsNameTheCountTheSelectionTheChangeAndTheScope(string $scope, array $scopeLines): void
    {
        $this->selectProducts(ids: ['5', '6'], types: ['simple']);
        $this->productSelection->method('describe')->willReturn(['color is Blue', 'type_id is simple']);
        $this->resolveChange($this->createChange('color', '10', 'Red', scope: $scope));

        self::assertSame(
            [
                'Updates 2 products in store view 3.',
                'Selection: color is Blue; type_id is simple.',
                'Sets "Color" (color) to "Red".',
                ...$scopeLines,
                'Sample: A, B',
                'The old values are not saved.',
            ],
            $this->action->getCautions(self::PARAMS + ['store_id' => 3], 1)
        );
    }

    public static function scopeCautionsProvider(): array
    {
        return [
            'global' => ['global', ['"color" has the global scope: every store view gets the value.']],
            'website' => [
                'website',
                ['"color" has the website scope: every store view of the website gets the value.'],
            ],
            'store view' => ['store', []],
        ];
    }

    public function testRefusesBeforeTheConfirmationCard(): void
    {
        $this->selectProducts(ids: ['5'], types: ['simple']);
        $this->resolveChange($this->createChange('color', '10', 'Red'));

        self::assertNull($this->action->findRefusal(self::PARAMS));
        self::assertSame(
            ['error' => 'Unknown store view'],
            $this->action->findRefusal(self::PARAMS + ['store_id' => 9])
        );
    }

    public function testRefusesAnEmptySelection(): void
    {
        $this->productSelection->method('parse')->willReturn(new Selection());
        $this->productAction->expects(self::never())->method('updateAttributes');

        self::assertSame(['error' => 'Give skus or filters to select products'], $this->action->execute([], 1));
    }

    public function testRefusesAnInvalidFilterOrValue(): void
    {
        $this->productSelection->method('parse')->willThrowException(new InvalidArgumentException('Bad filter'));

        self::assertSame(['error' => 'Bad filter'], $this->action->findRefusal(self::PARAMS));
    }

    public function testRefusesASelectionWithoutProducts(): void
    {
        $this->selectProducts(ids: [], types: []);
        $this->resolveChange($this->createChange('color', '10', 'Red'));

        self::assertSame(['error' => 'The selection matches no products'], $this->action->findRefusal(self::PARAMS));
    }

    public function testRefusesMoreProductsThanTheLimit(): void
    {
        $this->selectProducts(ids: range(1, 1001), types: ['simple']);
        $this->resolveChange($this->createChange('color', '10', 'Red'));
        $this->productAction->expects(self::never())->method('updateAttributes');

        $result = $this->action->execute(self::PARAMS, 1);

        self::assertStringContainsString('matches 1001 products', $result['error']);
        self::assertStringContainsString('at most 1000', $result['error']);
    }

    public function testRefusesAnAttributeThatDoesNotApplyToASelectedProductType(): void
    {
        $this->selectProducts(ids: ['5', '6'], types: ['simple', 'bundle']);
        $this->resolveChange($this->createChange('price_type', '1', 'Fixed', applyTo: ['bundle']));

        self::assertSame(
            ['error' => 'Attribute "price_type" does not apply to product type simple. Add a type_id filter.'],
            $this->action->findRefusal(self::PARAMS)
        );
    }

    public function testExecuteNeedsAnAdminUser(): void
    {
        $this->productAction->expects(self::never())->method('updateAttributes');

        self::assertSame(['error' => 'Admin user context is required'], $this->action->execute(self::PARAMS, 0));
    }

    private function selectProducts(array $ids, array $types): void
    {
        $this->productSelection->method('parse')->willReturn(new Selection(['A', 'B']));
        $this->collection->method('getSize')->willReturn(count($ids));
        $this->collection->method('getAllIds')->willReturn($ids);
        $this->collection->method('getProductTypeIds')->willReturn($types);
        $this->collection->method('getColumnValues')->willReturn(['A', 'B']);
    }

    private function resolveChange(ResolvedChange $change): void
    {
        $this->valueResolver->method('resolve')->willReturn($change);
    }

    private function createChange(
        string $code,
        string $value,
        string $displayValue,
        string $scope = 'store',
        array $applyTo = [],
    ): ResolvedChange {
        return new ResolvedChange($code, ucfirst($code), $value, $displayValue, $scope, $applyTo);
    }
}
