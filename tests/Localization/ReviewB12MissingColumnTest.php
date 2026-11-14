<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Accounts\Localization\PreferredLocale;
use Splicewire\Beam\Accounts\Tests\Fixtures\LocalizedUser;

it('review reads automatic safely without the column on a strict host', function () {
    config(['beam.localization.supported' => ['en', 'es']]);
    Schema::table('users', fn ($t) => $t->dropColumn('preferred_locale'));
    PreferredLocale::flush();
    $id = LocalizedUser::query()->create(['name' => 'Ana', 'email' => 'strict@example.test', 'password' => 'x'])->getKey();
    $retrieved = LocalizedUser::query()->findOrFail($id);
    $prior = Model::preventsAccessingMissingAttributes();
    Model::preventAccessingMissingAttributes();
    try {
        expect(PreferredLocale::of($retrieved))->toBeNull();
    } finally {
        Model::preventAccessingMissingAttributes($prior);
    }
});
