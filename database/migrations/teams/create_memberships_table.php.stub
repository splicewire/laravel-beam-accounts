<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Beam;

/**
 * The memberships table. Formerly `memberships`, renamed `memberships` → `beam_memberships`
 * (beam-particle-rename ticket 04), routed through the single table-prefix seam {@see Beam::table()}.
 *
 * Data-preserving rename (NOT drop+create): where the pre-rename `memberships` already exists in THIS
 * schema, it is renamed in place, preserving every row and its FK to `beam_teams` (Postgres keeps the
 * reference valid across a parent rename). A fresh install (neither table present) creates the target
 * directly with the FK pointed at the PREFIXED parent (`beam_teams`) — the create-branch footgun this
 * slice fixes. Guarded on the CURRENT schema explicitly (the CLAUDE.md footgun) so the logic is
 * order-independent and idempotent.
 *
 * NOT squashed with anything — this whole `teams/` directory converts 1:1 (extension/location/
 * registration only, logic untouched): the data-preserving rename above already ran against real
 * deployed data.
 */
return new class extends Migration
{
    public function up(): void
    {
        $currentSchema = DB::getDriverName() === 'pgsql'
            ? DB::selectOne('select current_schema() as schema')->schema
            : null;

        if ($this->existsInCurrentSchema($currentSchema, $this->target())) {
            return; // Already renamed / created in this schema — idempotent re-run.
        }

        // Data-preserving rename of the pre-rename table when it is present in THIS schema.
        if ($this->existsInCurrentSchema($currentSchema, 'memberships')) {
            Schema::rename('memberships', $this->target());

            return;
        }

        // Fresh install: neither name present in this schema — create the target directly, with the
        // FK pointed at the PREFIXED parent table.
        Schema::create($this->target(), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained(Beam::table('teams'))->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->string('role')->default('member');
            $table->timestamps();

            $table->unique(['team_id', 'user_id']);
        });
    }

    public function down(): void
    {
        $currentSchema = DB::getDriverName() === 'pgsql'
            ? DB::selectOne('select current_schema() as schema')->schema
            : null;

        // Reverse the rename when the target is the one present in this schema.
        if ($this->existsInCurrentSchema($currentSchema, $this->target())) {
            Schema::rename($this->target(), 'memberships');
        }
    }

    private function target(): string
    {
        return Beam::table('memberships');
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
