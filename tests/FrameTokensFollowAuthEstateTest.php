<?php

namespace Splicewire\Beam\Accounts\Tests;

use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * A host that declares the personal-access-token estate absent (`publish_auth_migrations => 'absent'`) has no
 * `personal_access_tokens` table. The Frame manifest must not advertise a `tokens` resource there: it answered 500 on
 * its list at `rushing/stephenrushing` (2026-11-09). The rest of the package's Frame resources still register.
 */
class FrameTokensFollowAuthEstateTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('beam.accounts.publish_auth_migrations', 'absent');
    }

    public function test_tokens_is_not_registered_when_the_auth_estate_is_absent(): void
    {
        $registry = app(ParticleResourceRegistry::class);

        $this->assertFalse($registry->has('tokens'));
        $this->assertTrue($registry->has('invitations'));
        $this->assertTrue($registry->has('members'));
    }
}
