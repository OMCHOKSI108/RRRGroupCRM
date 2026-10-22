<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('timezone');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->index(['tenant_id', 'latitude', 'longitude'], 'leads_tenant_coords_idx');
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('zip_code');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->index(['tenant_id', 'latitude', 'longitude'], 'properties_tenant_coords_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex('leads_tenant_coords_idx');
            $table->dropColumn(['latitude', 'longitude']);
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex('properties_tenant_coords_idx');
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
