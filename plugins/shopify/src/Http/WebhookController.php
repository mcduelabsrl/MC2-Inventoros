<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Inventoros\Plugins\Shopify\Jobs\ProcessWebhookEvent;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;
use Inventoros\Plugins\Shopify\Models\ShopifyWebhookEvent;

/**
 * Receives Shopify's webhooks at /webhooks/plugins/shopify/{token}.
 *
 * The token in the URL picks the connection (and so the organization); the
 * X-Shopify-Hmac-Sha256 header, an HMAC of the raw body with the app's client
 * secret, proves Shopify sent it, and X-Shopify-Shop-Domain must be the
 * connected store.
 *
 * Every accepted delivery is stored before anything else, keyed by
 * X-Shopify-Webhook-Id: Shopify may deliver the same event more than once
 * (a repeat is answered 200 and dropped) and in any order (processing
 * re-reads the current state, see OrderImporter and InboundReconciler).
 * Work is queued so Shopify gets its answer fast; a 2xx tells Shopify not
 * to retry.
 */
class WebhookController
{
    public const ORDER_TOPICS = ['orders/create', 'orders/updated', 'orders/cancelled'];

    public const INVENTORY_TOPICS = ['inventory_levels/update'];

    /** @deprecated use ORDER_TOPICS */
    public const TOPICS = self::ORDER_TOPICS;

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

        $shop = (string) $request->header('X-Shopify-Shop-Domain');
        if ($shop !== '' && strcasecmp($shop, $connection->shop_domain) !== 0) {
            return response('Wrong store.', 401);
        }

        $topic = (string) $request->header('X-Shopify-Topic');
        $payload = json_decode($body, true);

        $wanted = (in_array($topic, self::ORDER_TOPICS, true) && $connection->import_orders)
            || in_array($topic, self::INVENTORY_TOPICS, true);

        if (! $wanted || ! is_array($payload)) {
            return response('Ignored.', 200);
        }

        // Shopify's id of the delivery; the same event delivered again has
        // the same id. Without one, the body itself identifies it.
        $webhookId = (string) ($request->header('X-Shopify-Webhook-Id') ?: $request->header('X-Shopify-Event-Id') ?: hash('sha256', $topic.$body));

        $inserted = ShopifyWebhookEvent::query()->insertOrIgnore([
            'organization_id' => $connection->organization_id,
            'webhook_id' => mb_substr($webhookId, 0, 100),
            'topic' => $topic,
            'triggered_at' => self::time((string) $request->header('X-Shopify-Triggered-At')),
            'payload' => $body,
            'status' => ShopifyWebhookEvent::RECEIVED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 0) {
            return response('Duplicate.', 200);
        }

        $event = ShopifyWebhookEvent::query()
            ->where('organization_id', $connection->organization_id)
            ->where('webhook_id', mb_substr($webhookId, 0, 100))
            ->first();

        if ($event !== null) {
            ProcessWebhookEvent::dispatch($event->id);
        }

        return response('OK', 200);
    }

    public static function validSignature(string $body, string $signature, string $secret): bool
    {
        if ($signature === '' || $secret === '') {
            return false;
        }

        return hash_equals(base64_encode(hash_hmac('sha256', $body, $secret, true)), $signature);
    }

    private static function time(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }
}
