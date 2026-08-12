<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Rushing\PermissionCascade\Contracts\AccessGrant;
use Splicewire\Beam\Beam;

/**
 * The directory-ACL grant ledger — `beam_access_grants`, the table behind
 * {@see Splicewire\Beam\Accounts\Models\AccessGrant} (permission-cascade's
 * {@see AccessGrant}). Net-new, so create-only; guarded on
 * the CURRENT schema (the tenant-schema footgun) so it is idempotent and tenant-safe.
 *
 * `grantable_id` AND `grantee_id` are strings — morph keys that must hold both uuid-keyed
 * hosts (beam users/threads) and bigint-keyed hosts (audiostud users/roles) without a
 * per-host column type. `ability` ∈ {view, manage}; `effect` ∈ {allow, deny}.
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
            $table->string('grantable_type');
            $table->string('grantable_id');   // morph key (uuid or bigint) — string for cross-host
            $table->string('grantee_type');
            $table->string('grantee_id');     // morph key (uuid or bigint) — string for cross-host
            $table->string('ability');                 // view | manage
            $table->string('effect');                  // allow | deny
            $table->timestamps();

            $table->index(['grantable_type', 'grantable_id']);
            $table->index(['grantee_type', 'grantee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->target());
    }

    private function target(): string
    {
        return Beam::table('access_grants');
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
