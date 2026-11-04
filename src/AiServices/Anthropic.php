<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;
use MageOS\AiBase\Api\JsonFetcherInterface;
use MageOS\AiBase\Api\ModelListProviderInterface;

class Anthropic extends AbstractAiService implements ModelListProviderInterface
{
    use ModelListTrait;

    /**
     * Anthropic model listing endpoint.
     */
    private const MODELS_URL = 'https://api.anthropic.com/v1/models';

    /**
     * API version header value required by the Anthropic API.
     */
    private const API_VERSION = '2023-06-01';

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
        return 'anthropic';
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'Anthropic';
    }

    /**
     * @inheritdoc
     */
    public function getSupportedModels(): array
    {
        return [
            'claude-fable-5-1'          => 'Claude Fable 5.1',
            'claude-opus-5-5'           => 'Claude Opus 5.5',
            'claude-sonnet-5-5'         => 'Claude Sonnet 5.5',
            'claude-haiku-5-5'          => 'Claude Haiku 5.5',
            'claude-opus-5'             => 'Claude Opus 5',
            'claude-sonnet-5'           => 'Claude Sonnet 5',
            'claude-opus-4-7'           => 'Claude Opus 4.7',
            'claude-sonnet-4-6'         => 'Claude Sonnet 4.6',
            'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5',
        ];
    }

    /**
     * @inheritdoc
     */
    public function getConfigurationFields(): array
    {
        return [
            $this->apiKeyField(),
            $this->modelField($this->getSupportedModels()),
        ];
    }

    /**
     * @inheritdoc
     */
    public function fetchModels(#[\SensitiveParameter] array $configuration): array
    {
        $response = $this->modelListFetcher->getJson(self::MODELS_URL, [
            'x-api-key' => $this->resolveApiKey($configuration),
            'anthropic-version' => self::API_VERSION,
        ]);

        return $this->parseDataModelList($response, 'display_name');
    }
}
