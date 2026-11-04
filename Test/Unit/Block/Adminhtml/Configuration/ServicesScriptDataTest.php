<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Block\Adminhtml\Configuration;

require_once __DIR__ . '/../../../Stubs/FixedConfigScopeResolver.php';

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\DataObject;
use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterface;
use MageOS\AiBase\Block\Adminhtml\Configuration\Services;
use MageOS\AiBase\Model\Config\ConfigScope;
use MageOS\AiBase\Model\FieldDescriptor;
use MageOS\AiBase\Model\ModelList\Resolver;
use MageOS\AiBase\Test\Unit\Stubs\FixedConfigScopeResolver;
use MageOS\AiBase\Model\ServiceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * What the block hands the template's inline script: encoded so the HTML parser cannot be steered
 * by it, and per row where the data belongs to a row.
 *
 * A stored value or a provider-returned model name containing `<!--<script>` used to put the HTML
 * parser into the script-data double-escaped state. The real `</script>` was swallowed, the script
 * never ran, no rows rendered, and the next Save Config stored an empty list.
 */
final class ServicesScriptDataTest extends TestCase
{
    private const HOSTILE = '<!--<script>"\'&</script>';

    private Resolver&MockObject $modelListResolver;

    protected function setUp(): void
    {
        if (!class_exists(AbstractFieldArray::class)) {
            self::markTestSkipped('magento/module-config is not installed in this environment.');
        }

        $this->modelListResolver = $this->createMock(Resolver::class);
    }

    /**
     * @param string $character
     */
    #[DataProvider('characters_the_html_parser_reacts_to')]
    public function test_encoded_values_carry_no_character_the_html_parser_reacts_to(string $character): void
    {
        $encoded = $this->block()->encodeForScript(['model' => self::HOSTILE, self::HOSTILE => 'key']);

        self::assertStringNotContainsString($character, $encoded);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function characters_the_html_parser_reacts_to(): array
    {
        return [
            'less than' => ['<'],
            'greater than' => ['>'],
            'ampersand' => ['&'],
            'single quote' => ["'"],
        ];
    }

    /**
     * The escapes are JSON string escapes, so the script reads exactly the stored value back.
     */
    public function test_encoded_values_decode_back_unchanged(): void
    {
        $value = ['model' => self::HOSTILE, 'nested' => ['a' => 1, 'b' => null]];

        self::assertSame($value, json_decode($this->block()->encodeForScript($value), true));
    }

    public function test_a_hostile_model_name_from_refresh_models_cannot_close_the_schema_script(): void
    {
        $this->modelListResolver->method('getModels')->willReturn([self::HOSTILE => self::HOSTILE]);

        $json = $this->block([$this->service('ollama')])->getServicesSchemaJson();

        self::assertStringNotContainsString('<', $json);
        self::assertSame(
            [['value' => self::HOSTILE, 'label' => self::HOSTILE]],
            json_decode($json, true)['ollama']['fields'][0]['options']
        );
    }

    /**
     * Two rows of one self-hosted provider on different hosts serve different models, so each row
     * is handed the list refreshed for it rather than one list per provider.
     */
    public function test_each_stored_row_gets_its_own_model_list(): void
    {
        $this->modelListResolver->method('getModelsForRow')->willReturnCallback(
            static fn (AiServiceConfigurationInterface $service, string $rowId): array => [
                'model-of' . $rowId => 'Model of ' . $rowId,
            ]
        );
        $block = $this->blockWithRows([
            '_host_a' => ['ollama' => ['base_url' => 'http://a:11434']],
            '_host_b' => ['ollama' => ['base_url' => 'http://b:11434']],
        ]);

        $options = array_column($block->getStoredRows(), 'modelOptions', 'id');

        self::assertSame(
            [
                '_host_a' => [['value' => 'model-of_host_a', 'label' => 'Model of _host_a']],
                '_host_b' => [['value' => 'model-of_host_b', 'label' => 'Model of _host_b']],
            ],
            $options
        );
    }

    /**
     * The row's own list is looked up at the scope being edited, where a website that overrides the
     * services keeps lists of its own.
     */
    public function test_a_row_model_list_is_resolved_at_the_scope_being_edited(): void
    {
        $scopes = [];
        $this->modelListResolver->method('getModelsForRow')->willReturnCallback(
            static function (AiServiceConfigurationInterface $service, string $rowId, ConfigScope $scope) use (&$scopes) {
                $scopes[] = $scope->getCode();

                return [];
            }
        );

        $this->blockWithRows(['_row' => ['ollama' => []]], new ConfigScope('websites', 2, 'second'))
            ->getStoredRows();

        self::assertSame(['second'], $scopes);
    }

    /**
     * A row whose provider module was removed is handed to the script flagged unregistered, so the
     * form renders a placeholder for it instead of leaving it out without a word (GitHub issue #65).
     */
    public function test_a_row_of_an_unregistered_provider_is_handed_over_flagged_and_without_a_model_list(): void
    {
        $rows = $this->blockWithRows(['_row' => ['retired_provider' => ['model' => 'x']]])->getStoredRows();

        self::assertSame(
            [[
                'code' => 'retired_provider',
                'id' => '_row',
                'label' => '',
                'registered' => false,
                'values' => ['model' => 'x'],
                'modelOptions' => null,
            ]],
            $rows
        );
    }

    public function test_a_row_of_a_registered_provider_is_flagged_registered(): void
    {
        $this->modelListResolver->method('getModelsForRow')->willReturn([]);

        $rows = $this->blockWithRows(['_row' => ['ollama' => ['model' => 'llama3']]])->getStoredRows();

        self::assertTrue($rows[0]['registered']);
    }

    /**
     * The placeholder names the row by what the administrator called it. The block hands the label
     * over untouched; the template escapes it for the script and the script for the markup, which
     * `ServicesTemplateEscapingTest` pins.
     */
    public function test_an_unregistered_row_carries_its_label_unchanged(): void
    {
        $rows = $this->blockWithRows([
            '_row' => ['retired_provider' => ['_label' => self::HOSTILE, 'api_key' => '******']],
        ])->getStoredRows();

        self::assertSame(self::HOSTILE, $rows[0]['label']);
    }

    public function test_a_label_that_is_not_a_string_is_treated_as_no_label(): void
    {
        $rows = $this->blockWithRows(['_row' => ['retired_provider' => ['_label' => ['nested']]]])->getStoredRows();

        self::assertSame('', $rows[0]['label']);
    }

    /**
     * A row the script cannot render is left out instead of being passed on to fail the script.
     */
    public function test_a_row_whose_configuration_is_not_an_array_is_left_out(): void
    {
        $this->modelListResolver->method('getModelsForRow')->willReturn([]);

        $rows = $this->blockWithRows([
            '_broken' => ['ollama' => 'not an array'],
            '_fine' => ['ollama' => ['model' => 'llama3']],
        ])->getStoredRows();

        self::assertSame(['_fine'], array_column($rows, 'id'));
    }

    /**
     * @param array<string, mixed> $rows
     */
    private function blockWithRows(array $rows, ?ConfigScope $scope = null): Services
    {
        $block = $this->block([$this->service('ollama')], $scope);
        $block->setData('element', new DataObject(['value' => $rows]));

        return $block;
    }

    private function service(string $code): AiServiceConfigurationInterface
    {
        return new class ($code) implements AiServiceConfigurationInterface {
            public function __construct(private readonly string $code)
            {
            }

            /**
             * @inheritdoc
             */
            public function getCode(): string
            {
                return $this->code;
            }

            /**
             * @inheritdoc
             */
            public function getName(): string
            {
                return ucfirst($this->code);
            }

            /**
             * @inheritdoc
             */
            public function getConfigurationFields(): array
            {
                return [new FieldDescriptor('model', 'Model', FieldDescriptorInterface::TYPE_TEXT)];
            }

            /**
             * @inheritdoc
             */
            public function getSupportedModels(): array
            {
                return [];
            }
        };
    }

    /**
     * Build the block without running its constructor, which needs the whole backend context.
     *
     * @param array<int, AiServiceConfigurationInterface> $services
     */
    private function block(array $services = [], ?ConfigScope $scope = null): Services
    {
        $reflection = new \ReflectionClass(Services::class);
        $block = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('serviceRegistry')->setValue($block, new ServiceRegistry($services));
        $reflection->getProperty('modelListResolver')->setValue($block, $this->modelListResolver);
        $reflection->getProperty('modelOptions')->setValue($block, new \MageOS\AiBase\Model\ModelList\ModelOptions());
        $reflection->getProperty('scopeResolver')->setValue(
            $block,
            $scope === null ? FixedConfigScopeResolver::atDefault() : new FixedConfigScopeResolver($scope)
        );

        return $block;
    }
}
