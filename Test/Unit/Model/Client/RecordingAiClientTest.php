<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Client;

require_once __DIR__ . '/../../Stubs/RecordingLogger.php';

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreExtensionInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AiBase\Api\AiClientInterface;
use MageOS\AiBase\Api\Data\ChatRequestInterface;
use MageOS\AiBase\Api\Data\ChatResponseInterface;
use MageOS\AiBase\Api\Data\MessageRole;
use MageOS\AiBase\Api\Data\StreamChunkInterface;
use MageOS\AiBase\Api\Data\StreamChunkType;
use MageOS\AiBase\Api\Data\UsageRecordInterface;
use MageOS\AiBase\Api\PlatformAwareInterface;
use MageOS\AiBase\Api\UsageRecordRepositoryInterface;
use MageOS\AiBase\Model\Chat\ChatMessage;
use MageOS\AiBase\Model\Chat\ChatRequest;
use MageOS\AiBase\Model\Chat\ChatResponse;
use MageOS\AiBase\Model\Chat\StreamChunk;
use MageOS\AiBase\Model\Chat\TokenUsage;
use MageOS\AiBase\Exceptions\AiRequestNotSentException;
use MageOS\AiBase\Model\Client\RecordingAiClient;
use MageOS\AiBase\Model\Client\RecordingPlatformAwareAiClient;
use MageOS\AiBase\Model\Usage\UsageStoreResolver;
use PHPUnit\Framework\Attributes\TestWith;
use MageOS\AiBase\Model\Pricing\CostCalculator;
use MageOS\AiBase\Model\Pricing\CostEstimator;
use MageOS\AiBase\Model\Pricing\ModelPrice;
use MageOS\AiBase\Model\Pricing\PriceCatalog;
use MageOS\AiBase\Model\Pricing\PriceQuote;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use MageOS\AiBase\Test\Unit\Stubs\RecordingLogger;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;

/**
 * @covers \MageOS\AiBase\Model\Client\RecordingAiClient
 * @covers \MageOS\AiBase\Model\Client\RecordingPlatformAwareAiClient
 *
 * @covers \MageOS\AiBase\Model\Usage\UsageStoreResolver
 *
 * Exercises the decorators against {@see FakeAiClient}, {@see FakeUsageRecordRepository},
 * {@see FakeStoreManager}, {@see FakeAppState} and {@see FakeLogger}, in-memory stand-ins for the
 * collaborators per this codebase's fakes-over-mocks convention, kept next to the test that uses
 * them. The store attribution rule is driven through the real {@see UsageStoreResolver}, since
 * which store a row lands under is only observable on the row the client records.
 */
final class RecordingAiClientTest extends TestCase
{
    private FakeUsageRecordRepository $repository;

    private FakeLogger $logger;

    private RecordingLogger $storeResolverLogger;

    protected function setUp(): void
    {
        $this->repository = new FakeUsageRecordRepository();
        $this->logger = new FakeLogger();
        $this->storeResolverLogger = new RecordingLogger();
    }

    public function test_it_returns_the_wrapped_client_response_unchanged_from_chat(): void
    {
        $response = new ChatResponse('Hi there', [], new TokenUsage(10, 5));
        $delegate = new FakeAiClient(chatResponse: $response);

        $result = $this->subject($delegate)->chat($this->request());

        self::assertSame($response, $result);
    }

    public function test_it_records_one_usage_row_for_a_buffered_chat_call(): void
    {
        $delegate = new FakeAiClient(chatResponse: new ChatResponse('Hi', [], new TokenUsage(10, 5)));

        $this->subject($delegate)->chat($this->request());

        self::assertCount(1, $this->repository->getSavedRecords());
    }

    public function test_it_records_the_service_id_and_service_code_from_the_wrapped_client(): void
    {
        $delegate = new FakeAiClient(
            chatResponse: new ChatResponse('Hi', [], new TokenUsage(10, 5)),
            serviceCode: 'openai',
            serviceId: '_row9',
        );

        $this->subject($delegate)->chat($this->request());

        $saved = $this->repository->getSavedRecords()[0];
        self::assertSame('openai', $saved->getServiceCode());
        self::assertSame('_row9', $saved->getServiceId());
    }

    public function test_it_records_the_configured_model_when_the_call_named_none(): void
    {
        $delegate = new FakeAiClient(
            chatResponse: new ChatResponse('Hi', [], new TokenUsage(10, 5)),
            model: 'claude-sonnet',
        );

        $this->subject($delegate)->chat($this->request());

        self::assertSame('claude-sonnet', $this->repository->getSavedRecords()[0]->getModel());
    }

    public function test_it_records_the_model_the_call_overrode_with_the_model_option(): void
    {
        $delegate = new FakeAiClient(
            chatResponse: new ChatResponse('Hi', [], new TokenUsage(10, 5)),
            model: 'claude-sonnet',
        );

        $this->subject($delegate)->chat(
            $this->request(),
            [AiClientInterface::OPTION_MODEL => 'gpt-4o-mini'],
        );

        self::assertSame('gpt-4o-mini', $this->repository->getSavedRecords()[0]->getModel());
    }

    public function test_it_records_the_consumer_named_on_the_individual_call_over_the_client_level_one(): void
    {
        $delegate = new FakeAiClient(
            chatResponse: new ChatResponse('Hi', [], new TokenUsage(10, 5)),
            consumer: 'client_level',
        );

        $this->subject($delegate)->chat(
            $this->request(),
            [AiClientInterface::OPTION_CONSUMER => 'per_call'],
        );

        self::assertSame('per_call', $this->repository->getSavedRecords()[0]->getConsumer());
    }

    public function test_it_records_the_unknown_consumer_when_neither_the_call_nor_the_client_named_one(): void
    {
        $delegate = new FakeAiClient(
            chatResponse: new ChatResponse('Hi', [], new TokenUsage(10, 5)),
            consumer: UsageRecordInterface::CONSUMER_UNKNOWN,
        );

        $this->subject($delegate)->chat($this->request());

        self::assertSame(
            UsageRecordInterface::CONSUMER_UNKNOWN,
            $this->repository->getSavedRecords()[0]->getConsumer(),
        );
    }

    public function test_it_does_not_pass_the_consumer_option_on_to_the_wrapped_client(): void
    {
        $delegate = new FakeAiClient(chatResponse: new ChatResponse('Hi', [], new TokenUsage(10, 5)));

        $this->subject($delegate)->chat(
            $this->request(),
            [AiClientInterface::OPTION_CONSUMER => 'chat_widget'],
        );

        self::assertArrayNotHasKey(AiClientInterface::OPTION_CONSUMER, $delegate->chatCalls[0]['options']);
    }

    public function test_it_records_the_admin_store_when_there_is_no_current_store(): void
    {
        $delegate = new FakeAiClient(chatResponse: new ChatResponse('Hi', [], new TokenUsage(10, 5)));

        $this->subject($delegate, new FakeStoreManager(null))->chat($this->request());

        self::assertSame(0, $this->repository->getSavedRecords()[0]->getStoreId());
    }

    #[TestWith([Area::AREA_FRONTEND])]
    #[TestWith([Area::AREA_WEBAPI_REST])]
    #[TestWith([Area::AREA_WEBAPI_SOAP])]
    #[TestWith([Area::AREA_GRAPHQL])]
    public function test_it_records_the_current_store_in_a_storefront_area(string $areaCode): void
    {
        $this->subject($this->succeedingDelegate(), new FakeStoreManager(3, 1), new FakeAppState($areaCode))
            ->chat($this->request());

        self::assertSame(3, $this->repository->getSavedRecords()[0]->getStoreId());
    }

    public function test_it_records_the_default_store_view_for_a_storefront_request_made_in_it(): void
    {
        $this->subject($this->succeedingDelegate(), new FakeStoreManager(1, 1), new FakeAppState(Area::AREA_FRONTEND))
            ->chat($this->request());

        self::assertSame(1, $this->repository->getSavedRecords()[0]->getStoreId());
    }

    #[TestWith([Area::AREA_CRONTAB])]
    #[TestWith([Area::AREA_ADMINHTML])]
    public function test_it_records_the_admin_store_outside_a_storefront_when_the_current_store_is_the_default_store_view(
        string $areaCode
    ): void {
        // Cron and CLI resolve the default store view as the current store although nothing chose
        // it; recording it would put all of that spend on the default store view.
        $this->subject($this->succeedingDelegate(), new FakeStoreManager(1, 1), new FakeAppState($areaCode))
            ->chat($this->request());

        self::assertSame(0, $this->repository->getSavedRecords()[0]->getStoreId());
    }

    public function test_it_records_the_admin_store_when_no_area_code_is_set(): void
    {
        $this->subject($this->succeedingDelegate(), new FakeStoreManager(1, 1), new FakeAppState(null))
            ->chat($this->request());

        self::assertSame(0, $this->repository->getSavedRecords()[0]->getStoreId());
    }

    public function test_it_records_the_store_a_cron_job_emulates_when_it_is_not_the_default_store_view(): void
    {
        $this->subject($this->succeedingDelegate(), new FakeStoreManager(2, 1), new FakeAppState(Area::AREA_CRONTAB))
            ->chat($this->request());

        self::assertSame(2, $this->repository->getSavedRecords()[0]->getStoreId());
    }

    public function test_it_records_the_admin_store_and_still_answers_when_the_default_store_view_cannot_be_resolved(): void
    {
        $storeManager = new FakeStoreManager(2, 1);
        $storeManager->givenDefaultStoreViewLookupFails(new \RuntimeException('store table unavailable'));

        $response = $this->subject($this->succeedingDelegate(), $storeManager, new FakeAppState(Area::AREA_CRONTAB))
            ->chat($this->request());

        self::assertSame('Hi', $response->getText());
        self::assertSame(0, $this->repository->getSavedRecords()[0]->getStoreId());
    }

    public function test_it_logs_a_store_lookup_that_failed_so_the_store_0_row_can_be_traced(): void
    {
        $storeManager = new FakeStoreManager(2, 1);
        $storeManager->givenDefaultStoreViewLookupFails(new \RuntimeException('store table unavailable'));

        $this->subject($this->succeedingDelegate(), $storeManager, new FakeAppState(Area::AREA_CRONTAB))
            ->chat($this->request());

        self::assertSame('warning', $this->storeResolverLogger->getRecords()[0]['level']);
        self::assertStringContainsString('store table unavailable', $this->storeResolverLogger->getMessages());
    }

    public function test_it_logs_nothing_when_the_store_resolves(): void
    {
        $this->subject($this->succeedingDelegate(), new FakeStoreManager(2, 1), new FakeAppState(Area::AREA_CRONTAB))
            ->chat($this->request());

        self::assertSame([], $this->storeResolverLogger->getRecords());
    }

    public function test_it_records_cached_and_reasoning_token_counts_when_the_response_carries_them(): void
    {
        $usage = new TokenUsage(100, 50, null, 20, 10);
        $delegate = new FakeAiClient(chatResponse: new ChatResponse('Hi', [], $usage));

        $this->subject($delegate)->chat($this->request());

        $saved = $this->repository->getSavedRecords()[0];
        self::assertSame(20, $saved->getCacheReadTokens());
        self::assertSame(10, $saved->getReasoningTokens());
    }

    public function test_it_records_cache_read_and_write_tokens(): void
    {
        $usage = new TokenUsage(100, 50, null, 20, 10, 5);
        $delegate = new FakeAiClient(chatResponse: new ChatResponse('Hi', [], $usage));

        $this->subject($delegate)->chat($this->request());

        $saved = $this->repository->getSavedRecords()[0];
        self::assertSame(20, $saved->getCacheReadTokens());
        self::assertSame(5, $saved->getCacheWriteTokens());
    }

    public function test_it_records_a_successful_call_without_usage_as_a_row_with_null_tokens(): void
    {
        $delegate = new FakeAiClient(chatResponse: new ChatResponse('Hi'));

        $this->subject($delegate)->chat($this->request());

        $saved = $this->repository->getSavedRecords();
        self::assertCount(1, $saved);
        self::assertFalse($saved[0]->isFailed());
        self::assertNull($saved[0]->getInputTokens());
        self::assertNull($saved[0]->getOutputTokens());
        self::assertNull($saved[0]->getTotalTokens());
    }

    public function test_it_records_a_failed_chat_call_with_null_tokens(): void
    {
        $delegate = new FakeAiClient();
        $delegate->givenChatThrows(new LocalizedException(__('boom')));

        try {
            $this->subject($delegate)->chat($this->request());
            self::fail('Expected the wrapped exception to propagate.');
        } catch (LocalizedException) {
        }

        $saved = $this->repository->getSavedRecords();
        self::assertCount(1, $saved);
        self::assertTrue($saved[0]->isFailed());
        self::assertNull($saved[0]->getInputTokens());
        self::assertNull($saved[0]->getOutputTokens());
        self::assertNull($saved[0]->getTotalTokens());
    }

    public function test_it_rethrows_the_original_exception_from_a_failed_chat_call(): void
    {
        $delegate = new FakeAiClient();
        $exception = new LocalizedException(__('boom'));
        $delegate->givenChatThrows($exception);

        try {
            $this->subject($delegate)->chat($this->request());
            self::fail('Expected the wrapped exception to propagate.');
        } catch (LocalizedException $caught) {
            self::assertSame($exception, $caught);
        }
    }

    public function test_it_records_nothing_when_the_request_was_never_sent(): void
    {
        $delegate = new FakeAiClient();
        $delegate->givenChatThrows(new AiRequestNotSentException(__('unsupported option')));

        try {
            $this->subject($delegate)->chat($this->request());
            self::fail('Expected the exception to propagate.');
        } catch (AiRequestNotSentException) {
        }

        self::assertCount(0, $this->repository->getSavedRecords());
    }

    public function test_it_rethrows_the_request_not_sent_exception_unchanged(): void
    {
        $delegate = new FakeAiClient();
        $exception = new AiRequestNotSentException(__('unsupported option'));
        $delegate->givenChatThrows($exception);

        try {
            $this->subject($delegate)->chat($this->request());
            self::fail('Expected the exception to propagate.');
        } catch (AiRequestNotSentException $caught) {
            self::assertSame($exception, $caught);
        }
    }

    public function test_it_yields_every_stream_chunk_the_wrapped_client_produced(): void
    {
        $chunks = [
            new StreamChunk(StreamChunkType::Text, 'Hi'),
            new StreamChunk(StreamChunkType::Text, ' there'),
        ];
        $delegate = new FakeAiClient();
        $delegate->givenStreamChunks(...$chunks);

        $seen = [];
        foreach ($this->subject($delegate)->streamChat($this->request()) as $chunk) {
            $seen[] = $chunk;
        }

        self::assertSame($chunks, $seen);
    }

    public function test_it_returns_the_wrapped_turn_from_the_finished_stream_generator(): void
    {
        $turn = new ChatResponse('Hi there');
        $delegate = new FakeAiClient();
        $delegate->givenStreamChunks(new StreamChunk(StreamChunkType::Text, 'Hi there'));
        $delegate->givenStreamReturn($turn);

        $stream = $this->subject($delegate)->streamChat($this->request());
        foreach ($stream as $chunk) {
        }

        self::assertSame($turn, $stream->getReturn());
    }

    public function test_it_records_one_usage_row_marked_as_streamed_when_the_stream_finishes(): void
    {
        $delegate = new FakeAiClient();
        $delegate->givenStreamChunks(
            new StreamChunk(StreamChunkType::Text, 'Hi'),
            new StreamChunk(StreamChunkType::Usage, '', null, new TokenUsage(10, 5)),
        );

        $stream = $this->subject($delegate)->streamChat($this->request());
        foreach ($stream as $chunk) {
        }

        $saved = $this->repository->getSavedRecords();
        self::assertCount(1, $saved);
        self::assertTrue($saved[0]->isStreamed());
    }

    public function test_it_records_a_failed_stream_with_the_usage_seen_before_the_error(): void
    {
        $delegate = new FakeAiClient();
        $delegate->givenStreamFailsAfter(
            [
                new StreamChunk(StreamChunkType::Text, 'Hi'),
                new StreamChunk(StreamChunkType::Usage, '', null, new TokenUsage(10, 5)),
            ],
            new LocalizedException(__('boom')),
        );

        try {
            foreach ($this->subject($delegate)->streamChat($this->request()) as $chunk) {
            }
            self::fail('Expected the wrapped exception to propagate.');
        } catch (LocalizedException) {
        }

        $saved = $this->repository->getSavedRecords();
        self::assertCount(1, $saved);
        self::assertTrue($saved[0]->isFailed());
        self::assertSame(10, $saved[0]->getInputTokens());
    }

    public function test_it_rethrows_the_original_exception_from_a_failed_stream(): void
    {
        $delegate = new FakeAiClient();
        $exception = new LocalizedException(__('boom'));
        $delegate->givenStreamFailsAfter([], $exception);

        try {
            foreach ($this->subject($delegate)->streamChat($this->request()) as $chunk) {
            }
            self::fail('Expected the wrapped exception to propagate.');
        } catch (LocalizedException $caught) {
            self::assertSame($exception, $caught);
        }
    }

    public function test_it_records_the_usage_seen_so_far_when_the_caller_abandons_the_stream_after_a_usage_chunk(): void
    {
        $delegate = new FakeAiClient();
        $delegate->givenStreamChunks(
            new StreamChunk(StreamChunkType::Text, 'Hi'),
            new StreamChunk(StreamChunkType::Usage, '', null, new TokenUsage(10, 5)),
            new StreamChunk(StreamChunkType::Text, 'more'),
        );

        $stream = $this->subject($delegate)->streamChat($this->request());
        foreach ($stream as $chunk) {
            if ($chunk->getType() === StreamChunkType::Usage) {
                break;
            }
        }
        unset($stream);

        $saved = $this->repository->getSavedRecords();
        self::assertCount(1, $saved);
        self::assertSame(10, $saved[0]->getInputTokens());
    }

    public function test_it_records_an_abandoned_stream_as_not_failed(): void
    {
        $delegate = new FakeAiClient();
        $delegate->givenStreamChunks(
            new StreamChunk(StreamChunkType::Text, 'Hi'),
            new StreamChunk(StreamChunkType::Usage, '', null, new TokenUsage(10, 5)),
        );

        $stream = $this->subject($delegate)->streamChat($this->request());
        foreach ($stream as $chunk) {
            break;
        }
        unset($stream);

        $saved = $this->repository->getSavedRecords();
        self::assertCount(1, $saved);
        self::assertFalse($saved[0]->isFailed());
        self::assertNull($saved[0]->getInputTokens());
    }

    public function test_it_records_exactly_one_row_for_a_complete_call(): void
    {
        $delegate = new FakeAiClient(chatResponse: new ChatResponse('Hi', [], new TokenUsage(10, 5)));

        $this->subject($delegate)->complete('Hello');

        self::assertCount(1, $this->repository->getSavedRecords());
    }

    public function test_it_returns_the_wrapped_client_text_unchanged_from_complete(): void
    {
        $delegate = new FakeAiClient(chatResponse: new ChatResponse('Hi there', [], new TokenUsage(10, 5)));

        $result = $this->subject($delegate)->complete('Hello');

        self::assertSame('Hi there', $result);
    }

    public function test_it_returns_the_wrapped_platform_untouched_from_get_platform(): void
    {
        $platform = MockPlatformFactory::createPlatform('Hi');
        $delegate = new FakePlatformAwareAiClient($platform);

        $subject = new RecordingPlatformAwareAiClient(
            $delegate,
            $this->repository,
            new UsageStoreResolver(new FakeStoreManager(1), new FakeAppState(Area::AREA_FRONTEND), $this->storeResolverLogger),
            $this->logger,
        );

        self::assertSame($platform, $subject->getPlatform());
    }

    public function test_it_forwards_option_normalisation_to_the_wrapped_client(): void
    {
        $delegate = new FakePlatformAwareAiClient(MockPlatformFactory::createPlatform('Hi'));

        $subject = new RecordingPlatformAwareAiClient(
            $delegate,
            $this->repository,
            new UsageStoreResolver(new FakeStoreManager(1), new FakeAppState(Area::AREA_FRONTEND), $this->storeResolverLogger),
            $this->logger,
        );

        self::assertSame(['normalized' => true, 'max_tokens' => 400], $subject->normalizeOptions(['max_tokens' => 400]));
    }

    public function test_it_is_not_platform_aware_when_the_wrapped_client_is_not(): void
    {
        $subject = $this->subject(new FakeAiClient());

        self::assertNotInstanceOf(PlatformAwareInterface::class, $subject);
    }

    public function test_it_logs_and_swallows_a_repository_failure_without_breaking_the_call(): void
    {
        $this->repository->givenSaveFails(new \RuntimeException('database is down'));
        $delegate = new FakeAiClient(chatResponse: new ChatResponse('Hi there', [], new TokenUsage(10, 5)));

        $result = $this->subject($delegate)->chat($this->request());

        self::assertSame('Hi there', $result->getText());
        self::assertCount(1, $this->logger->getRecords());
    }

    public function test_it_swallows_a_repository_failure_raised_while_recording_an_abandoned_stream(): void
    {
        $this->repository->givenSaveFails(new \RuntimeException('database is down'));
        $delegate = new FakeAiClient();
        $delegate->givenStreamChunks(
            new StreamChunk(StreamChunkType::Text, 'Hi'),
            new StreamChunk(StreamChunkType::Usage, '', null, new TokenUsage(10, 5)),
        );

        $stream = $this->subject($delegate)->streamChat($this->request());
        foreach ($stream as $chunk) {
            if ($chunk->getType() === StreamChunkType::Usage) {
                break;
            }
        }
        unset($stream);

        self::assertCount(1, $this->logger->getRecords());
    }

    public function test_it_records_the_estimated_cost_and_the_snapshot_it_came_from(): void
    {
        $delegate = new FakeAiClient(
            chatResponse: new ChatResponse('Hi', [], new TokenUsage(1000, 500, 1500)),
            serviceCode: 'openai',
            model: 'gpt-4o',
        );

        $this->subjectWithPrice($delegate, new ModelPrice(0.000002, 0.00001))->chat($this->request());

        $saved = $this->repository->getSavedRecords()[0];
        self::assertEqualsWithDelta(0.007, $saved->getCost(), 1e-12);
        self::assertSame('USD', $saved->getCostCurrency());
        self::assertSame('snap-1', $saved->getPriceSnapshot());
    }

    public function test_it_records_an_unknown_cost_as_null_when_the_model_has_no_price(): void
    {
        $delegate = new FakeAiClient(chatResponse: new ChatResponse('Hi', [], new TokenUsage(10, 5)));

        $this->subjectWithPrice($delegate, null)->chat($this->request());

        $saved = $this->repository->getSavedRecords()[0];
        self::assertNull($saved->getCost());
        self::assertNull($saved->getCostCurrency());
        self::assertNull($saved->getPriceSnapshot());
    }

    public function test_a_failed_call_with_no_usage_has_an_unknown_cost(): void
    {
        $delegate = new FakeAiClient();
        $delegate->givenChatThrows(new \RuntimeException('boom'));

        try {
            $this->subjectWithPrice($delegate, new ModelPrice(1.0, 1.0))->chat($this->request());
            self::fail('expected the delegate exception');
        } catch (\RuntimeException) {
            self::assertNull($this->repository->getSavedRecords()[0]->getCost());
        }
    }

    public function test_a_pricing_failure_never_costs_the_row_or_the_response(): void
    {
        $catalog = $this->createStub(PriceCatalog::class);
        $catalog->method('find')->willThrowException(new \RuntimeException('catalog down'));
        $delegate = new FakeAiClient(chatResponse: new ChatResponse('Hi', [], new TokenUsage(10, 5)));
        $client = new RecordingAiClient(
            $delegate,
            $this->repository,
            new UsageStoreResolver(
                new FakeStoreManager(1),
                new FakeAppState(Area::AREA_FRONTEND),
                $this->storeResolverLogger,
            ),
            $this->logger,
            new CostEstimator($catalog, new CostCalculator()),
        );

        $response = $client->chat($this->request());

        self::assertSame('Hi', $response->getText());
        self::assertCount(1, $this->repository->getSavedRecords());
        self::assertNull($this->repository->getSavedRecords()[0]->getCost());
    }

    private function subjectWithPrice(FakeAiClient $delegate, ?ModelPrice $price): RecordingAiClient
    {
        $catalog = $this->createStub(PriceCatalog::class);
        $catalog->method('find')->willReturn($price === null ? null : new PriceQuote($price, 'snap-1'));

        return new RecordingAiClient(
            $delegate,
            $this->repository,
            new UsageStoreResolver(
                new FakeStoreManager(1),
                new FakeAppState(Area::AREA_FRONTEND),
                $this->storeResolverLogger,
            ),
            $this->logger,
            new CostEstimator($catalog, new CostCalculator()),
        );
    }

    private function subject(
        FakeAiClient $delegate,
        ?StoreManagerInterface $storeManager = null,
        ?State $appState = null,
    ): RecordingAiClient {
        return new RecordingAiClient(
            $delegate,
            $this->repository,
            new UsageStoreResolver(
                $storeManager ?? new FakeStoreManager(1),
                $appState ?? new FakeAppState(Area::AREA_FRONTEND),
                $this->storeResolverLogger,
            ),
            $this->logger,
        );
    }

    private function succeedingDelegate(): FakeAiClient
    {
        return new FakeAiClient(chatResponse: new ChatResponse('Hi', [], new TokenUsage(10, 5)));
    }

    private function request(): ChatRequestInterface
    {
        return new ChatRequest([new ChatMessage(MessageRole::User, 'Hello')]);
    }
}

/**
 * In-memory stand-in for {@see AiClientInterface}, kept next to the test that uses it per this
 * codebase's fakes-over-mocks convention.
 */
class FakeAiClient implements AiClientInterface
{
    /**
     * @var list<array{request: ChatRequestInterface, options: array<string,mixed>}>
     */
    public array $chatCalls = [];

    /**
     * @var list<array{request: ChatRequestInterface, options: array<string,mixed>}>
     */
    public array $streamChatCalls = [];

    private ?\Throwable $chatException = null;

    /**
     * @var list<StreamChunkInterface>
     */
    private array $streamChunks = [];

    private ?\Throwable $streamException = null;

    private ?ChatResponseInterface $streamReturn = null;

    public function __construct(
        private readonly ChatResponseInterface $chatResponse = new ChatResponse(),
        private readonly string $serviceCode = 'anthropic',
        private readonly string $serviceId = '_row1',
        private readonly string $model = 'claude-sonnet',
        private readonly string $consumer = UsageRecordInterface::CONSUMER_UNKNOWN,
    ) {
    }

    public function chat(ChatRequestInterface $request, array $options = []): ChatResponseInterface
    {
        $this->chatCalls[] = ['request' => $request, 'options' => $options];
        if ($this->chatException !== null) {
            throw $this->chatException;
        }

        return $this->chatResponse;
    }

    public function streamChat(ChatRequestInterface $request, array $options = []): \Generator
    {
        $this->streamChatCalls[] = ['request' => $request, 'options' => $options];

        foreach ($this->streamChunks as $chunk) {
            yield $chunk;
        }

        if ($this->streamException !== null) {
            throw $this->streamException;
        }

        return $this->streamReturn ?? new ChatResponse();
    }

    public function complete(string $prompt, array $options = []): string
    {
        return $this->chatResponse->getText();
    }

    public function getServiceCode(): string
    {
        return $this->serviceCode;
    }

    public function getServiceId(): string
    {
        return $this->serviceId;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getConsumer(): string
    {
        return $this->consumer;
    }

    public function givenChatThrows(\Throwable $exception): void
    {
        $this->chatException = $exception;
    }

    public function givenStreamChunks(StreamChunkInterface ...$chunks): void
    {
        $this->streamChunks = $chunks;
    }

    public function givenStreamReturn(ChatResponseInterface $response): void
    {
        $this->streamReturn = $response;
    }

    /**
     * @param list<StreamChunkInterface> $chunks Yielded in order before the stream fails; mirrors
     *        the usage chunk a real failing stream yields before rethrowing (see task 004)
     * @param \Throwable $exception Thrown after every $chunk has been yielded
     */
    public function givenStreamFailsAfter(array $chunks, \Throwable $exception): void
    {
        $this->streamChunks = $chunks;
        $this->streamException = $exception;
    }
}

/**
 * {@see FakeAiClient} for a wrapped client that also implements {@see PlatformAwareInterface}.
 */
class FakePlatformAwareAiClient extends FakeAiClient implements PlatformAwareInterface
{
    public function __construct(private readonly PlatformInterface $platform)
    {
        parent::__construct();
    }

    public function getPlatform(): PlatformInterface
    {
        return $this->platform;
    }

    /**
     * @inheritdoc
     */
    public function normalizeOptions(array $options): array
    {
        return ['normalized' => true] + $options;
    }
}

/**
 * In-memory stand-in for {@see UsageRecordRepositoryInterface}, kept next to the test that uses it.
 * Only save() is exercised by {@see RecordingAiClient}; every other method belongs to the admin
 * grid and the daily roll-up built by later tasks and is never called here.
 */
class FakeUsageRecordRepository implements UsageRecordRepositoryInterface
{
    /**
     * @var list<UsageRecordInterface>
     */
    private array $saved = [];

    private ?\Throwable $saveException = null;

    public function save(UsageRecordInterface $record): void
    {
        if ($this->saveException !== null) {
            throw $this->saveException;
        }

        $this->saved[] = $record;
    }

    public function givenSaveFails(\Throwable $exception): void
    {
        $this->saveException = $exception;
    }

    /**
     * @return list<UsageRecordInterface>
     */
    public function getSavedRecords(): array
    {
        return $this->saved;
    }

    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function deleteOlderThan(\DateTimeInterface $cutoff): int
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function getOldestRecordedAt(): ?\DateTimeImmutable
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function getDistinctConsumers(): array
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }
}

/**
 * In-memory stand-in for {@see StoreManagerInterface}, kept next to the test that uses it. Only
 * getStore() and getDefaultStoreView() are exercised by {@see UsageStoreResolver}.
 */
class FakeStoreManager implements StoreManagerInterface
{
    private ?\Throwable $defaultStoreViewFailure = null;

    /**
     * @param int|null $storeId The current store; null makes getStore() throw, as it does when no
     *        store can be resolved
     * @param int|null $defaultStoreViewId The default store view; null when the install has none
     */
    public function __construct(
        private readonly ?int $storeId,
        private readonly ?int $defaultStoreViewId = 1,
    ) {
    }

    public function givenDefaultStoreViewLookupFails(\Throwable $failure): void
    {
        $this->defaultStoreViewFailure = $failure;
    }

    public function getStore($storeId = null)
    {
        if ($this->storeId === null) {
            throw new NoSuchEntityException();
        }

        return new FakeStore($this->storeId);
    }

    public function setIsSingleStoreModeAllowed($value)
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function hasSingleStore()
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function isSingleStoreMode()
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function getStores($withDefault = false, $codeKey = false)
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function getWebsite($websiteId = null)
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function getWebsites($withDefault = false, $codeKey = false)
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function reinitStores()
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function getDefaultStoreView()
    {
        if ($this->defaultStoreViewFailure !== null) {
            throw $this->defaultStoreViewFailure;
        }

        return $this->defaultStoreViewId === null ? null : new FakeStore($this->defaultStoreViewId);
    }

    public function getGroup($groupId = null)
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function getGroups($withDefault = false)
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function setCurrentStore($store)
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }
}

/**
 * Stand-in for {@see State}, which is a concrete class with no interface to fake behind. Only
 * getAreaCode() is exercised by {@see UsageStoreResolver}, so this subclass answers it from its
 * own constructor argument and skips the parent constructor, whose collaborators nothing here
 * reaches. A null area code throws the way the real one does before any area is set.
 */
class FakeAppState extends State
{
    public function __construct(private readonly ?string $fakeAreaCode)
    {
    }

    public function getAreaCode()
    {
        if ($this->fakeAreaCode === null) {
            throw new LocalizedException(__('Area code is not set'));
        }

        return $this->fakeAreaCode;
    }
}

/**
 * In-memory stand-in for {@see StoreInterface}, kept next to the test that uses it. Only getId()
 * is exercised by {@see RecordingAiClient}.
 */
class FakeStore implements StoreInterface
{
    public function __construct(private readonly int $id)
    {
    }

    public function getId()
    {
        return $this->id;
    }

    public function setId($id)
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function getCode()
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function setCode($code)
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function getName()
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function setName($name)
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function getWebsiteId()
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function setWebsiteId($websiteId)
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function getStoreGroupId()
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function setIsActive($isActive)
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function getIsActive()
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function setStoreGroupId($storeGroupId)
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function getExtensionAttributes()
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function setExtensionAttributes(StoreExtensionInterface $extensionAttributes)
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }
}

/**
 * In-memory stand-in for {@see \Psr\Log\LoggerInterface}, kept next to the test that uses it.
 */
class FakeLogger extends AbstractLogger
{
    /**
     * @var list<array{level: mixed, message: string, context: array<mixed>}>
     */
    private array $records = [];

    /**
     * @inheritdoc
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * @return list<array{level: mixed, message: string, context: array<mixed>}>
     */
    public function getRecords(): array
    {
        return $this->records;
    }
}
