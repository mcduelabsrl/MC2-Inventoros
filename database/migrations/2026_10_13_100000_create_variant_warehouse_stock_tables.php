<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock of each variant in each warehouse, split by who owns the units.
 *
 * Until now a variant had one number (product_variants.stock) with no
 * warehouse breakdown. These tables hold the real figures; the variant's
 * stock column becomes a cache of on_hand - committed summed over its rows,
 * so every existing total, report and low-stock check keeps working.
 *
 * - stock_owners: the companies that can own units (e.g. MC2, Saxone).
 * - variant_stock_levels: one row per (variant, warehouse, owner); owner
 *   NULL means "ownership to be assigned". owner_key mirrors the owner id
 *   (0 for none) so the unique key works on every database.
 *   on_hand is everything physically there; committed, quality_control,
 *   damaged and reserved are parts of on_hand; available is computed
 *   (on_hand - committed - quality_control - damaged - reserved). incoming is
 *   on its way and never part of on_hand.
 * - variant_stock_operations: one row per business operation, with an
 *   optional idempotency key so the same operation is never applied twice.
 * - variant_stock_movements: the ledger, one row per bucket change.
 * - order_item_allocations: which warehouse and owner each sold unit comes
 *   from, kept through fulfilment and return.
 * - variant_stock_transfers (+ lines): immediate moves between warehouses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_owners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('variant_stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_owner_id')->nullable()->constrained('stock_owners')->nullOnDelete();
            $table->unsignedBigInteger('owner_key')->default(0);
            $table->integer('on_hand')->default(0);
            $table->integer('committed')->default(0);
            $table->integer('quality_control')->default(0);
            $table->integer('damaged')->default(0);
            $table->integer('reserved')->default(0);
            $table->integer('incoming')->default(0);
            $table->timestamps();

            $table->unique(['product_variant_id', 'warehouse_id', 'owner_key'], 'variant_stock_levels_unique');
            $table->index(['organization_id', 'warehouse_id']);
            $table->index(['organization_id', 'product_id']);
        });

        Schema::create('variant_stock_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            // local: started in Inventoros; external: reported by a sales
            // channel (already true there, must not be sent back); import:
            // opening balances.
            $table->string('source', 20)->default('local');
            $table->string('idempotency_key', 191)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('transfer_id')->nullable();
            $table->unsignedBigInteger('return_order_id')->nullable();
            // absolute: the operator stated a count; delta: a relative change.
            $table->string('mode', 10)->default('delta');
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'idempotency_key']);
            $table->index(['organization_id', 'type']);
            $table->index('order_id');
        });

        Schema::create('variant_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_stock_operation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_stock_level_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_owner_id')->nullable()->constrained('stock_owners')->nullOnDelete();
            $table->string('bucket', 20);
            $table->integer('delta');
            $table->integer('before');
            $table->integer('after');
            $table->timestamp('created_at')->nullable();

            $table->index(['product_variant_id', 'warehouse_id']);
            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('variant_stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 32);
            $table->foreignId('from_warehouse_id')->constrained('warehouses');
            $table->foreignId('to_warehouse_id')->constrained('warehouses');
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // ready: prepared, units set aside at the origin; in_transit:
            // left the origin, incoming at the destination; completed: all
            // received (or refused); cancelled: called off before leaving.
            // Each step is one operator confirmation. A consolidation for an
            // order is completed at once. channel_status says whether the
            // sales channel has confirmed the latest step.
            $table->string('status', 20)->default('ready');
            $table->string('channel_status', 20)->default('not_applicable');
            $table->text('channel_error')->nullable();
            $table->text('notes')->nullable();
            $table->string('carrier')->nullable();
            $table->string('tracking_number')->nullable();
            $table->foreignId('shipped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'reference']);
        });

        Schema::create('variant_stock_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('stock_owner_id')->nullable()->constrained('stock_owners')->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('received_quantity')->default(0);
            // Units that did not arrive or arrived unusable: they never
            // enter the destination's stock.
            $table->unsignedInteger('rejected_quantity')->default(0);
            // Units moved while committed to an order (consolidation): they
            // stay committed at the destination.
            $table->boolean('committed')->default(false);
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('order_item_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained();
            $table->foreignId('stock_owner_id')->nullable()->constrained('stock_owners')->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('fulfilled_quantity')->default(0);
            $table->unsignedInteger('released_quantity')->default(0);
            $table->unsignedInteger('returned_quantity')->default(0);
            // Units committed with less available than needed (a channel
            // sold them anyway): shown as an anomaly until fixed.
            $table->unsignedInteger('shortfall_quantity')->default(0);
            $table->timestamps();

            $table->index(['order_item_id']);
            $table->index(['organization_id', 'warehouse_id']);
        });

        Schema::table('variant_stock_operations', function (Blueprint $table) {
            $table->foreign('transfer_id')->references('id')->on('variant_stock_transfers')->nullOnDelete();
            $table->foreign('return_order_id')->references('id')->on('return_orders')->nullOnDelete();
        });

        // A shipment a sales channel reports as already made (e.g. a Shopify
        // fulfilment): its units left from where the channel says, so it is
        // not held back waiting for consolidation.
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('reported_by_channel', 40)->nullable()->after('carrier');
        });

        Schema::table('stock_adjustment_requests', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->nullable()->after('location_id')->constrained()->nullOnDelete();
            $table->foreignId('stock_owner_id')->nullable()->after('warehouse_id')->constrained('stock_owners')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('reported_by_channel');
        });

        Schema::table('stock_adjustment_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_owner_id');
            $table->dropConstrainedForeignId('warehouse_id');
        });

        Schema::table('variant_stock_operations', function (Blueprint $table) {
            $table->dropForeign(['transfer_id']);
            $table->dropForeign(['return_order_id']);
        });

        Schema::dropIfExists('order_item_allocations');
        Schema::dropIfExists('variant_stock_transfer_lines');
        Schema::dropIfExists('variant_stock_transfers');
        Schema::dropIfExists('variant_stock_movements');
        Schema::dropIfExists('variant_stock_operations');
        Schema::dropIfExists('variant_stock_levels');
        Schema::dropIfExists('stock_owners');
    }
};
