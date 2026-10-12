<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inventoros\Plugins\Shopify\Jobs\ProcessWebhook;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;

/**
 * Receives Shopify's order webhooks at /webhooks/plugins/shopify/{token}.
 *
 * The token in the URL picks the connection (and so the organization); the
 * X-Shopify-Hmac-Sha256 header, an HMAC of the raw body with the app's client
 * secret, proves Shopify sent it. Work is queued so Shopify gets its answer
 * fast; a 2xx tells Shopify not to retry.
 */
class WebhookController
{
    public const TOPICS = ['orders/create', 'orders/cancelled'];

    public function __invoke(Request $request, string $token): Response
    {
        $connection = ShopifyConnection::query()->where('webhook_token', $token)->first();

        if ($connection === null) {
            return response('Unknown webhook.', 404);
        }

        $body = $request->getContent();

        if (! self::validSignature($body, (string) $request->header('X-Shopify-Hmac-Sha256'), $connection->client_secret)) {
            return response('Invalid signature.', 401);
        }

        $topic = (string) $request->header('X-Shopify-Topic');
        $payload = json_decode($body, true);

        if (! in_array($topic, self::TOPICS, true) || ! is_array($payload) || ! $connection->import_orders) {
            return response('Ignored.', 200);
        }

        ProcessWebhook::dispatch($connection->id, $topic, $payload);

        return response('OK', 200);
    }

    public static function validSignature(string $body, string $signature, string $secret): bool
    {
        if ($signature === '' || $secret === '') {
            return false;
        }

        return hash_equals(base64_encode(hash_hmac('sha256', $body, $secret, true)), $signature);
    }
}
