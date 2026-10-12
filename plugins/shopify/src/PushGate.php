<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify;

/**
 * Holds back stock pushes while the plugin itself writes stock that Shopify
 * already has (a catalogue import), so Shopify is never set to a figure that
 * is about to change, such as the zero of a product created before its
 * opening stock is booked.
 */
final class PushGate
{
    private static int $paused = 0;

    public static function isPaused(): bool
    {
        return self::$paused > 0;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function while(callable $callback): mixed
    {
        self::$paused++;

        try {
            return $callback();
        } finally {
            self::$paused--;
        }
    }
}
