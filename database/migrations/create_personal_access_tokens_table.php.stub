<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rushing\SchemaConvergence\ConvergentTable;

/**
 * Sanctum's `personal_access_tokens`, ADOPTED rather than owned — the same relationship (and the same
 * quiet terminal) as `shared/create_users_table.php.stub`.
 *
 * WHY THIS EXISTS AT ALL (beam-docs-satellite ticket 25). Nobody in the estate owned this CREATE.
 * `laravel/sanctum` v4 only ever PUBLISHES its copy — `SanctumServiceProvider` calls
 * `publishesMigrations()` and never `loadMigrationsFrom()`, and the v4 `Sanctum::shouldRunMigrations()`
 * branch is gone — so on a host that never ran `vendor:publish --tag=sanctum-migrations` the table
 * simply does not exist. This package's `add_provenance_and_archived_to_personal_access_tokens_table`
 * then no-opped through its own `hasTable` guard, silently, on every such host, forever. Two of the
 * three starters were in exactly that state.
 *
 * CENTRAL-ONLY (bare, not shared/): tokens are minted against central users. Matches the provenance
 * ALTER's placement, which this file must precede — it is listed immediately before it in
 * `BeamAccountsServiceProvider::configurePackage()`.
 *
 * THE KEY TYPE IS THE HOST'S, DELIBERATELY. This declares Sanctum's stock bigint shape.
 * `key-type.convention.md` names uuid as a LIST (`beam.core.schema.uuid_tables`) with an explicit
 * carve-out — "a third-party table vendored verbatim keeps its upstream shape" — and this is the
 * textbook case of that carve-out. A host that wants uuid tokens (every splicewire-operated host does)
 * commits its own uuid-keyed create and binds a `HasUuids` subclass through
 * `beam.accounts.tokens.model`; the `matches()` terminal below treats that as the legitimate host
 * shape it is rather than an install-time stop. Note the id is not merely a primary key: a Sanctum
 * bearer string IS `{id}|{plaintext}` and `findToken()` looks the row up BY KEY, so the id is a
 * wire-format field and changing its type is a credential-format change, not a schema tidy.
 *
 * THE MORPH IS NOT THE HOST'S, AND THAT IS THE ONE PLACE THIS FILE DEPARTS FROM SANCTUM. Stock
 * Sanctum declares `$table->morphs('tokenable')` — a bigint `tokenable_id`. A `tokenable` here is a
 * User, and the User table is created by THIS PACKAGE's sibling stub
 * `shared/create_users_table.php.stub`, which declares `$table->uuid('id')->primary()`. So on a fresh
 * install of this estate the stock morph emits a column that can never hold the key the sibling stub
 * just wrote, and the two package files contradict each other before any host has had a say. The
 * "vendored verbatim" carve-out above covers the parts of this table Sanctum OWNS — its `id`, its
 * token string, its ability payload — and does not reach a foreign key whose referent is a
 * package-created table. `uuidMorphs` it is.
 *
 * Confirmed against the estate rather than reasoned alone: both hosts that actually have this table
 * (`splicewire/splicewire-app` and `rushing/laravel-tower-starter`) report `tokenable_id = uuid`,
 * having each diverged from the stock stub in this same direction independently.
 *
 * The residual case is a host whose `users` predates this package and is bigint-keyed — the same host
 * the sibling stub's quiet terminal deliberately leaves alone. Such a host must commit its own create
 * for this table too, exactly as a uuid-`id` host already does; the `matches()` terminal below then
 * sees a shape it cannot converge and stays quiet, which is the behaviour that case already relied on.
 */
return new class extends Migration
{
    /**
     * THE QUIET TERMINAL, deliberately (convergent-migration-guards convention). Convergence handles
     * ABSENCE, never CONFLICT — a uuid-keyed host `personal_access_tokens` has `id` present-and-wrong-
     * type, which `assert()` would turn into a hard install stop on a table this package does not own.
     * `matches()` converges what it can, writes no DDL when it cannot, and never throws.
     */
    public function up(): void
    {
        ConvergentTable::named('personal_access_tokens')
            ->define(function (Blueprint $table) {
                $table->id();

                // uuid, NOT Sanctum's stock `morphs()` — see the docblock. The referent is this
                // package's own uuid-keyed `users`, so a bigint `tokenable_id` could never hold it.
                $table->uuidMorphs('tokenable');
                $table->text('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
            })
            ->matches();
    }

    /**
     * Deliberately NOT a `dropIfExists`. This migration adopts a table it may not have created — on a
     * host that published Sanctum's copy, or committed its own uuid-keyed one, rolling this back would
     * drop a table another migration owns and believes it still has.
     */
    public function down(): void
    {
        // no-op — see the docblock above.
    }
};
