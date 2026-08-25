<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Accounts\Enums\TokenProvenance;

/**
 * Squashed pre-prod (no deployed data to preserve migration history for) from the original 2
 * additive ALTERs on `personal_access_tokens` into ONE alter-only stub: `provenance` (composition-
 * authoring-ux ticket 10 — separate user-created API tokens from session/browser tokens, with a
 * one-time backfill of existing rows via best-effort inference) + `archived_at` (pat-permission-scope
 * — archive instead of hard-delete, so revoking a token retains its record for audit rather than
 * erasing it; archiving also neutralizes the stored hash at the application layer so an archived
 * token can never authenticate again).
 *
 * ALTER-ONLY, CENTRAL-ONLY: no tenant twin (the table doesn't exist on the tenant estate at all, so
 * there is nothing to classify "shared" against).
 *
 * CORRECTED at beam-docs-satellite ticket 25. This docblock used to say the table "is created by
 * Sanctum's own migration, not this package's". Sanctum v4 CREATES NOTHING — its provider calls
 * `publishesMigrations()` and never `loadMigrationsFrom()` — so on a host that never published
 * Sanctum's copy there was no table, no error, and the guard below skipped this ALTER forever. The
 * CREATE is now this package's too (`create_personal_access_tokens_table.php.stub`, listed
 * immediately before this file), which is what makes the guard's skip case rare rather than routine.
 */
return new class extends Migration
{
    public function up(): void
    {
        // GUARDED on the table's EXISTENCE, not its columns: a host that has neither this package's
        // create nor a published Sanctum copy has no table to alter, and this file would otherwise
        // fatal `migrate:fresh` on every such host (beam-facade ticket 39, at `~/Herd/schemastud`).
        //
        // The ordering argument this comment used to make was WRONG (ticket 25): Sanctum's stamp is
        // `2019_12_14_000001`, not the `0001_01_01_*` band. The conclusion survives on a different
        // and actually true premise — every create that can supply this table sorts before it. Ours
        // is listed immediately above this file in the same publish run (package-tools stamps in
        // listed order), and Sanctum's 2019 stamp precedes any publish-time `now()`.
        if (! Schema::hasTable('personal_access_tokens')) {
            return;
        }

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('provenance')->nullable()->after('abilities');
            $table->timestamp('archived_at')->nullable()->after('last_used_at');
        });

        // One-time backfill of existing rows' provenance. Mirrors the original table migration's bare
        // (default-connection) access — the tokens table lives on that same physical DB.
        DB::table('personal_access_tokens')
            ->select('id', 'name', 'abilities')
            ->orderBy('id')
            ->each(function ($row) {
                $abilities = json_decode($row->abilities ?? '[]', true) ?: [];

                DB::table('personal_access_tokens')
                    ->where('id', $row->id)
                    ->update(['provenance' => TokenProvenance::infer($row->name ?? '', $abilities)->value]);
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            return;
        }

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['provenance', 'archived_at']);
        });
    }
};
