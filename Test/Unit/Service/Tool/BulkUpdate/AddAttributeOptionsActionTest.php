<?php
declare(strict_types=1);

namespace Sparkable\MagoBulkUpdate\Test\Unit\Service\Tool\BulkUpdate;

use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\Source\Table;
use MagoAssistant\Mago\Api\InternalApiClientInterface;
use MagoAssistant\Mago\Service\Store\StoreScopeContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sparkable\MagoBulkUpdate\Service\Tool\BulkUpdate\AddAttributeOptionsAction;
use Sparkable\MagoProductFinder\Model\AttributeAccess;
use Sparkable\MagoProductFinder\Model\AttributeOptions;
use Sparkable\MagoProductFinder\Model\AttributeSuggester;

class AddAttributeOptionsActionTest extends TestCase
{
    private const int ADMIN_USER_ID = 7;

    private InternalApiClientInterface&MockObject $apiClient;
    private AttributeAccess&MockObject $attributeAccess;
    private AddAttributeOptionsAction $action;

    protected function setUp(): void
    {
        $attribute = $this->createMock(Attribute::class);
        $attribute->method('getAttributeId')->willReturn(12);
        $attribute->method('getFrontendInput')->willReturn('select');
        $attribute->method('getSource')->willReturn($this->createMock(Table::class));

        $eavConfig = $this->createMock(EavConfig::class);
        $eavConfig->method('getAttribute')->willReturn($attribute);

        $attributeOptions = $this->createMock(AttributeOptions::class);
        $attributeOptions->method('hasLabel')
            ->willReturnCallback(static fn (Attribute $attribute, string $label): bool => $label === 'Cork');
        $attributeOptions->method('count')->willReturn(4);

        $storeScopeContext = $this->createMock(StoreScopeContext::class);
        $storeScopeContext->method('hasStoreView')->willReturn(true);

        $this->apiClient = $this->createMock(InternalApiClientInterface::class);
        $this->attributeAccess = $this->createMock(AttributeAccess::class);
        $this->action = new AddAttributeOptionsAction(
            $eavConfig,
            $attributeOptions,
            $this->createMock(AttributeSuggester::class),
            $this->attributeAccess,
            $this->apiClient,
            $storeScopeContext,
        );
    }

    public function testAddsEachNewOptionThroughTheOptionsRoute(): void
    {
        $this->attributeAccess->method('mayAddOptions')->willReturn(true);
        $this->apiClient->expects($this->exactly(2))
            ->method('post')
            ->willReturnCallback(function (string $endpoint, array $body, int $adminUserId): array {
                $this->assertSame('products/attributes/demo_grip/options', $endpoint);
                $this->assertSame(self::ADMIN_USER_ID, $adminUserId);

                return match ($body['option']) {
                    [
                        'label' => 'Foam',
                        'store_labels' => [['store_id' => 2, 'label' => 'Schuim']],
                        'sort_order' => 5,
                    ] => ['result' => 'id_31'],
                    [
                        'label' => 'Gel',
                        'store_labels' => [['store_id' => 2, 'label' => 'Gel']],
                        'sort_order' => 6,
                    ] => ['error' => 'No access'],
                };
            });

        $result = $this->action->execute(
            [
                'attribute_code' => 'demo_grip',
                'labels' => 'Cork, Foam, Gel',
                'translations' => 'Kurk, Schuim, Gel',
                'translation_store_id' => 2,
            ],
            self::ADMIN_USER_ID
        );

        $this->assertSame(1, $result['added_count']);
        $this->assertSame(['Cork'], $result['skipped_existing']);
        $this->assertSame(
            [
                ['label' => 'Foam', 'added' => true, 'option_id' => '31', 'message' => ''],
                ['label' => 'Gel', 'added' => false, 'option_id' => '', 'message' => 'No access'],
            ],
            $result['results']
        );
    }

    public function testRefusesAnAttributeTheMerchantClosed(): void
    {
        $this->attributeAccess->method('mayAddOptions')->willReturn(false);
        $this->attributeAccess->method('describeOptionsDenial')->willReturn('Closed');
        $this->apiClient->expects($this->never())->method('post');

        $params = ['attribute_code' => 'demo_grip', 'labels' => 'Foam'];

        $this->assertSame(['error' => 'Closed'], $this->action->findRefusal($params));
        $this->assertSame(['error' => 'Closed'], $this->action->execute($params, self::ADMIN_USER_ID));
    }

    #[DataProvider('refusedParamsProvider')]
    public function testRefusesBeforeTheConfirmation(array $params, string $expectedError): void
    {
        $this->attributeAccess->method('mayAddOptions')->willReturn(true);

        self::assertStringContainsString(
            $expectedError,
            $this->action->findRefusal(['attribute_code' => 'demo_grip'] + $params)['error']
        );
    }

    public static function refusedParamsProvider(): array
    {
        return [
            'no labels' => [[], 'at least one label'],
            'label twice' => [['labels' => 'Foam, foam'], 'listed twice'],
            'translation count' => [
                ['labels' => 'Foam, Gel', 'translations' => 'Schuim', 'translation_store_id' => 2],
                'one translation for each label',
            ],
        ];
    }
}
