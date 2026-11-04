<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Controller\Adminhtml\Service;

require_once __DIR__ . '/../../../Stubs/RecordingLogger.php';
require_once __DIR__ . '/../../../Stubs/FixedConfigScopeResolver.php';
require_once __DIR__ . '/../../../Stubs/FakeFieldDescriptorFactory.php';

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use MageOS\AiBase\AiServices\Anthropic;
use MageOS\AiBase\AiServices\OpenAi;
use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\AiServiceInterface;
use MageOS\AiBase\Api\JsonFetcherInterface;
use MageOS\AiBase\Controller\Adminhtml\Service\RefreshModels;
use MageOS\AiBase\Model\Config\ConfigScope;
use MageOS\AiBase\Model\Config\ConfigScopeResolver;
use MageOS\AiBase\Model\FailureReporter;
use MageOS\AiBase\Model\ModelList\ModelOptions;
use MageOS\AiBase\Model\ModelList\Storage;
use MageOS\AiBase\Model\ServiceRegistry;
use MageOS\AiBase\Model\ServiceScope;
use MageOS\AiBase\Test\Unit\Stubs\FakeFieldDescriptorFactory;
use MageOS\AiBase\Test\Unit\Stubs\FixedConfigScopeResolver;
use MageOS\AiBase\Test\Unit\Stubs\RecordingLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MageOS\AiBase\Controller\Adminhtml\Service\RefreshModels
 *
 * Requires Magento\Backend classes (Backend\App\Action inheritance chain);
 * in the standalone module checkout run PHPUnit with a bootstrap that
 * autoloads the Magento\Backend module sources, otherwise the tests skip.
 */
final class RefreshModelsTest extends TestCase
{
    private RequestInterface&MockObject $request;
    private AiServiceSelectorInterface&MockObject $serviceSelector;
    private Storage&MockObject $storage;
    private OpenAi&MockObject $openAi;
    private RecordingLogger $logger;
    private ServiceScope $serviceScope;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $resultData = null;

    protected function setUp(): void
    {
        if (!class_exists(\Magento\Backend\App\Action::class)) {
            self::markTestSkipped('Magento\Backend is not available in this environment.');
        }

        $this->request = $this->createMock(RequestInterface::class);
        $this->serviceSelector = $this->createMock(AiServiceSelectorInterface::class);
        $this->storage = $this->createMock(Storage::class);

        $this->openAi = $this->createMock(OpenAi::class);
        $this->openAi->method('getCode')->willReturn('openai');
        $this->logger = new RecordingLogger();
        $this->serviceScope = new ServiceScope();
    }

    /**
     * Build the controller under test with the given registered service definitions.
     *
     * @param AiServiceConfigurationInterface[] $services
     * @param ConfigScopeResolver|null $scopeResolver The scope the config page sent; default when null
     * @return RefreshModels
     */
    private function createSubject(array $services, ?ConfigScopeResolver $scopeResolver = null): RefreshModels
    {
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($this->request);

        $json = $this->createMock(Json::class);
        $json->method('setData')->willReturnCallback(function (array $data) use ($json) {
            $this->resultData = $data;
            return $json;
        });
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($json);

        return new RefreshModels(
            $context,
            $jsonFactory,
            $this->serviceSelector,
            $this->storage,
            new ServiceRegistry($services),
            new FailureReporter($this->logger),
            $scopeResolver ?? FixedConfigScopeResolver::atDefault(),
            $this->serviceScope,
            new ModelOptions(),
        );
    }

    /**
     * @param array<string, string|null> $params
     * @return void
     */
    private function stubParams(array $params): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static fn (string $name) => $params[$name] ?? null
        );
    }

    public function test_execute_rejects_missing_service_code(): void
    {
        $this->stubParams([]);

        $this->createSubject([])->execute();

        self::assertFalse($this->resultData['success']);
        self::assertSame('service_code is required', $this->resultData['error']);
    }

    public function test_execute_rejects_service_without_model_list_support(): void
    {
        $this->stubParams(['service_code' => 'azure']);

        $azure = $this->createMock(AiServiceConfigurationInterface::class);
        $azure->method('getCode')->willReturn('azure');
        $configured = $this->createMock(AiServiceInterface::class);
        $configured->method('getCode')->willReturn('azure');
        $this->serviceSelector->method('getByCode')->with('azure')->willReturn([$configured]);
        $this->storage->expects(self::never())->method('saveForRow');

        $this->createSubject([$azure])->execute();

        self::assertFalse($this->resultData['success']);
        self::assertSame('Model list refresh is not supported for this service.', $this->resultData['error']);
    }

    public function test_execute_reports_missing_saved_configuration(): void
    {
        $this->stubParams(['service_code' => 'openai']);
        $this->serviceSelector->method('getByCode')->with('openai')->willReturn([]);
        $this->storage->expects(self::never())->method('saveForRow');

        $this->createSubject([$this->openAi])->execute();

        self::assertFalse($this->resultData['success']);
        self::assertSame('No AI service configured for code "openai".', $this->resultData['error']);
    }

    public function test_execute_fetches_persists_and_returns_model_map(): void
    {
        $this->stubParams(['service_code' => 'openai']);

        $configured = $this->createMock(AiServiceInterface::class);
        $configured->method('getCode')->willReturn('openai');
        $configured->method('getId')->willReturn('_first_openai_row');
        $configured->method('getConfiguration')->willReturn(['api_key' => 'sk-test', 'model' => 'gpt-4o']);
        $this->serviceSelector->method('getByCode')->with('openai')->willReturn([$configured]);

        $models = ['gpt-4o' => 'gpt-4o', 'o1' => 'o1'];
        $this->openAi->expects(self::once())->method('fetchModels')
            ->with(['api_key' => 'sk-test', 'model' => 'gpt-4o'])
            ->willReturn($models);
        $this->storage->expects(self::once())->method('saveForRow')
            ->with('_first_openai_row', $models, self::isInstanceOf(ConfigScope::class));

        $this->createSubject([$this->openAi])->execute();

        self::assertTrue($this->resultData['success']);
        self::assertSame(2, $this->resultData['count']);
        self::assertSame($models, $this->resultData['models']);
    }

    public function test_execute_returns_the_refreshed_options_with_the_providers_guidance(): void
    {
        $this->stubParams(['service_code' => 'anthropic']);
        $configured = $this->createMock(AiServiceInterface::class);
        $configured->method('getCode')->willReturn('anthropic');
        $configured->method('getId')->willReturn('_anthropic_row');
        $configured->method('getConfiguration')->willReturn(['api_key' => 'sk-ant-test']);
        $this->serviceSelector->method('getByCode')->with('anthropic')->willReturn([$configured]);
        $anthropic = new Anthropic(new FakeFieldDescriptorFactory(), new class implements JsonFetcherInterface {
            public function getJson(string $url, #[\SensitiveParameter] array $headers = []): array
            {
                return ['data' => [['id' => 'claude-sonnet-5-5', 'display_name' => 'Claude Sonnet 5.5']]];
            }
        });

        $this->createSubject([$anthropic])->execute();

        self::assertSame(
            [[
                'value' => 'claude-sonnet-5-5',
                'label' => 'Claude Sonnet 5.5 (recommended)',
                'tier' => 'balanced',
            ]],
            $this->resultData['options']
        );
    }

    public function test_execute_passes_localized_exception_message_through(): void
    {
        $this->stubParams(['service_code' => 'openai']);

        $configured = $this->createMock(AiServiceInterface::class);
        $configured->method('getCode')->willReturn('openai');
        $configured->method('getConfiguration')->willReturn(['api_key' => 'bad']);
        $this->serviceSelector->method('getByCode')->with('openai')->willReturn([$configured]);

        $this->openAi->method('fetchModels')
            ->willThrowException(new LocalizedException(__('Request to %1 returned HTTP status %2.', 'x', 401)));
        $this->storage->expects(self::never())->method('saveForRow');

        $this->createSubject([$this->openAi])->execute();

        self::assertFalse($this->resultData['success']);
        self::assertSame('Request to x returned HTTP status 401.', $this->resultData['error']);
    }

    /**
     * An untyped failure is the HTTP client's own text, which routinely names the request URL. It
     * goes to the log and the page gets told only that the refresh failed.
     */
    public function test_execute_keeps_an_untyped_failure_out_of_the_page_and_logs_it(): void
    {
        $this->stubParams(['service_code' => 'openai']);

        $configured = $this->createMock(AiServiceInterface::class);
        $configured->method('getCode')->willReturn('openai');
        $configured->method('getConfiguration')->willReturn([]);
        $this->serviceSelector->method('getByCode')->with('openai')->willReturn([$configured]);

        $this->openAi->method('fetchModels')->willThrowException(
            new \RuntimeException('boom at https://gw.example/v1/models?token=super-secret-value')
        );

        $this->createSubject([$this->openAi])->execute();

        self::assertFalse($this->resultData['success']);
        self::assertSame(
            'Model list refresh failed. The full error was written to the log.',
            $this->resultData['error']
        );
        self::assertStringContainsString('super-secret-value', $this->logger->getMessages());
        self::assertSame('openai', $this->logger->getRecords()[0]['context']['service_code']);
    }

    /**
     * Model lists are per provider, but the key that fetches one belongs to a row. Refreshing from
     * the first row of a code no matter which button was pressed reports another account's error
     * against the key the administrator is looking at.
     */
    public function test_execute_fetches_with_the_credentials_of_the_row_the_button_belongs_to(): void
    {
        $this->stubParams(['service_id' => '_second_openai_row', 'service_code' => 'openai']);

        $configured = $this->createMock(AiServiceInterface::class);
        $configured->method('getCode')->willReturn('openai');
        $configured->method('getConfiguration')->willReturn(['api_key' => 'key-of-the-second-row']);
        $this->serviceSelector->expects(self::once())->method('getById')
            ->with('_second_openai_row')->willReturn($configured);
        $this->serviceSelector->expects(self::never())->method('getByCode');

        $this->openAi->expects(self::once())->method('fetchModels')
            ->with(['api_key' => 'key-of-the-second-row'])
            ->willReturn(['gpt-4o' => 'GPT-4o']);

        $this->createSubject([$this->openAi])->execute();

        self::assertTrue($this->resultData['success']);
    }

    /**
     * Two rows of one provider pointing at different hosts serve different models, so the list is
     * stored for the row whose button was pressed, not for the provider code.
     */
    public function test_execute_stores_the_list_for_the_row_it_was_fetched_for(): void
    {
        $this->stubParams(['service_id' => '_ollama_host_b', 'service_code' => 'openai']);
        $configured = $this->createMock(AiServiceInterface::class);
        $configured->method('getCode')->willReturn('openai');
        $configured->method('getId')->willReturn('_ollama_host_b');
        $configured->method('getConfiguration')->willReturn([]);
        $this->serviceSelector->method('getById')->willReturn($configured);
        $this->openAi->method('fetchModels')->willReturn(['qwen3' => 'qwen3']);
        $stored = [];
        $this->storage->method('saveForRow')->willReturnCallback(
            static function (string $rowId, array $models) use (&$stored): void {
                $stored[$rowId] = $models;
            }
        );

        $this->createSubject([$this->openAi])->execute();

        self::assertSame(['_ollama_host_b' => ['qwen3' => 'qwen3']], $stored);
    }

    /**
     * On a website's config page the row is read, and its list stored, at that website: a row that
     * only exists there must be found, and one the website overrides must use its own credentials.
     */
    public function test_execute_reads_the_row_and_stores_its_list_at_the_scope_of_the_config_page(): void
    {
        $this->stubParams(['service_id' => '_website_row', 'service_code' => 'openai']);
        $website = new ConfigScope('websites', 2, 'second');
        $scopeWhileReading = null;
        $configured = $this->createMock(AiServiceInterface::class);
        $configured->method('getCode')->willReturn('openai');
        $configured->method('getId')->willReturn('_website_row');
        $configured->method('getConfiguration')->willReturn([]);
        $this->serviceSelector->method('getById')->willReturnCallback(
            function () use (&$scopeWhileReading, $configured) {
                $scopeWhileReading = $this->serviceScope->getCurrent();

                return $configured;
            }
        );
        $this->openAi->method('fetchModels')->willReturn([]);
        $storedAt = null;
        $this->storage->method('saveForRow')->willReturnCallback(
            static function (string $rowId, array $models, ConfigScope $scope) use (&$storedAt): void {
                $storedAt = $scope;
            }
        );

        $this->createSubject([$this->openAi], new FixedConfigScopeResolver($website))->execute();

        self::assertTrue($this->resultData['success']);
        self::assertSame($website, $scopeWhileReading);
        self::assertSame($website, $storedAt);
        self::assertNull($this->serviceScope->getCurrent());
    }

    /**
     * The provider decides which host the row's key is sent to, and the row decides which key. A
     * request naming one row by id and another provider by code would send that row's key to the
     * other provider's host, so a code that disagrees with the row stops the refresh outright.
     */
    public function test_execute_refuses_a_posted_code_that_differs_from_the_row_and_fetches_nothing(): void
    {
        $this->stubParams(['service_id' => '_ollama_row', 'service_code' => 'openai']);
        $configured = $this->createMock(AiServiceInterface::class);
        $configured->method('getCode')->willReturn('ollama');
        $configured->method('getConfiguration')->willReturn(['api_key' => 'key-of-the-ollama-row']);
        $this->serviceSelector->method('getById')->with('_ollama_row')->willReturn($configured);
        $this->openAi->expects(self::never())->method('fetchModels');
        $this->storage->expects(self::never())->method('saveForRow');

        $this->createSubject([$this->openAi])->execute();

        self::assertFalse($this->resultData['success']);
        self::assertSame(
            'This row is stored as a "ollama" service, not "openai", so its models were not refreshed. '
            . 'Reload the page and try again.',
            $this->resultData['error']
        );
    }

    /**
     * With only a row id the provider comes from the row, so a caller cannot pick it at all.
     */
    public function test_execute_takes_the_provider_from_the_row_when_no_code_is_posted(): void
    {
        $this->stubParams(['service_id' => '_openai_row']);
        $configured = $this->createMock(AiServiceInterface::class);
        $configured->method('getCode')->willReturn('openai');
        $configured->method('getId')->willReturn('_openai_row');
        $configured->method('getConfiguration')->willReturn(['api_key' => 'sk-row']);
        $this->serviceSelector->method('getById')->willReturn($configured);
        $this->openAi->expects(self::once())->method('fetchModels')
            ->with(['api_key' => 'sk-row'])
            ->willReturn(['gpt-4o' => 'gpt-4o']);

        $this->createSubject([$this->openAi])->execute();

        self::assertTrue($this->resultData['success']);
    }

    public function test_execute_reports_a_row_id_that_does_not_exist(): void
    {
        $this->stubParams(['service_id' => '_gone', 'service_code' => 'openai']);
        $this->serviceSelector->method('getById')->with('_gone')->willReturn(null);
        $this->openAi->expects(self::never())->method('fetchModels');

        $this->createSubject([$this->openAi])->execute();

        self::assertFalse($this->resultData['success']);
        self::assertSame('No AI service configured with id "_gone".', $this->resultData['error']);
    }
}
