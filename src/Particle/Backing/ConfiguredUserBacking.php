<?php

namespace Splicewire\Beam\Accounts\Particle\Backing;

use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Particle\Backing\EloquentBacking;

/**
 * The `users` resource's backing: an ordinary Eloquent backing whose MODEL CLASS is the host's, read
 * from configuration at resolve time.
 *
 * ## Why this exists, when the docs say not to hand-roll one
 *
 * {@see \Splicewire\Beam\Particle\Backing\ResourceBacking}'s docblock warns that *"a resource does not
 * hand-roll a backing to say 'I am a model'"* — and this one is not saying that. It is saying **my model
 * is host-configured**, which is a different claim and is the one thing a `backing:` class-string cannot
 * express on its own.
 *
 * `#[ParticleResource]` is an attribute, so every argument must be a **constant expression**:
 * `backing: BeamAccounts::userModel()` will not compile. Before this class,
 * {@see \Splicewire\Beam\Accounts\Data\UserData} therefore froze `Models\User::class` into the attribute
 * while the imperative `me` resource ({@see \Splicewire\Beam\Accounts\Concerns\WiresMeResource}) read the
 * config — and the two disagreed at every host.
 *
 * ## Three freeze points, and only one of them is late enough
 *
 * Worth stating, because the difference is invisible at a call site and cost a wrong test on the way in:
 *
 * | declaration form | reads the model at | follows a host's config? |
 * |---|---|---|
 * | attribute (`backing: Foo::class`) | **compile** time | never |
 * | imperative `register(new ParticleResource(backing: userModel()))` | **boot** time | only if config is set before the provider boots |
 * | a `ResourceBacking` class-string (this) | **request** time | yes — `BackingResolver` does `app($backing)` |
 *
 * ## What was actually wrong
 *
 * Measured 2026-08-28, and this is the single home for that census — the config comment, `UserData` and
 * the test point here rather than restating it, because it is a dated reading of a moving estate and
 * would otherwise go stale in four places at once.
 *
 * {@see \Splicewire\Beam\Accounts\BeamAccountsManager::userModel()} falls back
 * `beam.accounts.user_model` → `auth.providers.users.model` → `Models\User`. Every host leaves the first
 * null, so the second decides, and it resolves to `App\Models\User`. All **six** hosts installing this
 * package were affected — the sixth by a different mechanism, and it was first written up here as the
 * exception:
 *
 * - `~/Herd/{audiostud, fable, numero, schemastud}` — `App\Models\User extends Authenticatable`, an
 *   entirely **unrelated class**;
 * - `~/Herd/splicewire-app` — `extends BeamUser`, a **subclass**, so the frozen parent queried the right
 *   table with the wrong casts, scopes, relations and policy binding;
 * - `~/Herd/splicewire` — the one host that took the documented escape hatch (`app/Data/UserData.php`
 *   re-declares `key: 'users'`), and it is affected too, because that hatch does not hold. Booting the
 *   host on 2026-08-28 resolves `users` to beam's own `Data\UserData`, with `App\Data\UserData` sitting
 *   in `superseded('users')` — displaced, not winning. The list route registers FIRST and loses; only a
 *   host provider's own `boot()` lands after beam's. {@see \Splicewire\Beam\Accounts\Data\UserData}
 *   carries the measurement and the two-route asymmetry.
 *
 * The failure is IDENTITY, not disclosure: the table is the same, but the concrete class is what decides
 * casts, global scopes, eager-loadable relations and policy binding.
 *
 * ## Capabilities are inherited deliberately
 *
 * Extending {@see EloquentBacking} rather than reimplementing it keeps `BacksModel`, `QueriesRecords`,
 * `StreamsRecords` and `WritesRecords` — the exact set `backing: User::class` resolved to before — so
 * `BackingResolver::hasCapability()`, which decides statically via `is_a(…, true)`, returns what it
 * always did and no affordance assertion on the declaration changes.
 *
 * @see \Splicewire\Beam\Accounts\BeamAccountsManager::userModel()  the three-step fallback this reads
 */
class ConfiguredUserBacking extends EloquentBacking
{
    public function __construct()
    {
        parent::__construct(BeamAccounts::userModel());
    }
}
