<?php

namespace Splicewire\Beam\Accounts\Tests\Localization;

use Splicewire\Beam\Accounts\Tests\TestCase;
use Splicewire\Beam\Localization\MissingTranslationKey;

/**
 * localization 12, in a second family package: a mistyped key in this package's own namespace (`beam-accounts::`)
 * fails its test. Nothing here installs the gate; beam's provider does, so this package inherits it like every other
 * family package and host. A host's own key and a sentence key never throw.
 */
class MissingKeyGateTest extends TestCase
{
    public function test_a_mistyped_key_in_this_packages_namespace_fails_the_test(): void
    {
        $this->expectException(MissingTranslationKey::class);
        $this->expectExceptionMessage('beam-accounts::auth.pasword_reset');

        __('beam-accounts::auth.pasword_reset');
    }

    public function test_a_host_key_and_a_sentence_key_never_throw(): void
    {
        $this->assertSame('host.missing', __('host.missing'));
        $this->assertSame('Reset your password', __('Reset your password'));
    }
}
