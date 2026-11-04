<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\FieldDescriptorInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;
use MageOS\AiBase\Api\JsonFetcherInterface;
use MageOS\AiBase\Api\ModelListProviderInterface;

/**
 * Any server that speaks the de facto OpenAI Chat Completions wire format on a host of the
 * administrator's own choosing — a self-hosted gateway or aggregator (LiteLLM, an
 * OpenRouter-style proxy, Eden AI), not a specific vendor. Unlike Ollama and LM Studio this has
 * no sensible default host, so the field ships without one and an administrator must supply both
 * the endpoint and, where the server requires it, an API key.
 */
class OpenAiCompatible extends AbstractAiService implements ModelListProviderInterface
{
    use ModelListTrait;

    /**
     * The de facto OpenAI model listing path, relative to the base URL without its version segment.
     */
    private const MODELS_PATH = '/v1/models';

    /**
     * Stored field holding the chat completions path, for a gateway that does not serve it at /v1.
     */
    private const FIELD_COMPLETIONS_PATH = 'completions_path';

    /**
     * Stored field holding the embeddings path, for a gateway that does not serve it at /v1.
     */
    private const FIELD_EMBEDDINGS_PATH = 'embeddings_path';

    /**
     * The generic bridge's own completions path, shown in the empty field as what it falls back to.
     */
    private const DEFAULT_COMPLETIONS_PATH = '/v1/chat/completions';

    /**
     * The generic bridge's own embeddings path, shown in the empty field as what it falls back to.
     */
    private const DEFAULT_EMBEDDINGS_PATH = '/v1/embeddings';

    /**
     * @param FieldDescriptorInterfaceFactory $fieldFactory
     * @param JsonFetcherInterface $modelListFetcher
     */
    public function __construct(
        FieldDescriptorInterfaceFactory $fieldFactory,
        private readonly JsonFetcherInterface $modelListFetcher,
    ) {
        parent::__construct($fieldFactory);
    }

    /**
     * @inheritdoc
     */
    public function getCode(): string
    {
        return 'openai_compatible';
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'OpenAI-Compatible';
    }

    /**
     * @inheritdoc
     */
    public function getSupportedModels(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getConfigurationFields(): array
    {
        return [
            $this->baseUrlField('', 'Base URL (will strip /v1)'),
            $this->apiKeyField(),
            $this->freeTextModelField(),
            $this->pathField(self::FIELD_COMPLETIONS_PATH, 'Completions Path', self::DEFAULT_COMPLETIONS_PATH),
            $this->pathField(self::FIELD_EMBEDDINGS_PATH, 'Embeddings Path', self::DEFAULT_EMBEDDINGS_PATH),
        ];
    }

    /**
     * An optional, advanced path field that falls back to the bridge's own path when left empty.
     *
     * The bridge default shows as a placeholder rather than a prefilled value, so an empty field
     * keeps following the bridge and rows saved before the field existed need no migration. It is
     * not flagged as an endpoint: ClientFactory always gives a path a leading slash, so it can only
     * pick a path on the host the base URL names, never another host the key could be sent to.
     *
     * @param string $name
     * @param string $label
     * @param string $placeholder
     * @return FieldDescriptorInterface
     */
    private function pathField(string $name, string $label, string $placeholder): FieldDescriptorInterface
    {
        return $this->fieldFactory->create([
            'name'        => $name,
            'label'       => $label,
            'type'        => FieldDescriptorInterface::TYPE_TEXT,
            'placeholder' => $placeholder,
            'advanced'    => true,
        ]);
    }

    /**
     * The base URL and, where the server wants one, the API key.
     *
     * Unlike Ollama and LM Studio this fronts no specific runtime, so it cannot assume the server is
     * unauthenticated; an empty key is passed as null, which the bridge reads as "send none".
     *
     * @param array<string,mixed> $configuration
     * @return list<mixed>
     */
    public function getPlatformArguments(#[\SensitiveParameter] array $configuration): array
    {
        $apiKey = $this->stringValue($configuration, self::FIELD_API_KEY);

        return [$this->resolveBaseUrlWithoutVersion($configuration), $apiKey !== '' ? $apiKey : null];
    }

    /**
     * Fetch the gateway's model list from the de facto OpenAI listing endpoint.
     *
     * Every gateway this provider fronts is a different server, so there is no curated fallback:
     * a gateway that does not serve `/v1/models` fails here with the fetcher's error, and the model
     * field stays free text. The key is only sent when one is stored, for the same reason
     * getPlatformArguments() passes none: a self-hosted gateway without auth may reject a bare
     * `Bearer ` header.
     *
     * @param array<string,mixed> $configuration
     * @return array<string,string>
     */
    public function fetchModels(#[\SensitiveParameter] array $configuration): array
    {
        $response = $this->modelListFetcher->getJson(
            $this->resolveBaseUrlWithoutVersion($configuration) . self::MODELS_PATH,
            $this->authorizationHeaders($configuration),
        );

        return $this->parseDataModelList($response);
    }

    /**
     * A Bearer header for the stored key, or no headers at all when the row has none.
     *
     * @param array<string,mixed> $configuration
     * @return array<string,string>
     */
    private function authorizationHeaders(#[\SensitiveParameter] array $configuration): array
    {
        $apiKey = $this->resolveApiKey($configuration);

        return $apiKey !== '' ? ['Authorization' => 'Bearer ' . $apiKey] : [];
    }

    /**
     * The base URL with a trailing API version segment stripped.
     *
     * {@see \Symfony\AI\Platform\Bridge\Generic\Factory::createPlatform()}'s bridge always appends
     * `/v1/chat/completions` itself, so an administrator pasting the `/v1`-suffixed URL their gateway
     * shows them (as LiteLLM and most OpenAI-compatible gateways do) would otherwise double it into a
     * path the gateway 404s on, with nothing in the response pointing at why.
     *
     * @param array<string,mixed> $configuration
     * @return string
     */
    private function resolveBaseUrlWithoutVersion(#[\SensitiveParameter] array $configuration): string
    {
        $baseUrl = $this->resolveBaseUrl($configuration, '');

        return preg_replace('#/v1$#', '', $baseUrl) ?? $baseUrl;
    }
}
