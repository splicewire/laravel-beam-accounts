<?php

namespace Splicewire\Beam\Accounts\Actions;

use Illuminate\Support\Facades\URL;
use Splicewire\Beam\Accounts\Console\LoginAsCommand;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Facades\BeamDemo;
use Splicewire\Beam\Accounts\Ops\LogInAsUser;

/**
 * Mint the signed `users/{id}/op/login-as` link for a demo subject — the ONE place that turns a
 * subject KEY into a URL a browser can follow.
 *
 * ## Why a caller cannot build this URL, and why that was defect 172(a)
 *
 * The demo one-click sign-in built its href client-side, from the generated wayfinder route:
 * `loginAs.url(subject.key)`. The mount is `users/{id}/op/login-as` against a uuid-keyed model, so
 * that produced `users/owner/op/login-as` and a 500 — `invalid input syntax for type uuid: "owner"`.
 *
 * The obvious repair is to pass the id instead of the slug. **It does not work, and finding that out
 * is what settled the ticket's slug-vs-id question.** {@see LogInAsUser} declares
 * `ability: 'loginAs'` + `signed: true`, and it is reached by a GUEST — someone not logged in as
 * anybody, which is the entire point. A guest holding a correct uuid gets:
 *
 *   1. **404** at subject resolution, because the users resource's row scope shows a guest nothing
 *      (see {@see \Splicewire\Beam\Accounts\QueryBuilders\SignedLoginAsSubject} for the one narrow
 *      exception, which is a valid signature); and, were it to get past that,
 *   2. **403** at the ability check, because a guest holds no `loginAs` ability.
 *
 * So passing the id would have converted a 500 into a 404 and looked like progress. The identifier
 * was never the missing thing — the **credential** was. A signature is minted with `APP_KEY`, which
 * only the server holds, so no client-side URL builder can ever produce a working login-as link.
 *
 * **The decision: the operation keeps owning the uuid, and the SERVER hands out the whole URL.** The
 * op stays an ordinary particle operation resolving `{id}` through its `model:` — the design
 * {@see LogInAsUser}'s docblock argues for at length, and the thing that let login-as stop being a
 * bespoke controller. Nothing about a slug is added to the route. What changes is that the demo
 * affordance publishes `url` alongside `key` and `label`, and the button just follows it. That also
 * collapses the ticket's two defects into one path: the one-click demo sign-in IS the signed link,
 * rather than a second, weaker way in that was never going to work.
 *
 * ## ⚠️ What publishing these into a page does, stated plainly
 *
 * A link minted here is a bearer credential for becoming that user, rendered into an anonymous page.
 * Anyone who can load the login screen can become any demo subject without typing anything — which
 * is exactly what "one-click demo sign-in" means. The bound on a leaked link is its expiry, and
 * nothing else; {@see LoginAsCommand} shares that bound.
 *
 * ## Two doors, two gates — and why {@see BeamDemo::enabled()} was not enough
 *
 * `enabled()` is false in production and, unset, TRUE in every other environment. As the only gate
 * it would arm the anonymous-page affordance on every preview deploy, staging box and shared dev
 * host the moment the package installed — nobody having asked for it. So the two doors are gated
 * separately:
 *
 * - {@see self::for()} — mint ONE link, for a caller that has already established it may
 *   ({@see LoginAsCommand}: a shell is a far stronger credential than the link it prints). Gated on
 *   `enabled()`, as before.
 * - {@see self::all()} — publish the WHOLE roster as links into a page's props. Gated on
 *   {@see BeamDemo::publishesLoginLinks()} ⇒ `beam.accounts.demo.login_links`, which ships **false**
 *   and fails closed on anything but a strict true. This is "demo mode", and a host turns it on.
 *
 * The gate sits HERE, at the mint, and not in the component that renders the buttons. A frontend
 * gate is one a visitor walks around by reading the page's props, and props carrying live signed
 * URLs are as good as the buttons. A host that is not in demo mode emits an empty array — there is
 * no link in the served HTML to find.
 */
class DemoLoginLinks
{
    /**
     * How long a minted link stays valid, in minutes. Short enough that a link scraped out of a
     * cached page stops working, long enough to survive an agent or a human reading the page and
     * then clicking.
     */
    public const DEFAULT_MINUTES = 30;

    /**
     * The signed login-as URL for one demo subject, or `null` when the subject has no provisioned
     * user yet.
     *
     * `null` rather than a throw: an unseeded host is the ordinary state of a fresh install, and the
     * login page must render without its demo buttons rather than 500. The CLI, which CAN say
     * something useful about it, distinguishes the two cases itself.
     *
     * Gated on {@see BeamDemo::enabled()} only, NOT on demo mode: this is the single-link door for a
     * caller that has already established its entitlement out of band. {@see LoginAsCommand} is that
     * caller, and a shell on the host outranks anything a demo link grants. Anything publishing to a
     * page goes through {@see self::all()}, which carries the demo-mode gate.
     */
    public function for(string $subject, int $minutes = self::DEFAULT_MINUTES): ?string
    {
        if (! BeamDemo::enabled() || ! BeamDemo::has($subject)) {
            return null;
        }

        $user = BeamAccounts::userModel()::query()
            ->where('email', BeamDemo::email($subject))
            ->first();

        if ($user === null) {
            return null;
        }

        return URL::temporarySignedRoute(
            'users.op.login-as',
            now()->addMinutes($minutes),
            ['id' => $user->getKey()],
        );
    }

    /**
     * Every demo subject that currently has a user, as `{key, label, url}` — the shape a login
     * page's demo quick-sign-in affordance renders directly.
     *
     * Subjects with no provisioned user are OMITTED rather than emitted with a null url: a button
     * that cannot work is worse than no button, and a host filtering them itself would be the
     * fourth place this logic lived.
     *
     * `label` comes from {@see BeamDemo::name()}; a host wanting its own wording maps over the
     * result rather than rebuilding it, so the URL half stays in one place.
     *
     * **Empty unless the host is in demo mode** ({@see BeamDemo::publishesLoginLinks()}). That check
     * is first and unconditional, so a non-demo host does not merely hide the buttons — it never
     * mints the credentials, and the served page carries no login-as URL to scrape.
     *
     * @return array<int, array{key: string, label: string, url: string}>
     */
    public function all(int $minutes = self::DEFAULT_MINUTES): array
    {
        if (! BeamDemo::publishesLoginLinks()) {
            return [];
        }

        $links = [];

        foreach (BeamDemo::keys() as $key) {
            $url = $this->for($key, $minutes);

            if ($url === null) {
                continue;
            }

            $links[] = ['key' => $key, 'label' => BeamDemo::name($key), 'url' => $url];
        }

        return $links;
    }
}
