<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Accounts\Enums\TokenProvenance;

/**
 * composition-authoring-ux ticket 10 — separate user-created API tokens from session/browser
 * tokens. Adds a `provenance` column stamped at every creation site, and backfills the rows
 * that already exist (the browser/session/dev churn) ONCE via best-effort inference so the
 * settings Tokens page gets correct type chips immediately. The column is authoritative after
 * this migration; the inference lives here only, not at read time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('provenance')->nullable()->after('abilities');
        });

        // One-time backfill of existing rows. Mirrors the original table migration's bare
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
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('provenance');
        });
    }
};
