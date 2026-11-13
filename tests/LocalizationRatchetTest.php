<?php

namespace Splicewire\Beam\Accounts\Tests;

use Splicewire\Beam\Localization\Ratchet\TextRatchet;
use Splicewire\Beam\Testing\AssertsTextRatchet;

class LocalizationRatchetTest extends TestCase
{
    use AssertsTextRatchet;

    public function test_the_response_reaching_literal_ratchet_stays_zero_without_rewriting(): void
    {
        $root = dirname(__DIR__);
        $before = file_get_contents($root.'/lang-ratchet.json');
        $this->assertSame(0, TextRatchet::measure($root)['total']);
        $this->assertTextRatchet($root);
        $this->assertSame($before, file_get_contents($root.'/lang-ratchet.json'));
    }
}
