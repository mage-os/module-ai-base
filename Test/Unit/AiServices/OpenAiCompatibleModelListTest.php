<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\AiServices;

require_once __DIR__ . '/../Stubs/FieldDescriptorInterfaceFactoryStub.php';

use Magento\Framework\Exception\LocalizedException;
use MageOS\AiBase\AiServices\OpenAiCompatible;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;
use MageOS\AiBase\Test\Unit\Stubs\RecordingJsonFetcher;
use PHPUnit\Framework\TestCase;

/**
 * Refresh Models for the OpenAI-Compatible provider: one listing call against whatever gateway the
 * row points at, so every OpenAI-compatible gateway gets a live model list without a provider of
 * its own.
 *
 * @covers \MageOS\AiBase\AiServices\OpenAiCompatible
 */
final class OpenAiCompatibleModelListTest extends TestCase
{
    public function test_it_lists_the_models_the_gateway_serves(): void
    {
        $service = $this->service(new RecordingJsonFetcher(['data' => [
            ['id' => 'gpt-5.4-mini'],
            ['id' => 'claude-sonnet-5'],
            ['object' => 'model'],
            ['id' => ''],
            'invalid',
        ]]));

        $models = $service->fetchModels(['base_url' => 'https://gateway.example', 'api_key' => 'key']);

        self::assertSame(
            ['gpt-5.4-mini' => 'gpt-5.4-mini', 'claude-sonnet-5' => 'claude-sonnet-5'],
            $models,
        );
    }

    public function test_it_reads_the_v1_models_path_under_the_base_url(): void
    {
        $fetcher = new RecordingJsonFetcher(['data' => []]);

        $this->service($fetcher)->fetchModels(['base_url' => 'https://gateway.example/', 'api_key' => 'key']);

        self::assertSame('https://gateway.example/v1/models', $fetcher->getRequests()[0]['url']);
    }

    public function test_it_does_not_double_the_version_segment_of_a_pasted_v1_url(): void
    {
        $fetcher = new RecordingJsonFetcher(['data' => []]);

        $this->service($fetcher)->fetchModels(['base_url' => 'https://gateway.example/v1', 'api_key' => 'key']);

        self::assertSame('https://gateway.example/v1/models', $fetcher->getRequests()[0]['url']);
    }

    public function test_it_sends_the_stored_key_as_a_bearer_token(): void
    {
        $fetcher = new RecordingJsonFetcher(['data' => []]);

        $this->service($fetcher)->fetchModels(['base_url' => 'https://gateway.example', 'api_key' => 'secret']);

        self::assertSame(['Authorization' => 'Bearer secret'], $fetcher->getRequests()[0]['headers']);
    }

    public function test_it_sends_no_authorization_header_when_the_row_has_no_key(): void
    {
        $fetcher = new RecordingJsonFetcher(['data' => []]);

        $this->service($fetcher)->fetchModels(['base_url' => 'http://litellm.internal:4000', 'api_key' => '']);

        self::assertSame([], $fetcher->getRequests()[0]['headers']);
    }

    public function test_it_treats_a_malformed_key_as_absent(): void
    {
        $fetcher = new RecordingJsonFetcher(['data' => []]);

        $this->service($fetcher)->fetchModels(['base_url' => 'https://gateway.example', 'api_key' => ['oops']]);

        self::assertSame([], $fetcher->getRequests()[0]['headers']);
    }

    public function test_it_rejects_a_response_without_a_model_list(): void
    {
        $service = $this->service(new RecordingJsonFetcher(['error' => ['message' => 'nope']]));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('missing "data" list');

        $service->fetchModels(['base_url' => 'https://gateway.example', 'api_key' => 'key']);
    }

    public function test_it_lets_a_gateway_without_a_listing_endpoint_fail_with_the_fetch_error(): void
    {
        $service = $this->service((new RecordingJsonFetcher())->givenFailure('HTTP 404'));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('HTTP 404');

        $service->fetchModels(['base_url' => 'https://gateway.example', 'api_key' => 'key']);
    }

    /**
     * The provider under test, wired to the given fetcher.
     *
     * @param RecordingJsonFetcher $fetcher
     * @return OpenAiCompatible
     */
    private function service(RecordingJsonFetcher $fetcher): OpenAiCompatible
    {
        return new OpenAiCompatible(new FieldDescriptorInterfaceFactory(), $fetcher);
    }
}
