<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Beam;

/**
 * Frame OS ticket 20 — the invitation LIFECYCLE columns the promoted Invitations resource reads:
 * `invited_by` (who sent it) + `accepted_at` (null = pending; the resource's revoke scope is
 * pending-only). The team-of-one accept flow (`TeamMembers::accept`) deletes the row rather than
 * stamping `accepted_at`, so these were absent; the Frame list/revoke surface needs a pending marker.
 * Additive + nullable — every existing invitation stays valid (pending), no backfill needed.
 *
 * NOT squashed into `create_invitations_table` — this whole `teams/` directory converts 1:1
 * (extension/location/registration only, logic untouched): `create_invitations_table`'s
 * data-preserving rename already ran against real deployed data, so folding this ALTER into a
 * "clean create" would silently skip landing these columns on a host that already ran the original
 * migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = Beam::table('invitations');

        if (! Schema::hasColumn($table, 'invited_by')) {
            Schema::table($table, function (Blueprint $t): void {
                $t->unsignedBigInteger('invited_by')->nullable();
            });
        }

        if (! Schema::hasColumn($table, 'accepted_at')) {
            Schema::table($table, function (Blueprint $t): void {
                $t->timestamp('accepted_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        $table = Beam::table('invitations');

        Schema::table($table, function (Blueprint $t) use ($table): void {
            foreach (['invited_by', 'accepted_at'] as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $t->dropColumn($column);
                }
            }
        });
    }
};
