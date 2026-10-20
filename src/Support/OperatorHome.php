<?php

namespace Splicewire\Beam\Accounts\Support;

use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Splicewire\Beam\Accounts\Concerns\WiresOperatorShell;
use Splicewire\Beam\Accounts\Data\Pages\OperatorHomeAreaData;
use Splicewire\Beam\Accounts\Data\Pages\OperatorHomePageData;
use Splicewire\Beam\Accounts\Data\Pages\OperatorHomeStaffData;
use Splicewire\Beam\Accounts\Data\Pages\OperatorHomeStatsData;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Models\Invitation;

/**
 * What the package's `/operator` home shows ({@see WiresOperatorShell}): the figures this package owns, and links to
 * the operator pages the host mounts. Owner re-walk WS-05 (2026-10-19): the page used to be a developer's eject note
 * shown to the site owner. That note is now opt-in for a developer ({@see showsDeveloperNote()}).
 *
 * ## Where the links come from
 *
 * The package resolves no host page of its own, so it reads the host's router: every GET route named in the operator
 * realm (`operator.*`) other than this home, with no required parameter (in its path or its domain), outside the API,
 * whose `can:` gates the viewer passes. A host names or hides an area through `beam.accounts.operator_shell.areas`
 * (route name => label, or `false`); an undeclared one is labelled from its route name in plain words
 * (`operator.customers.index` reads "Customers").
 */
final class OperatorHome
{
    /** The route this home is mounted as, which it never links to itself. */
    public const HOME_ROUTE = 'operator.home';

    private const REALM_PREFIX = 'operator.';

    public static function pageFor(Authenticatable $user): OperatorHomePageData
    {
        return new OperatorHomePageData(
            staff: new OperatorHomeStaffData(name: (string) $user->name, email: (string) $user->email),
            stats: self::stats(),
            areas: self::areasFor($user),
        );
    }

    /**
     * The eject note is a developer's instruction, so it shows only when the host asks for it with
     * `beam.accounts.operator_shell.developer_note`, never as a side effect of the environment. A local site or one with
     * `app.debug` on is where the owner browses their own site, signed in as the operator, and WS-05 was the owner
     * reading this note.
     */
    public static function showsDeveloperNote(): bool
    {
        return (bool) config('beam.accounts.operator_shell.developer_note', false);
    }

    /** @return list<OperatorHomeAreaData> */
    public static function areasFor(Authenticatable $user): array
    {
        $declared = (array) config('beam.accounts.operator_shell.areas', []);
        $gate = Gate::forUser($user);
        $areas = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = $route->getName();

            if (! is_string($name) || $name === self::HOME_ROUTE || ! str_starts_with($name, self::REALM_PREFIX) || ! self::isPage($route)) {
                continue;
            }

            $label = array_key_exists($name, $declared) ? $declared[$name] : self::labelFor($name);

            if (! is_string($label) || trim($label) === '' || ! self::opens($route, $gate)) {
                continue;
            }

            $areas[$name] = new OperatorHomeAreaData(label: trim($label), href: route($name, [], false));
        }

        return array_values($areas);
    }

    private static function stats(): OperatorHomeStatsData
    {
        $userModel = BeamAccounts::userModel();
        $teamModel = BeamAccounts::teamModel();

        return new OperatorHomeStatsData(
            users: $userModel::query()->count(),
            teams: $teamModel !== null && self::installed(new $teamModel) ? $teamModel::query()->count() : null,
            pendingInvitations: self::installed(new Invitation)
                ? Invitation::query()->whereNull('accepted_at')->count()
                : null,
        );
    }

    private static function installed(Model $model): bool
    {
        return Schema::connection($model->getConnectionName())->hasTable($model->getTable());
    }

    /**
     * A page a link can open: a GET with no required parameter that is not an API door. A domain parameter
     * (`{workspace}.example.test`) is a required value like a path one, and a link cannot be built without it: the URL
     * generator throws, and the whole home answered 500 for a host with one such operator route.
     */
    private static function isPage(RouteDefinition $route): bool
    {
        $uri = ltrim($route->uri(), '/');

        return in_array('GET', $route->methods(), true)
            && preg_match('/\{\w+\}/', $uri) !== 1
            && ! str_contains((string) $route->getDomain(), '{')
            && ! str_starts_with($uri, 'api/');
    }

    /** Whether the viewer passes every `can:` gate the route declares, so the home never offers a page that refuses them. */
    private static function opens(RouteDefinition $route, GateContract $gate): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            $spec = match (true) {
                str_starts_with($middleware, 'can:') => substr($middleware, 4),
                str_starts_with($middleware, Authorize::class.':') => substr($middleware, strlen(Authorize::class) + 1),
                default => null,
            };

            if ($spec === null) {
                continue;
            }

            $arguments = explode(',', $spec);
            $ability = array_shift($arguments);

            if ($gate->denies($ability, $arguments)) {
                return false;
            }
        }

        return true;
    }

    /** `operator.customers.index` → "Customers", `operator.billing.plans` → "Billing plans". */
    private static function labelFor(string $name): string
    {
        $segments = array_filter(
            explode('.', Str::after($name, self::REALM_PREFIX)),
            fn (string $segment): bool => ! in_array($segment, ['index', 'show', 'home'], true),
        );

        return Str::ucfirst((string) Str::of(implode(' ', $segments))->replace(['-', '_'], ' ')->squish()->lower());
    }
}
