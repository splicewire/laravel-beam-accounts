<?php

namespace Splicewire\Beam\Accounts\Ops;

use Illuminate\Http\Request;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Accounts\Actions\DemoLoginLinks;
use Splicewire\Beam\Accounts\Concerns\WiresDemo;
use Splicewire\Beam\Accounts\Data\SearchDemoUsersInputData;
use Splicewire\Beam\Accounts\Data\SearchDemoUsersOutputData;
use Splicewire\Beam\Accounts\Facades\BeamDemo;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\Subject\NoSubject;
use Splicewire\Beam\Routing\HttpMethod;

/**
 * `GET users/demo-search?q=` — the local-only "log in as any user" typeahead's read (S1, owner-review-20261026
 * ticket 01). Scaffolded by `splicewire:beam:make:particle-op` and then filled in.
 *
 * ## Why `ability: false`, and why that is not an open door
 *
 * The caller is a GUEST on a login page — that is the whole point — so no ability can be held, and declaring one
 * would 403 the only caller this op has. It is declared ungated DELIBERATELY (`false`, not the undeclared `null`).
 * What gates it instead is the environment, three times over, and all three are server-side:
 *
 *   1. the route exists only when {@see BeamDemo::offersUserPicker()} held at boot ({@see WiresDemo::bootDemo()});
 *   2. this handler re-checks the gate and answers 404 when it no longer holds; and
 *   3. the mint ({@see DemoLoginLinks::users()}) checks it again, so no caller of the action can skip it.
 *
 * `offersUserPicker()` requires `APP_ENV=local` with no config override, so no flag combination reaches this on a
 * preview, staging or production host.
 *
 * `subject: NoSubject` — there is no record coordinate, so the mount is the flat `users/demo-search`. The package
 * mounts it at provider boot, which is before any host route file, so a host's `users/{id}` cannot swallow it
 * (particle doctrine, "a NoSubject op lands in a DIFFERENT slot").
 */
#[ParticleOp(
    resource: 'users',
    name: 'demo-search',
    summary: 'Search users to sign in as (local demo only)',
    description: 'Lists users whose name or email matches the query, each with a signed, expiring login-as link. Mounted only on a local host with the demo user picker enabled.',
    kind: OperationKind::Read,
    ability: false,
    affordance: false,
    input: SearchDemoUsersInputData::class,
    output: SearchDemoUsersOutputData::class,
    subject: NoSubject::class,
    method: HttpMethod::Get,
)]
class SearchDemoUsers
{
    public static function handle(mixed $model, Request $request, mixed $actor): SearchDemoUsersOutputData
    {
        abort_unless(BeamDemo::offersUserPicker(), 404);

        $input = SearchDemoUsersInputData::from($request->query());
        $query = $input->q instanceof Optional ? null : $input->q;

        return new SearchDemoUsersOutputData(users: app(DemoLoginLinks::class)->users($query));
    }
}
