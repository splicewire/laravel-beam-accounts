<?php

namespace Splicewire\Beam\Accounts\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Features;
use Laravel\Fortify\FortifyServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\PermissionCascade\PermissionCascadeServiceProvider;
use Spatie\Permission\PermissionServiceProvider;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Splicewire\Beam\Beam;
use Splicewire\Beam\BeamServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createUsersSchema();
        $this->createTeamsSchema();
        $this->createSpatieSchema();
    }

    protected function getPackageProviders($app): array
    {
        return [
            PermissionServiceProvider::class,
            PermissionCascadeServiceProvider::class,
            FortifyServiceProvider::class,
            // beam-core, so its BeamSeedManifest singleton binds — beam-accounts registers its
            // DemoTeamSeeder into it (bootSeed). beam-accounts hard-deps beam-core in composition.
            BeamServiceProvider::class,
            BeamAccountsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        $config->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $config->set('auth.providers.users.model', User::class);
        $config->set('permission-cascade.user_model', User::class);

        $config->set('session.driver', 'array');

        // laravel-data's package config isn't merged under Testbench, so its defaults come through
        // null/absent and trip a TypeError when a Data class is transformed (->toArray) or hydrated
        // (::from). Load the package's full default config so both work in the isolated test app.
        $config->set('data', require dirname(__DIR__).'/vendor/spatie/laravel-data/config/data.php');
        $config->set('data.max_transformation_depth', null);
        $config->set('data.throw_when_max_transformation_depth_reached', true);

        $config->set('fortify.guard', 'web');
        $config->set('fortify.home', '/');
        $config->set('fortify.views', false);
        $config->set('fortify.features', [
            Features::registration(),
            Features::resetPasswords(),
            Features::emailVerification(),
            Features::updatePasswords(),
        ]);
    }

    protected function createUsersSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('current_team_id')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    protected function createTeamsSchema(): void
    {
        Schema::create(Beam::table('teams'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->boolean('personal_team')->default(false);
            $table->timestamps();
        });

        Schema::create(Beam::table('memberships'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role')->default('member');
            $table->timestamps();
            $table->unique(['team_id', 'user_id']);
        });

        Schema::create(Beam::table('invitations'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->string('email');
            $table->string('role')->default('member');
            $table->string('token')->unique();
            $table->timestamps();
            $table->unique(['team_id', 'email']);
        });

        Schema::create(Beam::table('share_links'), function (Blueprint $table): void {
            $table->id();
            $table->string('token')->unique();
            $table->string('scope')->index();
            $table->string('created_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('use_count')->default(0);
            $table->unsignedInteger('max_uses')->nullable();
            $table->timestamps();
        });

        Schema::create(Beam::table('access_grants'), function (Blueprint $table): void {
            $table->id();
            $table->string('grantable_type');
            $table->string('grantable_id');
            $table->string('grantee_type');
            $table->string('grantee_id');
            $table->string('ability');
            $table->string('effect');
            $table->timestamps();
        });

        Schema::create(Beam::table('view_requests'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('requestable_type');
            $table->string('requestable_id');
            $table->string('requester_type');
            $table->string('requester_id');
            $table->string('status')->default('pending');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        // A HasVisibility fixture to share (steward via HasUserId).
        Schema::create('shareables', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('visibility')->nullable();
            $table->timestamps();
        });
    }

    protected function createSpatieSchema(): void
    {
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['team_id', 'name', 'guard_name']);
        });

        Schema::create('model_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->unsignedBigInteger('team_id')->nullable();
            $table->index(['model_id', 'model_type']);
            $table->primary(['team_id', 'permission_id', 'model_id', 'model_type']);
        });

        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->unsignedBigInteger('team_id')->nullable();
            $table->index(['model_id', 'model_type']);
            $table->primary(['team_id', 'role_id', 'model_id', 'model_type']);
        });

        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
        });
    }
}
