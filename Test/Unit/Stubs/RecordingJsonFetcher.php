<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Stubs;

use Magento\Framework\Exception\LocalizedException;
use MageOS\AiBase\Api\JsonFetcherInterface;

/**
 * A {@see JsonFetcherInterface} that answers every request with one canned response, set through the
 * constructor or {@see givenFailure()}, and records each URL and header set it was asked for.
 */
final class RecordingJsonFetcher implements JsonFetcherInterface
{
    /**
     * Every request made, in order.
     *
     * @var list<array{url: string, headers: array<string,string>}>
     */
    private array $requests = [];

    /**
     * The message every request fails with, or null while requests succeed.
     *
     * @var string|null
     */
    private ?string $failureMessage = null;

    /**
     * @param array<mixed> $response The decoded JSON every request returns
     */
    public function __construct(
        private readonly array $response = [],
    ) {
    }

    /**
     * Make every request fail the way the real fetcher does on an HTTP error.
     *
     * @param string $message
     * @return $this
     */
    public function givenFailure(string $message): self
    {
        $this->failureMessage = $message;

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function getJson(string $url, #[\SensitiveParameter] array $headers = []): array
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers];
        if ($this->failureMessage !== null) {
            throw new LocalizedException(__($this->failureMessage));
        }

        return $this->response;
    }

    /**
     * Every request made, in order.
     *
     * @return list<array{url: string, headers: array<string,string>}>
     */
    public function getRequests(): array
    {
        return $this->requests;
    }
}
