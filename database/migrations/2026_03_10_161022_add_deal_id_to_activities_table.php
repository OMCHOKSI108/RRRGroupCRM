<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->foreignId('deal_id')->nullable()->after('lead_id')->constrained('deals')->onDelete('cascade');
        });

        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            // Make lead_id nullable (activities can belong to a deal instead)
            DB::statement('ALTER TABLE activities MODIFY COLUMN lead_id BIGINT UNSIGNED NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE activities ALTER COLUMN lead_id DROP NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropForeign(['deal_id']);
            $table->dropColumn('deal_id');
        });

        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE activities MODIFY COLUMN lead_id BIGINT UNSIGNED NOT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE activities ALTER COLUMN lead_id SET NOT NULL');
        }
    }
};
