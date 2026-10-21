<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            // SQLite columns are inherently nullable unless defined with NOT NULL in the original CREATE.
            // Since SQLite doesn't support ALTER COLUMN, and fresh SQLite schemas already have these nullable,
            // this is a no-op for SQLite (used in testing).
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE leads ALTER COLUMN agent_id DROP NOT NULL');
            DB::statement('ALTER TABLE deals ALTER COLUMN agent_id DROP NOT NULL');
            return;
        }

        // Make agent_id nullable on leads (API-ingested leads have no agent yet)
        DB::statement('ALTER TABLE leads MODIFY COLUMN agent_id BIGINT UNSIGNED NULL');

        // Make agent_id nullable on deals (deals from API may not have an agent)
        DB::statement('ALTER TABLE deals MODIFY COLUMN agent_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE leads ALTER COLUMN agent_id SET NOT NULL');
            DB::statement('ALTER TABLE deals ALTER COLUMN agent_id SET NOT NULL');
            return;
        }

        DB::statement('ALTER TABLE leads MODIFY COLUMN agent_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE deals MODIFY COLUMN agent_id BIGINT UNSIGNED NOT NULL');
    }
};
