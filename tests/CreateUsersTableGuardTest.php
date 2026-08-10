<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * beam-install-turnkey trap 3: the accounts `create_users_table` migration is guarded with a
 * `hasTable('users')` check so a host that already owns a `users` table (any `laravel new` derivative,
 * typically bigint-keyed) keeps its own table while still getting the rest of the auth estate. A fresh host
 * with no `users` table still gets the package's uuid-keyed one.
 */
function usersMigration(): object
{
    return require __DIR__.'/../database/migrations/2014_10_12_000000_create_users_table.php';
}

it('creates the uuid-keyed users table when none exists', function () {
    Schema::dropIfExists('users');

    expect(Schema::hasTable('users'))->toBeFalse();

    usersMigration()->up();

    expect(Schema::hasTable('users'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'id'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'email'))->toBeTrue();
});

it('SKIPS creating users when the host already owns a users table', function () {
    // Simulate a host's pre-existing (bigint-keyed) users table.
    Schema::dropIfExists('users');
    Schema::create('users', function (Blueprint $table) {
        $table->id(); // bigint auto-increment — the shape the package migration must NOT clobber
        $table->string('email')->unique();
        $table->string('host_only_column')->nullable();
        $table->timestamps();
    });

    // Running the guarded package migration must be a no-op — it must not throw ("table already exists")
    // and must leave the host's own columns intact.
    usersMigration()->up();

    expect(Schema::hasColumn('users', 'host_only_column'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'password'))->toBeFalse(); // the package migration's column never landed
});
