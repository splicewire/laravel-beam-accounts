<?php

use Splicewire\Beam\Accounts\Write\PermissiveAcceptanceGate;
use Splicewire\Beam\Accounts\Write\PermissiveWriteGate;

it('the permissive write gate always authorizes', function () {
    $gate = new PermissiveWriteGate;

    expect($gate->authorizes('any-schema-stem', ['field' => 'value']))->toBeTrue()
        ->and($gate->authorizes('any-schema-stem', [], null))->toBeTrue();
});

it('the permissive acceptance gate always accepts, even a non-conforming candidate', function () {
    $gate = new PermissiveAcceptanceGate;

    expect($gate->accepts(['field' => 'value'], ['type' => 'object', 'required' => ['missing']]))->toBeTrue()
        ->and($gate->accepts([], []))->toBeTrue();
});
