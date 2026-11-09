<?php

namespace Splicewire\Beam\Accounts\Tests;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Splicewire\Beam\Accounts\Models\User;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * The `tokens` Frame resource drops only where a host declares the auth estate absent AND its user model cannot own
 * tokens (no `HasApiTokens`). `rushing/stephenrushing` is both, and its manifest advertised a `tokens` list that answered
 * 500 (2026-11-09). Either fact alone keeps it: `~/Herd/beam` and `~/Herd/satellite` declare the estate absent but own
 * their PAT tables (codex review ba-estate), and this package's fixture user has no `HasApiTokens` but has the table.
 */
class FrameTokensFollowAuthEstateTest extends TestCase
{
    /** @var array<string, mixed> */
    private static array $config = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        foreach (self::$config as $key => $value) {
            $app['config']->set($key, $value);
        }
    }

    protected function tearDown(): void
    {
        self::$config = [];
        parent::tearDown();
    }

    private function bootWith(array $config): ParticleResourceRegistry
    {
        self::$config = $config;
        $this->refreshApplication();

        return app(ParticleResourceRegistry::class);
    }

    public function test_tokens_is_not_registered_when_the_estate_is_absent_and_the_user_cannot_own_tokens(): void
    {
        $registry = $this->bootWith(['beam.accounts.user_model' => TokenlessUser::class, 'beam.accounts.publish_auth_migrations' => 'absent']);

        $this->assertFalse($registry->has('tokens'));
        $this->assertTrue($registry->has('invitations'));
        $this->assertTrue($registry->has('members'));
    }

    public function test_tokens_stays_registered_for_a_host_that_owns_its_pat_table_with_the_estate_absent(): void
    {
        $registry = $this->bootWith(['beam.accounts.user_model' => User::class, 'beam.accounts.publish_auth_migrations' => 'absent']);

        $this->assertTrue($registry->has('tokens'));
    }

    public function test_tokens_is_registered_by_default(): void
    {
        $this->assertTrue($this->bootWith([])->has('tokens'));
    }

    public function test_a_tokenless_user_model_alone_keeps_tokens(): void
    {
        $this->assertTrue($this->bootWith(['beam.accounts.user_model' => TokenlessUser::class])->has('tokens'));
    }
}

class TokenlessUser extends Authenticatable {}
