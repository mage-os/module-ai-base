<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;
use MageOS\AiBase\Api\JsonFetcherInterface;
use MageOS\AiBase\Api\ModelListProviderInterface;

/**
 * Cheaper Inference's hosted gateway, using its OpenAI-compatible /v1 chat endpoint.
 */
class CheaperInference extends AbstractAiService implements ModelListProviderInterface
{
    use ModelListTrait;

    /**
     * Keep the host fixed so the gateway needs only a key and a model in the admin form. The
     * generic bridge adds its default /v1 chat and embedding paths, which this gateway serves.
     */
    private const BASE_URL = 'https://api.cheaperinference.com';

    /**
     * OpenAI-compatible model listing endpoint; it needs the API key.
     */
    private const MODELS_URL = 'https://api.cheaperinference.com/v1/models';

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
        return 'cheaperinference';
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'Cheaper Inference';
    }

    /**
     * The gateway serves models from several labs and the list changes, so do not curate a static list.
     *
     * @return array<string,string>
     */
    public function getSupportedModels(): array
    {
        return [];
    }

    /**
     * The fixed gateway host and stored API key; the bridge entry in etc/di.xml sets the name.
     *
     * @param array<string,mixed> $configuration
     * @return list<mixed>
     */
    public function getPlatformArguments(#[\SensitiveParameter] array $configuration): array
    {
        return [self::BASE_URL, $this->resolveApiKey($configuration)];
    }

    /**
     * Fetch the models the API key can use.
     *
     * @param array<string,mixed> $configuration
     * @return array<string,string>
     */
    public function fetchModels(#[\SensitiveParameter] array $configuration): array
    {
        $response = $this->modelListFetcher->getJson(self::MODELS_URL, [
            'Authorization' => 'Bearer ' . $this->resolveApiKey($configuration),
        ]);

        return $this->parseDataModelList($response);
    }
}
