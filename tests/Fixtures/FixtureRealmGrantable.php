<?php

namespace Splicewire\Beam\Accounts\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Accounts\Entitlements\Contracts\RealmGrantable;

/** The test-package's binding of {@see RealmGrantable}, over the {@see RealmRoot} fixture. */
class FixtureRealmGrantable implements RealmGrantable
{
    public function grantableMorphType(): string
    {
        return (new RealmRoot)->getMorphClass();
    }

    public function realmForGrantableId(string $id): ?string
    {
        return RealmRoot::query()->whereKey($id)->value('realm');
    }

    /** @return list<string> */
    public function provisionedRealms(): array
    {
        return RealmRoot::query()->pluck('realm')->all();
    }

    public function rootFor(string $realm): Model
    {
        return RealmRoot::query()->firstOrCreate(['realm' => $realm]);
    }
}
