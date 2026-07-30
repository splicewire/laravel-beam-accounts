<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Beam;

/**
 * The teams table. Formerly `teams`, renamed `teams` → `beam_teams` (beam-particle-rename ticket 04),
 * routed through the single table-prefix seam {@see Beam::table()}.
 *
 * Data-preserving rename (NOT drop+create): where the pre-rename `teams` already exists in THIS
 * schema, it is renamed in place, preserving every row (Postgres re-points child FKs to the renamed
 * table automatically, so `beam_memberships`/`beam_invitations` stay valid). A fresh install (neither
 * table present) creates the target directly. Guarded on the CURRENT schema explicitly (not
 * Schema::hasTable, which follows the tenant search_path — the CLAUDE.md footgun) so the guarded logic
 * is order-independent and idempotent across re-runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        $currentSchema = DB::selectOne('select current_schema() as schema')->schema;

        if ($this->existsInCurrentSchema($currentSchema, $this->target())) {
            return; // Already renamed / created in this schema — idempotent re-run.
        }

        // Data-preserving rename of the pre-rename table when it is present in THIS schema.
        if ($this->existsInCurrentSchema($currentSchema, 'teams')) {
            Schema::rename('teams', $this->target());

            return;
        }

        // Fresh install: neither name present in this schema — create the target directly.
        Schema::create($this->target(), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('name');
            $table->boolean('personal_team')->default(false)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $currentSchema = DB::selectOne('select current_schema() as schema')->schema;

        // Reverse the rename when the target is the one present in this schema.
        if ($this->existsInCurrentSchema($currentSchema, $this->target())) {
            Schema::rename($this->target(), 'teams');
        }
    }

    private function target(): string
    {
        return Beam::table('teams');
    }

    private function existsInCurrentSchema(string $schema, string $table): bool
    {
        return DB::selectOne(
            'select 1 from information_schema.tables where table_schema = ? and table_name = ?',
            [$schema, $table],
        ) !== null;
    }
};
