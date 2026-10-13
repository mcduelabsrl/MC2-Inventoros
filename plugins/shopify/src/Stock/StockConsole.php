<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Stock;

use App\Services\Inventory\VariantStockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Inventoros\Plugins\Shopify\Jobs\FullStockReconcile;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;
use Inventoros\Plugins\Shopify\Models\ShopifyStockOperation;

/**
 * Artisan commands for setting up and checking stock per warehouse.
 *
 *   shopify:stock-setup [--apply]      dry run (default) or set up, with comparison CSV
 *   shopify:stock-activate             turn on two-way sync after the comparison is approved
 *   shopify:stock-pause                stop sending to Shopify (webhooks are still kept)
 *   shopify:stock-reconcile            read and check every level now
 *   shopify:routing-check              how Shopify assigned the open orders
 *   shopify:transfer-plan {name}       plan for a transfer (e.g. T0001) to reach the main warehouse
 *   shopify:transfer-approve {id}      carry out an approved plan step
 *   shopify:purge-legacy-stock-jobs    remove 1.x stock pushes from the database queue
 */
class StockConsole
{
    public static function register(): void
    {
        $connection = function (Command $command): ?ShopifyConnection {
            $org = $command->option('organization');
            $connection = $org !== null
                ? ShopifyConnection::forOrganization((int) $org)
                : (ShopifyConnection::query()->count() === 1 ? ShopifyConnection::query()->first() : null);

            if ($connection === null) {
                $command->error('Name the organization with --organization=ID (or connect exactly one store).');
            }

            return $connection;
        };

        Artisan::command('shopify:stock-setup {--organization=} {--apply} {--main-location=} {--second-location=}', function () use ($connection) {
            /** @var Command $this */
            $c = $connection($this);
            if ($c === null) {
                return 1;
            }

            $apply = (bool) $this->option('apply');
            $this->info($apply ? 'Setting up stock per warehouse from Shopify (Shopify is not changed)...' : 'Dry run: reading and comparing only.');

            $summary = (new StockBootstrap($c))->run($apply, array_filter([
                'main_location' => $this->option('main-location'),
                'second_location' => $this->option('second-location'),
            ]));

            $cmp = $summary['comparison'];
            $this->table(['', ''], [
                ['Shopify items', $summary['shopify_items'] ?? 0],
                ['… without an Inventoros variant', $summary['shopify_items_without_variant'] ?? 0],
                ['Variant × location rows', $cmp['rows']],
                ['Matching', $cmp['match']],
                ['Committed differs only', $cmp['committed_only']],
                ['Different', $cmp['differ']],
                ['Negative available', $cmp['negative_available']],
                ['On hand in Shopify', $cmp['shopify_on_hand']],
                ['On hand in Inventoros', $cmp['inventoros_on_hand']],
                ['Open Shopify orders', $summary['orders']['open'] ?? 0],
            ]);
            $this->line('Comparison: '.$cmp['file']);
            $this->line($apply
                ? 'Sync stays paused. Check the comparison, then run shopify:stock-activate.'
                : 'Nothing was written. Run again with --apply to set up.');

            return 0;
        })->purpose('Set up stock per warehouse from Shopify (dry run unless --apply)');

        Artisan::command('shopify:stock-activate {--organization=}', function () use ($connection) {
            /** @var Command $this */
            $c = $connection($this);
            if ($c === null) {
                return 1;
            }
            $result = (new StockBootstrap($c))->activate();
            FullStockReconcile::dispatch((int) $c->organization_id);
            $this->info("Two-way stock sync is on. {$result['queued']} operation(s) made since the set-up were queued for Shopify; every level is being checked.");

            return 0;
        })->purpose('Turn on two-way stock sync with Shopify');

        Artisan::command('shopify:stock-pause {--organization=}', function () use ($connection) {
            /** @var Command $this */
            $c = $connection($this);
            if ($c === null) {
                return 1;
            }
            $c->forceFill(['stock_sync_mode' => ShopifyConnection::STOCK_PAUSED])->save();
            $this->info('Stock sync with Shopify is paused: nothing is sent, webhooks are still recorded. Run shopify:stock-activate to resume (changes made meanwhile are sent then).');

            return 0;
        })->purpose('Pause two-way stock sync with Shopify');

        Artisan::command('shopify:stock-reconcile {--organization=}', function () use ($connection) {
            /** @var Command $this */
            $c = $connection($this);
            if ($c === null) {
                return 1;
            }
            (new FullStockReconcile((int) $c->organization_id))->handle();
            $this->line(json_encode($c->fresh()->last_full_sync_summary, JSON_PRETTY_PRINT));

            return 0;
        })->purpose('Read and check every Shopify inventory level now');

        Artisan::command('shopify:routing-check {--organization=}', function () use ($connection) {
            /** @var Command $this */
            $c = $connection($this);
            if ($c === null) {
                return 1;
            }
            $rows = (new RoutingCheck($c))->run();
            $this->table(['Order', 'Verdict', 'Advice'], array_map(fn ($r) => [$r['order'], $r['verdict'], $r['advice']], $rows));

            return 0;
        })->purpose('Show how Shopify assigned the open orders to locations');

        Artisan::command('shopify:transfer-plan {name} {--organization=}', function () use ($connection) {
            /** @var Command $this */
            $c = $connection($this);
            if ($c === null) {
                return 1;
            }
            $target = app(VariantStockService::class)->defaultWarehouseId((int) $c->organization_id);
            $plan = (new TransferPlanner($c))->plan((string) $this->argument('name'), (int) $target);
            $this->info('Transfer '.($plan['transfer']['name'] ?? $this->argument('name')).': '.$plan['status']);
            foreach ($plan['plan'] as $line) {
                $this->line(' - '.$line);
            }
            if ($plan['operations'] !== []) {
                $this->line('Approve with: php artisan shopify:transfer-approve '.implode(' ', $plan['operations']));
            }

            return 0;
        })->purpose('Plan how a Shopify transfer reaches the main warehouse (changes nothing)');

        Artisan::command('shopify:transfer-approve {ids*} {--organization=}', function () use ($connection) {
            /** @var Command $this */
            $c = $connection($this);
            if ($c === null) {
                return 1;
            }
            $planner = new TransferPlanner($c);
            foreach ((array) $this->argument('ids') as $id) {
                $operation = ShopifyStockOperation::query()->where('organization_id', $c->organization_id)->findOrFail((int) $id);
                foreach ($planner->approve($operation) as $line) {
                    $this->line(" - {$line}");
                }
            }

            return 0;
        })->purpose('Carry out approved transfer plan steps');

        Artisan::command('shopify:purge-legacy-stock-jobs', function () {
            /** @var Command $this */
            $this->info(StockBootstrap::purgeLegacyJobs().' legacy stock job(s) removed from the queue.');

            return 0;
        })->purpose('Remove 1.x stock pushes waiting in the database queue');
    }
}
