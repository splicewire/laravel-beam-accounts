<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOT squashed into `create_teams_table` (or any shared `users` create) — this whole `teams/`
 * directory converts 1:1 (extension/location/registration only, logic untouched). The teams chain's
 * renames already ran against real deployed data; folding this ALTER into a "clean create" would
 * silently skip landing `current_team_id` on a host that already ran the original migrations.
 * TEAMS-estate, central-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('current_team_id')->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('current_team_id');
        });
    }
};
