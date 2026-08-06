<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Accounts\Models\ViewRequest;
use Splicewire\Beam\Beam;

/**
 * `beam_view_requests` — the polymorphic view-access request ledger behind
 * {@see ViewRequest} (ADR-0009, tracer 04, generalized).
 * Net-new, create-only, current-schema guarded (tenant-safe). Morph keys are strings to hold
 * both uuid- and bigint-keyed hosts.
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
            $table->uuid('id')->primary();
            $table->string('requestable_type');
            $table->string('requestable_id');
            $table->string('requester_type');
            $table->string('requester_id');
            $table->string('status')->default('pending');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['requestable_type', 'requestable_id', 'status']);
            $table->index(['requester_type', 'requester_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->target());
    }

    private function target(): string
    {
        return Beam::table('view_requests');
    }

    private function existsInCurrentSchema(string $schema, string $table): bool
    {
        return DB::selectOne(
            'select 1 from information_schema.tables where table_schema = ? and table_name = ?',
            [$schema, $table],
        ) !== null;
    }
};
