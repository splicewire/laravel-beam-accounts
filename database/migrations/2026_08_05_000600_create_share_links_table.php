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
 */
return new class extends Migration
{
    public function up(): void
    {
        $currentSchema = DB::selectOne('select current_schema() as schema')->schema;

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

    private function existsInCurrentSchema(string $schema, string $table): bool
    {
        return DB::selectOne(
            'select 1 from information_schema.tables where table_schema = ? and table_name = ?',
            [$schema, $table],
        ) !== null;
    }
};
