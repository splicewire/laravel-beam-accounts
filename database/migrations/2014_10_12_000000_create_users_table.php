<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * GUARDED (beam-install-turnkey trap 3): skip when a `users` table already exists. The estate's auth
     * schema (permission tables, PATs, passkeys, google_id) is gated all-or-nothing by
     * `beam.accounts.register_auth_migrations`, but that flag can't express "everything BUT users" — so a
     * host that already owns a `users` table (any `laravel new` derivative, typically bigint-keyed) would
     * collide with this uuid-keyed create. The per-migration `hasTable` guard lets such a host keep its own
     * users table while STILL getting the rest of the auth estate (the config flag stays on). A fresh host
     * with no users table still gets this uuid-keyed one. Idempotent: safe on re-run and on `migrate:fresh`.
     */
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            return;
        }

        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
