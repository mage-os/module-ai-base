<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Client;

use Magento\Framework\Exception\LocalizedException;
use MageOS\AiBase\Exceptions\AiAuthenticationException;
use MageOS\AiBase\Exceptions\AiContentFilteredException;
use MageOS\AiBase\Exceptions\AiInvalidRequestException;
use MageOS\AiBase\Exceptions\AiRateLimitedException;
use MageOS\AiBase\Exceptions\AiServiceException;
use MageOS\AiBase\Exceptions\AiToolCallException;
use MageOS\AiBase\Exceptions\AiTransientException;

/**
 * Translates a symfony/ai-platform failure into this module's own typed exception hierarchy.
 *
 * Matched by class name rather than by catching each symfony/ai type directly, and every match is
 * guarded with `class_exists` first, so an exception class a later symfony/ai release renames or
 * drops simply stops matching instead of needing a change here before the upgrade. An exception this
 * module does not recognize, symfony/ai's own or not, still comes back as {@see AiServiceException}
 * rather than escaping unmapped: every failure this module can produce stays a
 * {@see LocalizedException}, matching what `AiClientInterface` documents.
 *
 * Deliberately does not read HTTP status codes or headers itself: symfony/ai's bridges already did
 * that work through `HttpStatusErrorHandlingTrait`, and re-parsing it here would have to be
 * re-verified on every symfony/ai upgrade, which is exactly what this module chose not to take on
 * (see the decision record in docs/ARCHITECTURE.md).
 *
 * A network failure (connection refused, DNS failure, connection reset, idle timeout) is not
 * translated by symfony/ai at all: neither the platform nor its bridges catch the HTTP client's
 * `TransportExceptionInterface`, so it arrives here exactly as the HTTP client threw it.
 * Retrying it is as likely to succeed as retrying a server error, so it maps to
 * {@see AiTransientException} too.
 */
class AiExceptionMapper
{
    /**
     * Translate one caught failure into the matching typed exception.
     *
     * @param \Throwable $exception The failure to translate, typically caught around a call into
     *        symfony/ai-platform
     * @param string $serviceCode Named in the resulting message, as every wrapped failure already
     *        was before this class existed
     * @return LocalizedException
     */
    public function map(\Throwable $exception, string $serviceCode): LocalizedException
    {
        $phrase = __('AI request to service "%1" failed: %2', $serviceCode, $exception->getMessage());
        $cause = $exception instanceof \Exception ? $exception : null;

        if ($this->isInstanceOf($exception, \Symfony\AI\Platform\Exception\AuthenticationException::class)) {
            return new AiAuthenticationException($phrase, $cause);
        }

        if ($this->isInstanceOf($exception, \Symfony\AI\Platform\Exception\RateLimitExceededException::class)) {
            return new AiRateLimitedException($phrase, $exception->getRetryAfter(), $cause);
        }

        if ($this->isAnyInstanceOf($exception, [
            \Symfony\AI\Platform\Exception\ServerException::class,
            \Symfony\AI\Platform\Exception\IncompleteStreamException::class,
            \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface::class,
        ])) {
            return new AiTransientException($phrase, $cause);
        }

        if ($this->isInstanceOf($exception, \Symfony\AI\Platform\Exception\ContentFilterException::class)) {
            return new AiContentFilteredException($phrase, $cause);
        }

        if ($this->isAnyInstanceOf($exception, [
            \Symfony\AI\Platform\Exception\BadRequestException::class,
            \Symfony\AI\Platform\Exception\ExceedContextSizeException::class,
            \Symfony\AI\Platform\Exception\ModelNotFoundException::class,
        ])) {
            return new AiInvalidRequestException($phrase, $cause);
        }

        if ($this->isInstanceOf($exception, \Symfony\AI\Platform\Exception\MalformedToolCallException::class)) {
            return new AiToolCallException($phrase, $cause);
        }

        return new AiServiceException($phrase, $cause);
    }

    /**
     * Whether the exception is of the given class or interface, guarded against it being absent.
     *
     * @template T of object
     * @param \Throwable $exception
     * @param class-string<T> $class
     * @return bool
     * @phpstan-assert-if-true T $exception
     */
    private function isInstanceOf(\Throwable $exception, string $class): bool
    {
        return (class_exists($class) || interface_exists($class)) && $exception instanceof $class;
    }

    /**
     * Whether the exception is of any of the given classes.
     *
     * Checks each class itself rather than through {@see isInstanceOf()}: that method's template
     * resolves to `object` for a plain class-string, which made PHPStan read every call from here
     * as always true. The existence guard is the same one, so an absent bridge still never matches.
     *
     * @param \Throwable $exception
     * @param list<string> $classes
     * @return bool
     */
    private function isAnyInstanceOf(\Throwable $exception, array $classes): bool
    {
        foreach ($classes as $class) {
            if ((class_exists($class) || interface_exists($class)) && $exception instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
