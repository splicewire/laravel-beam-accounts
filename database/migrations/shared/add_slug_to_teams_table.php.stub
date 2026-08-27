<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Splicewire\Beam\Facades\Beam;

/**
 * `beam_teams.slug` — the stable, globally-unique public name a `to_teams:` recipient selector in an
 * `x-beam-notify` keyword addresses (beam-facade 100 D5, built by 159). A selector authored into a
 * JSON Schema that travels between hosts cannot name an auto-increment key.
 *
 * ## Why the column is declared in TWO files
 *
 * `create_teams_table` declares it NULLABLE and this migration constrains it, because a NOT NULL
 * column with no default cannot be added to a POPULATED table and that create guard is CONVERGENT: on
 * a host whose `beam_teams` already holds rows, a NOT NULL declaration there raises
 * `SchemaConflict::REQUIRED_ADDITION` and the host simply stops migrating. Nullable converges
 * everywhere, so greenfield and populated hosts walk the same two files and differ only in how much
 * the backfill has to do.
 *
 * The three steps are the estate's standing repair for this hazard (beam-facade 27): **add nullable,
 * backfill, constrain.** Each is individually guarded, so a host that has already taken part of it
 * (its create ran with the column, or a previous run of this migration got partway) skips what it has.
 *
 * ## The backfill mirrors the model, and cannot call it
 *
 * {@see \Splicewire\Beam\Accounts\Models\Team::slugSource()} derives a personal team's slug from the
 * OWNER's handle and everything else from the team name. This restates that in SQL-safe terms rather
 * than booting the model, because a migration runs against whatever schema exists at that moment and a
 * model carrying a global scope, a cast or an accessor from a later state of the package would read a
 * table that does not exist yet. The duplication is deliberate and bounded: it runs once per host and
 * the model owns every slug written after it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $teams = Beam::table('teams');

        if (! Schema::hasTable($teams)) {
            return;
        }

        if (! Schema::hasColumn($teams, 'slug')) {
            Schema::table($teams, function (Blueprint $table): void {
                $table->string('slug')->nullable()->after('name');
            });
        }

        $this->backfill($teams);

        if (! $this->hasSlugIndex($teams)) {
            Schema::table($teams, function (Blueprint $table): void {
                $table->unique('slug');
            });
        }

        // NOT NULL last: every row has a slug by now, so the constraint can be taken. `change()` on
        // sqlite rebuilds the table, which is why it comes AFTER the backfill and not before it.
        Schema::table($teams, function (Blueprint $table): void {
            $table->string('slug')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        $teams = Beam::table('teams');

        if (Schema::hasColumn($teams, 'slug')) {
            Schema::table($teams, function (Blueprint $table): void {
                $table->dropColumn('slug');
            });
        }
    }

    /**
     * Give every slug-less row a slug, uniquified in PHP because the unique index is added right after
     * and a collision here would take the whole migration down on a host that has two teams of the
     * same name — which is the normal state, not an edge case: `personalTeamName()` renders
     * "{name}'s Team" for everybody.
     */
    private function backfill(string $teams): void
    {
        $taken = DB::table($teams)->whereNotNull('slug')->pluck('slug')->all();
        $taken = array_flip(array_map('strval', $taken));

        DB::table($teams)->whereNull('slug')->orderBy('id')->each(function (object $team) use ($teams, &$taken): void {
            $base = Str::slug($this->source($team)) ?: 'team';
            $slug = $base;
            $n = 1;

            while (isset($taken[$slug])) {
                $slug = $base.'-'.$n++;
            }

            $taken[$slug] = true;

            DB::table($teams)->where('id', $team->id)->update(['slug' => $slug]);
        });
    }

    /**
     * The owner's handle for a personal team, the team's own name otherwise — the model's rule, read
     * straight off the row and its owner rather than through Eloquent.
     */
    private function source(object $team): string
    {
        if (! ($team->personal_team ?? false)) {
            return (string) ($team->name ?? '');
        }

        $owner = Schema::hasTable('users')
            ? DB::table('users')->where('id', $team->user_id)->first()
            : null;

        $email = $owner->email ?? null;
        $handle = $email !== null ? strstr((string) $email, '@', true) : ($owner->name ?? null);

        return (string) ($handle ?: ($team->name ?? ''));
    }

    private function hasSlugIndex(string $teams): bool
    {
        foreach (Schema::getIndexes($teams) as $index) {
            if (($index['unique'] ?? false) && ($index['columns'] ?? []) === ['slug']) {
                return true;
            }
        }

        return false;
    }
};
