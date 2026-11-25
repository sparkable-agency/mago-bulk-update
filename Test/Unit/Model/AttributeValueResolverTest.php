<?php
declare(strict_types=1);

namespace Sparkable\MagoBulkUpdate\Test\Unit\Model;

use InvalidArgumentException;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Authorization as ProductAuthorization;
use Magento\Catalog\Model\Product\AuthorizationFactory as ProductAuthorizationFactory;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\Backend\AbstractBackend;
use Magento\Eav\Model\Entity\Attribute\Backend\Datetime;
use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Option\CollectionFactory as OptionCollectionFactory;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Framework\Webapi\ServiceInputProcessor;
use Magento\Store\Model\StoreManagerInterface;
use MagoAssistant\Mago\Service\Api\InProcess\AdminAuthorizationFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sparkable\MagoBulkUpdate\Model\AttributeValueResolver;
use Sparkable\MagoProductFinder\Model\AttributeAccess;
use Sparkable\MagoProductFinder\Model\AttributeOptions;
use Sparkable\MagoProductFinder\Model\AttributeSuggester;

class AttributeValueResolverTest extends TestCase
{
    private AttributeValueResolver $resolver;
    private array $attributes;
    private array $webApiPayloads = [];
    private ProductAuthorization&MockObject $productAuthorization;

    protected function setUp(): void
    {
        $this->attributes = [
            'color' => $this->createAttribute('color', 'select', options: [10 => 'Red', 11 => 'Dark Blue']),
            'tags' => $this->createAttribute(
                'tags',
                'multiselect',
                options: [1 => 'New', 2 => 'Sale', 3 => 'Red, White']
            ),
            'is_featured' => $this->createAttribute('is_featured', 'boolean', options: [1 => 'Yes', 0 => 'No']),
            'no_index' => $this->createAttribute('no_index', 'boolean'),
            'price' => $this->createAttribute('price', 'price', isRequired: true),
            'weight' => $this->createAttribute('weight', 'weight', backendError: 'Weight is not valid'),
            'special_from_date' => $this->createAttribute('special_from_date', 'date', isDate: true),
            'special_to_date' => $this->createAttribute('special_to_date', 'date', isDate: true),
            'description' => $this->createAttribute('description', 'textarea'),
            'sku' => $this->createAttribute('sku', 'text', isStatic: true),
            'ean' => $this->createAttribute('ean', 'text', isUnique: true),
            'image' => $this->createAttribute('image', 'media_image'),
            'url_key' => $this->createAttribute('url_key', 'text'),
            'page_layout' => $this->createAttribute('page_layout', 'text'),
            'missing' => $this->createAttribute('missing', 'text', id: 0),
        ];

        $eavConfig = $this->createMock(EavConfig::class);
        $eavConfig->method('getAttribute')->willReturnCallback(
            fn (string $entityType, string $code): Attribute => $this->attributes[$code] ?? $this->attributes['missing']
        );
        $access = $this->createMock(AttributeAccess::class);
        $access->method('maySetValues')->willReturnCallback(
            static fn (Attribute $attribute): bool => $attribute->getAttributeCode() !== 'page_layout'
        );
        $access->method('describeValuesDenial')->willReturn('The assistant is not allowed to change "page_layout"');
        $suggester = $this->createMock(AttributeSuggester::class);
        $suggester->method('describeUnknown')->willReturnCallback(
            static fn (string $code): string => sprintf('Unknown product attribute "%s".', $code)
        );
        $serviceInputProcessor = $this->createMock(ServiceInputProcessor::class);
        $serviceInputProcessor->method('process')->willReturnCallback($this->processLikeTheWebApi(...));

        $adminAuthorization = $this->createMock(AuthorizationInterface::class);
        $adminAuthorizationFactory = $this->createMock(AdminAuthorizationFactory::class);
        $adminAuthorizationFactory->method('create')->with(7)->willReturn($adminAuthorization);
        $this->productAuthorization = $this->createMock(ProductAuthorization::class);
        $productAuthorizationFactory = $this->createMock(ProductAuthorizationFactory::class);
        $productAuthorizationFactory->method('create')
            ->with(['authorization' => $adminAuthorization])
            ->willReturn($this->productAuthorization);

        $this->resolver = new AttributeValueResolver(
            $eavConfig,
            new AttributeOptions(
                $this->createMock(OptionCollectionFactory::class),
                $this->createMock(StoreManagerInterface::class)
            ),
            $suggester,
            $access,
            $serviceInputProcessor,
            $productAuthorizationFactory,
            $adminAuthorizationFactory
        );
    }

    #[DataProvider('validValuesProvider')]
    public function testResolveNormalizesValue(string $code, mixed $value, ?string $expected, string $display): void
    {
        $change = $this->resolver->resolve($code, $value);

        self::assertSame($code, $change->attributeCode);
        self::assertSame($expected, $change->value);
        self::assertSame($display, $change->displayValue);
    }

    public static function validValuesProvider(): array
    {
        return [
            'option label' => ['color', 'dark blue', '11', 'Dark Blue'],
            'option id' => ['color', '10', '10', 'Red'],
            'multiselect list' => ['tags', 'New, sale,2', '1,2', 'New, Sale'],
            'multiselect single value' => ['tags', 'Sale', '2', 'Sale'],
            'cleared multiselect' => ['tags', '', null, '(empty)'],
            'boolean id' => ['is_featured', '1', '1', 'Yes'],
            'boolean label' => ['is_featured', 'no', '0', 'No'],
            'boolean value' => ['is_featured', false, '0', 'No'],
            'boolean without source options' => ['no_index', '1', '1', 'Yes'],
            'number as the web API converted it' => ['price', '19.95', '19.95', '19.95'],
            'date in the Magento format' => [
                'special_to_date', '2026-12-31', '2026-12-31 00:00:00', '2026-12-31 00:00:00',
            ],
            'date in another format' => [
                'special_to_date', '31-12-2026', '2026-12-31 00:00:00', '2026-12-31 00:00:00',
            ],
            'cleared date' => ['special_to_date', '', null, '(empty)'],
            'no value clears' => ['description', null, null, '(empty)'],
            'text' => ['description', ' Hello ', 'Hello', 'Hello'],
            'text keeps its commas' => ['description', 'Red, White', 'Red, White', 'Red, White'],
        ];
    }

    public function testResolveSendsTheChangeThroughTheWebApiInputOfAProductSave(): void
    {
        $this->resolver->resolve('price', '19.95');
        $this->resolver->resolve('color', 'Red');

        self::assertSame(
            [
                [ProductRepositoryInterface::class, 'save', ['product' => ['price' => '19.95']]],
                [
                    ProductRepositoryInterface::class,
                    'save',
                    ['product' => ['custom_attributes' => [['attribute_code' => 'color', 'value' => '10']]]],
                ],
            ],
            $this->webApiPayloads
        );
    }

    #[DataProvider('invalidValuesProvider')]
    public function testResolveRejectsInvalidValue(mixed $code, mixed $value, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->resolver->resolve($code, $value);
    }

    public static function invalidValuesProvider(): array
    {
        return [
            'no attribute' => [null, 'x', 'Give the attribute_code'],
            'unknown attribute' => ['nope', 'x', 'Unknown product attribute "nope"'],
            'unknown option' => ['color', 'Green', '"Green" is not an option of "color"'],
            'boolean word that is no label' => ['is_featured', 'true', '"true" is not an option'],
            'unknown multiselect option' => ['tags', 'New, Old', '"Old" is not an option of "tags"'],
            'comma list for a dropdown' => ['color', 'Red,Dark Blue', '"Red,Dark Blue" is not an option'],
            'list value' => ['color', ['Red'], 'Give the value as text'],
            'refused by the web API input' => ['price', 'cheap', 'The "cheap" value\'s type is invalid'],
            'refused by the Magento validation' => ['weight', '5', 'Weight is not valid'],
            'cleared required attribute' => ['price', '', 'is required'],
            'invalid date' => ['special_to_date', 'no date', 'Attribute "special_to_date": Invalid date'],
            'static attribute' => ['sku', 'A', 'does not support a bulk update'],
            'unique attribute' => ['ean', '123', 'does not support a bulk update'],
            'media attribute' => ['image', 'a.jpg', 'does not support a bulk update'],
            'blocked code' => ['url_key', 'a', 'does not support a bulk update'],
            'attribute without permission' => ['page_layout', '1column', 'is not allowed to change'],
        ];
    }

    public function testResolveRunsTheDesignPermissionCheckOfMagentoForAnAdminUser(): void
    {
        $this->productAuthorization->expects(self::once())
            ->method('authorizeSavingOf')
            ->willThrowException(new AuthorizationException(__('Not allowed to edit the design attributes')));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Not allowed to edit the design attributes. This needs the Magento_Catalog::edit_product_design permission.'
        );

        $this->resolver->resolve('description', 'x', 7);
    }

    public function testResolveSkipsTheDesignPermissionCheckWithoutAnAdminUser(): void
    {
        $this->productAuthorization->expects(self::never())->method('authorizeSavingOf');

        self::assertSame('x', $this->resolver->resolve('description', 'x')->value);
    }

    private function processLikeTheWebApi(string $service, string $method, array $input): array
    {
        $this->webApiPayloads[] = [$service, $method, $input];
        $data = array_diff_key($input['product'], ['custom_attributes' => true]);
        foreach ($input['product']['custom_attributes'] ?? [] as $customAttribute) {
            $data[$customAttribute['attribute_code']] = $customAttribute['value'];
        }
        if (($data['price'] ?? null) === 'cheap') {
            throw new WebapiException(__('The "cheap" value\'s type is invalid. The "float" type was expected.'));
        }

        $product = $this->createMock(Product::class);
        $product->method('setData')->willReturnCallback(
            static function (string $key, mixed $value) use (&$data, $product): Product {
                $data[$key] = $value;

                return $product;
            }
        );
        $product->method('getData')->willReturnCallback(
            static function (string $key) use (&$data): mixed {
                return $data[$key] ?? null;
            }
        );

        return [$product];
    }

    private function createAttribute(
        string $code,
        string $input,
        array $options = [],
        bool $isRequired = false,
        bool $isUnique = false,
        bool $isStatic = false,
        bool $isDate = false,
        int $id = 1,
        ?string $backendError = null,
    ): Attribute {
        $source = $this->createMock(AbstractSource::class);
        $source->method('getAllOptions')->willReturn(array_map(
            static fn (int $value, string $label): array => ['value' => $value, 'label' => $label],
            array_keys($options),
            $options
        ));
        $backend = $isDate
            ? $this->getMockBuilder(Datetime::class)->disableOriginalConstructor()->onlyMethods(['validate'])->getMock()
            : $this->createMock(AbstractBackend::class);
        $backendError === null
            ? $backend->method('validate')->willReturn(true)
            : $backend->method('validate')->willThrowException(new LocalizedException(__($backendError)));

        $attribute = $this->createMock(Attribute::class);
        $attribute->method('getAttributeId')->willReturn($id);
        $attribute->method('getAttributeCode')->willReturn($code);
        $attribute->method('getFrontendInput')->willReturn($input);
        $attribute->method('getDefaultFrontendLabel')->willReturn(ucfirst($code));
        $attribute->method('getIsRequired')->willReturn($isRequired);
        $attribute->method('getIsUnique')->willReturn($isUnique);
        $attribute->method('isStatic')->willReturn($isStatic);
        $attribute->method('isScopeGlobal')->willReturn(true);
        $attribute->method('getApplyTo')->willReturn([]);
        $attribute->method('getSource')->willReturn($source);
        $attribute->method('getBackend')->willReturn($backend);

        return $attribute;
    }
}
