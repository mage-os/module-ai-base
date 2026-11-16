<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Pricing;

use MageOS\AiBase\Model\Pricing\ModelKeyMapper;
use MageOS\AiBase\Model\Pricing\PriceTableParser;
use PHPUnit\Framework\TestCase;

final class PriceTableParserTest extends TestCase
{
    /**
     * @return array<string,mixed>
     */
    private function fixture(): array
    {
        $json = file_get_contents(__DIR__ . '/fixtures/litellm_prices.json');
        self::assertIsString($json);

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_it_keeps_only_per_token_text_models_and_drops_the_sample_spec(): void
    {
        $models = (new PriceTableParser())->trim($this->fixture());

        self::assertSame(
            ['gpt-4o', 'claude-sonnet-4-5', 'gemini/gemini-2.5-pro', 'deepseek/deepseek-chat'],
            array_keys($models)
        );
    }

    public function test_it_ignores_batch_flex_and_priority_rates(): void
    {
        $models = (new PriceTableParser())->trim($this->fixture());

        self::assertSame(['i' => 2.5e-6, 'o' => 1e-5, 'cr' => 1.25e-6], $models['gpt-4o']);
    }

    public function test_it_builds_a_price_with_cache_rates(): void
    {
        $parser = new PriceTableParser();
        $price = $parser->toPrice($parser->trim($this->fixture())['claude-sonnet-4-5']);

        self::assertNotNull($price);
        self::assertSame(3e-6, $price->input);
        self::assertSame(1.5e-5, $price->output);
        self::assertSame(3e-7, $price->cacheRead);
        self::assertSame(3.75e-6, $price->cacheWrite);
    }

    public function test_it_reads_long_context_tiers(): void
    {
        $parser = new PriceTableParser();
        $price = $parser->toPrice($parser->trim($this->fixture())['gemini/gemini-2.5-pro']);

        self::assertNotNull($price);
        self::assertSame(1.25e-6, $price->forPromptTokens(100000)->input);
        self::assertSame(2.5e-6, $price->forPromptTokens(200001)->input);
        self::assertSame(1.5e-5, $price->forPromptTokens(200001)->output);
    }

    public function test_an_entry_without_input_and_output_rates_gives_no_price(): void
    {
        self::assertNull((new PriceTableParser())->toPrice(['cr' => 1.0]));
        self::assertNull((new PriceTableParser())->toPrice([]));
    }

    public function test_the_key_mapper_prefixes_by_provider_and_knows_nothing_about_self_hosted(): void
    {
        $mapper = new ModelKeyMapper();

        self::assertSame(['gpt-4o', 'openai/gpt-4o'], $mapper->candidates('openai', 'gpt-4o'));
        self::assertContains('gemini/gemini-2.5-pro', $mapper->candidates('google', 'models/gemini-2.5-pro'));
        self::assertSame(['azure/gpt-4o'], $mapper->candidates('azure', 'gpt-4o'));
        self::assertSame([], $mapper->candidates('ollama', 'llama3'));
        self::assertSame([], $mapper->candidates('openai_compatible', 'anything'));
        self::assertSame([], $mapper->candidates('openai', ''));
    }
}
