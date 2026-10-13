<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock audits of variants per warehouse: the audit names the warehouse it
 * counts, and each line the warehouse of its variant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_audits', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->nullable()->after('warehouse_location_id')->constrained('warehouses')->nullOnDelete();
        });

        Schema::table('stock_audit_items', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->nullable()->after('location_id')->constrained('warehouses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_audit_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_id');
        });

        Schema::table('stock_audits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_id');
        });
    }
};
