<?php

namespace Splicewire\Beam\Accounts\Tests\Analytics;

use Splicewire\Beam\Accounts\Tests\TestCase;

/**
 * beam-accounts booted ALONE, the package's default composition with laravel-beam-analytics absent from it (charter ruling
 * (A), conditions 2 and 3): the Audience area's contribution is inert. Booting loads no class of the analytics package and
 * not the area class, and nothing resolves the analytics registry.
 *
 * The boot runs in a fresh PHP process (Scripts/boot-accounts-alone.php), because `class_exists(…, false)` reports what
 * the CURRENT process has loaded, and this suite's process loads both on purpose in the Audience area's own tests.
 * PHPUnit's process isolation is not used: Pest's runner does not support it here.
 */
class AudienceAreaAbsentTest extends TestCase
{
    public function test_booting_beam_accounts_alone_loads_no_analytics_class_and_not_the_area(): void
    {
        $root = dirname(__DIR__, 2);
        $out = shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/Scripts/boot-accounts-alone.php').' '.escapeshellarg($root).' 2>&1');
        $report = json_decode((string) $out, true);

        $this->assertIsArray($report, 'the fresh boot reported: '.$out);
        $this->assertTrue($report['booted'], 'control: the package booted');
        $this->assertTrue($report['analyticsInstalled'], 'control: analytics is installed (require-dev), so an absence is real, not a missing package');
        $this->assertSame([], $report['loaded'], 'booting beam-accounts alone loads none of these');
        $this->assertFalse($report['registryResolved'], 'nothing resolved the analytics registry');
    }
}
