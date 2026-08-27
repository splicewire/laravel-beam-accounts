<?php

namespace Splicewire\Beam\Accounts\Facades;

use Illuminate\Support\Facades\Facade;
use Splicewire\Beam\Accounts\BeamDemoManager;

/**
 * The BeamDemo facade — the short static front door to the demo-subject roster.
 *
 * It holds NO logic: every method it appears to have resolves through `__callStatic` to the
 * container-bound {@see BeamDemoManager}. The surface is CLOSED at the eleven methods below.
 *
 * A separate facade from {@see BeamAccounts} on purpose. The demo affordances are off in
 * production, and a front door that is sometimes closed does not belong on the package's production
 * front door; keeping them apart also means `BeamDemo::` reads as demo vocabulary at every call site
 * without a prefix on every method name.
 *
 * `OPERATOR_KEY` stays a `const` on the instance and is reachable here as `operatorKey()` — a
 * constant cannot ride `__callStatic`.
 *
 * Deliberately NOT registered as a global alias, for the same reason as {@see BeamAccounts}.
 *
 * The `@method` block below is hand-written and guarded by a reflective parity test
 * (`tests/Facade/FacadeMethodParityTest.php`).
 *
 * @method static string operatorKey()
 * @method static array<string, array{role: \Splicewire\Beam\Accounts\Enums\Role, shared: bool}> subjects()
 * @method static bool isOperator(string $key)
 * @method static bool enabled()
 * @method static bool publishesLoginLinks()
 * @method static array<int, string> keys()
 * @method static bool has(string $key)
 * @method static \Splicewire\Beam\Accounts\Enums\Role roleFor(string $key)
 * @method static bool isShared(string $key)
 * @method static string email(string $key)
 * @method static string name(string $key)
 *
 * @see BeamDemoManager
 */
class BeamDemo extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BeamDemoManager::class;
    }
}
