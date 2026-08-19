<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rushing\SchemaConvergence\ConvergentTable;
use Splicewire\Beam\Accounts\Models\ImpersonationEvent;
use Splicewire\Beam\Facades\Beam;

/**
 * `beam_impersonation_events` — the append-only operator-impersonation audit trail behind
 * {@see ImpersonationEvent} (particle-identity-resources ticket 03).
 *
 * Actor and subject are STRINGS with no foreign keys, unlike the two host tables this was lifted
 * from: audiostud declared `actor_id` as `foreignUuid`, numero as `foreignId`, and a package table
 * serving both cannot pick one. The same reasoning `shared/create_view_requests_table` gives for its
 * morph keys. A host that wants referential integrity keeps its own typed table and points
 * `beam.accounts.impersonation.table` at it rather than publishing this.
 *
 * No `updated_at`: the row is written once and never edited, which is the whole value of an audit
 * trail. `created_at` alone, matching {@see ImpersonationEvent::UPDATED_AT} being null.
 */
return new class extends Migration
{
    public function up(): void
    {
        ConvergentTable::named($this->target())
            ->define(function (Blueprint $table): void {
                $table->id();
                $table->string('actor_id');
                $table->string('subject_id')->nullable();
                $table->string('action');
                $table->timestamp('created_at')->nullable();

                // The two questions this table is asked: "what did this operator do" and "who has
                // been impersonating this customer".
                $table->index(['actor_id', 'created_at']);
                $table->index(['subject_id', 'created_at']);
            })
            ->assert();
    }

    public function down(): void
    {
        Schema::dropIfExists($this->target());
    }

    private function target(): string
    {
        return config('beam.accounts.impersonation.table')
            ?: Beam::table('impersonation_events');
    }
};
