<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Accounts\Models\ShareLink;
use Splicewire\Beam\Beam;

/**
 * `beam_share_links` — the reusable capability-link ledger behind
 * {@see ShareLink} (ADR-0009, tracer 05). Net-new, so
 * create-only; guarded on the CURRENT schema (the tenant-schema footgun) so it is idempotent
 * and tenant-safe. `created_by` is a string to hold both uuid- and bigint-keyed hosts.
 *
 * TEAMS-estate, converts 1:1 alongside the rest of `teams/` (no squashing across this directory).
 */
return new class extends Migration
{
    public function up(): void
    {
        $currentSchema = DB::getDriverName() === 'pgsql'
            ? DB::selectOne('select current_schema() as schema')->schema
            : null;

        if ($this->existsInCurrentSchema($currentSchema, $this->target())) {
            return; // Idempotent re-run.
        }

        Schema::create($this->target(), function (Blueprint $table): void {
            $table->id();
            $table->string('token')->unique();
            $table->string('scope')->index();          // opaque host scope, e.g. composition:{uuid}
            $table->string('created_by')->nullable();  // minter user key (string — cross-host)
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('use_count')->default(0);
            $table->unsignedInteger('max_uses')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->target());
    }

    private function target(): string
    {
        return Beam::table('share_links');
    }

    /**
     * Outside Postgres there is no per-connection "current schema"/search_path concern (the
     * CLAUDE.md footgun this guard exists for in the first place) — sqlite/mysql only ever see one
     * namespace per connection, so a plain {@see Schema::hasTable()} is exact and driver-safe there.
     */
    private function existsInCurrentSchema(?string $schema, string $table): bool
    {
        if ($schema === null) {
            return Schema::hasTable($table);
        }

        return DB::selectOne(
            'select 1 from information_schema.tables where table_schema = ? and table_name = ?',
            [$schema, $table],
        ) !== null;
    }
};
