<?php

use Splicewire\Beam\Accounts\BeamAccountsManager;
use Splicewire\Beam\Accounts\BeamDemoManager;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Facades\BeamDemo;

/**
 * The `@method` blocks on both facades are hand-written, so nothing but a test keeps them honest.
 * Mirrors beam core's own `tests/Facade/FacadeMethodParityTest.php`.
 */
$parity = function (string $facade, string $subject) {
    $tagged = [];
    preg_match_all(
        '/@method\s+static\s+.+?\s+(\w+)\(/',
        (new ReflectionClass($facade))->getDocComment() ?: '',
        $matches,
    );
    $tagged = $matches[1];

    $actual = array_values(array_map(
        fn (ReflectionMethod $m) => $m->getName(),
        array_filter(
            (new ReflectionClass($subject))->getMethods(ReflectionMethod::IS_PUBLIC),
            fn (ReflectionMethod $m) => ! $m->isStatic() && ! $m->isConstructor(),
        ),
    ));

    sort($tagged);
    sort($actual);

    expect($tagged)->toBe($actual);
};

it('documents exactly the BeamAccounts instance surface', fn () => $parity(BeamAccounts::class, BeamAccountsManager::class));

it('documents exactly the BeamDemo instance surface', fn () => $parity(BeamDemo::class, BeamDemoManager::class));

it('resolves each facade to its bound singleton', function () {
    expect(BeamAccounts::getFacadeRoot())->toBeInstanceOf(BeamAccountsManager::class)
        ->and(BeamDemo::getFacadeRoot())->toBeInstanceOf(BeamDemoManager::class)
        ->and(BeamAccounts::getFacadeRoot())->toBe(app(BeamAccountsManager::class));
});

it('is swappable, which the namespaced functions it replaced were not', function () {
    BeamAccounts::swap(new class extends BeamAccountsManager
    {
        public function guard(): string
        {
            return 'swapped';
        }
    });

    expect(BeamAccounts::guard())->toBe('swapped');

    BeamAccounts::clearResolvedInstances();
});
