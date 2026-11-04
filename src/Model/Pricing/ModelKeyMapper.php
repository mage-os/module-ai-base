<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Pricing;

/**
 * Maps this module's (service code, model) pair to the keys LiteLLM's price table might know it by.
 *
 * LiteLLM names models by provider prefix, and the prefix rules differ per provider: OpenAI and
 * Anthropic models are bare (`gpt-4o`), others are prefixed (`gemini/gemini-2.5-pro`,
 * `deepseek/deepseek-chat`, `azure/gpt-4o`). That knowledge lives here and nowhere else, so a
 * provider whose naming changes is a one-line edit.
 *
 * Self-hosted backends (Ollama, LM Studio, OpenAI-compatible gateways) have no public list price and
 * map to nothing: their cost is unknown, and an administrator who knows it adds an override.
 */
class ModelKeyMapper
{
    /**
     * Service code => the LiteLLM key prefixes to try, in order. An empty string is the bare name.
     *
     * @var array<string,string[]>
     */
    private const PREFIXES = [
        'openai' => ['', 'openai/'],
        'anthropic' => ['', 'anthropic/'],
        'azure' => ['azure/'],
        'google' => ['gemini/', ''],
        'deepseek' => ['deepseek/', ''],
        'openrouter' => ['openrouter/', ''],
        'huggingface' => ['huggingface/'],
    ];

    /**
     * Candidate table keys for a model, most specific first; empty when the provider has no price list.
     *
     * @param string $serviceCode
     * @param string $model
     * @return string[]
     */
    public function candidates(string $serviceCode, string $model): array
    {
        $prefixes = self::PREFIXES[$serviceCode] ?? null;
        $name = preg_replace('~^models/~', '', trim($model)) ?? $model;
        if ($prefixes === null || $name === '') {
            return [];
        }

        $keys = [];
        foreach ($prefixes as $prefix) {
            $keys[] = $prefix . $name;
            $keys[] = $prefix . strtolower($name);
        }

        return array_values(array_unique($keys));
    }
}
