<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify;

/**
 * A failed call to Shopify. Transient failures (network, rate limits, 5xx)
 * are worth retrying; the rest need someone to fix the settings.
 */
class ShopifyException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $transient = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
