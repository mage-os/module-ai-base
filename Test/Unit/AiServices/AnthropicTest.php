<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\AiServices;

use MageOS\AiBase\AiServices\Anthropic;
use MageOS\AiBase\Api\JsonFetcherInterface;
use MageOS\AiBase\Test\Unit\Stubs\FakeFieldDescriptorFactory;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Anthropic\ModelCatalog;

final class AnthropicTest extends TestCase
{
    public function test_every_curated_model_is_known_to_the_symfony_bridge(): void
    {
        $service = new Anthropic(new FakeFieldDescriptorFactory(), $this->fetcher());

        $unknown = array_diff(
            array_keys($service->getSupportedModels()),
            array_keys((new ModelCatalog())->getModels())
        );

        self::assertSame([], array_values($unknown));
    }

    public function test_the_current_claude_generation_is_offered(): void
    {
        $service = new Anthropic(new FakeFieldDescriptorFactory(), $this->fetcher());

        $models = $service->getSupportedModels();

        foreach (['claude-fable-5-1', 'claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'] as $model) {
            self::assertArrayHasKey($model, $models);
        }
    }

    private function fetcher(): JsonFetcherInterface
    {
        return new class implements JsonFetcherInterface {
            public function getJson(string $url, #[\SensitiveParameter] array $headers = []): array
            {
                return [];
            }
        };
    }
}
