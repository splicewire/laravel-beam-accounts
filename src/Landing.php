<?php

namespace Splicewire\Beam\Accounts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Splicewire\Beam\Accounts\Contracts\LandingSeats;
use Splicewire\Beam\Ia\HostIa;

/**
 * Where a signed-in user lands: ONE resolver for every sign-in door (ux-walkthrough UX-11, IA-5, M3). Precedence:
 *
 * 1. a SAFE intended URL ({@see safeIntended()});
 * 2. a tenant seat → {@see LandingSeats::homeFor()} (the tenant home);
 * 3. `os.operate` with no seat → the operator home;
 * 4. otherwise the tenant home. (The first-run welcome on this branch is purchase-walkthrough BUY-12's.)
 *
 * OQ-1 (owner, 2026-10-05): an owner who is also the operator holds a seat, so lands in the App; Operator is one
 * switcher click away. A home that is not mounted at this host falls back to '/', never a throw at sign-in.
 */
class Landing
{
    /** Paths an intended URL may never send a user back to: the doors and the session itself. */
    private const REFUSED_PATHS = '#^/(login|logout|register|two-factor-challenge|forgot-password|reset-password|email/verify|user/confirm-password|passkeys)(/|$)#i';

    public static function for(Authenticatable $user, ?string $intended = null): string
    {
        return self::safeIntended($intended)
            ?? app(LandingSeats::class)->homeFor($user)
            ?? (Gate::forUser($user)->allows('entitlement:os.operate') ? self::home('operator') : self::home('tenant'));
    }

    /** A realm's home at this host (HostIa, M2), or '/' when it is not mounted. */
    public static function home(string $realm): string
    {
        try {
            return app(HostIa::class)->home($realm);
        } catch (InvalidArgumentException) {
            return '/';
        }
    }

    /**
     * The intended URL, NORMALISED, when it is safe to redirect to; otherwise null.
     *
     * Judged by RESOLUTION, never by the string's shape (review-r1 and the lead, after the A2 open redirect): decode
     * until stable and turn backslashes into slashes, refuse any control character (a browser strips a tab or newline
     * and `/\t/evil.com` becomes `//evil.com`), then parse. Only a path with no scheme and no host that starts with
     * exactly one '/', or an absolute URL on this request's exact scheme, host and port, survives. The doors, the
     * session, the API, Frame transport and JSON are refused on the decoded path. The result is rebuilt from the
     * parsed parts, so what was judged is exactly what is sent.
     */
    public static function safeIntended(?string $intended): ?string
    {
        if ($intended === null || $intended === '') {
            return null;
        }

        // Judge the fully decoded form; refuse an input still changing after five rounds (only a hostile one does).
        $decoded = $intended;
        $stable = false;
        for ($i = 0; $i < 5; $i++) {
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                $stable = true;
                break;
            }
            $decoded = $next;
        }
        if (! $stable) {
            return null;
        }
        $decoded = str_replace('\\', '/', $decoded);

        if (preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1) {
            return null;
        }

        $judged = parse_url(self::asciiOnly($decoded));
        if ($judged === false) {
            return null;
        }

        if (isset($judged['scheme']) || isset($judged['host'])) {
            if (! self::isThisOrigin($judged)) {
                return null;
            }
        } elseif (! str_starts_with($decoded, '/') || str_starts_with($decoded, '//')) {
            return null;
        }

        $path = $judged['path'] ?? '/';
        if ($path === '' || ! str_starts_with($path, '/') || str_starts_with($path, '//')
            || preg_match('#(^|/)\.\.?(/|$)#', $path) === 1
            || preg_match(self::REFUSED_PATHS, $path) === 1
            || preg_match('#^/api(/|$)#i', $path) === 1
            || preg_match('#/frame/manifest(/|$)#i', $path) === 1
            || str_ends_with(strtolower($path), '.json')) {
            return null;
        }

        // Safe as decoded, so return what the client SENT (review-r1 on 2c106e1): the parts of the original, with only the
        // backslashes normalised, so an encoded `&`, `#`, `?` or space keeps its meaning (`?q=a%26b` stays one value).
        $sent = parse_url(self::asciiOnly(str_replace('\\', '/', $intended)));
        if ($sent === false) {
            return null;
        }

        return ($sent['path'] ?? '/')
            .(isset($sent['query']) ? '?'.$sent['query'] : '')
            .(isset($sent['fragment']) ? '#'.$sent['fragment'] : '');
    }

    /**
     * Percent-encode every non-ASCII byte (build.qa on fc2d3ef): `parse_url()` replaces raw bytes it does not expect,
     * so an unencoded `/café` would come back as broken UTF-8. Encoded, it means the same and parses intact.
     */
    private static function asciiOnly(string $url): string
    {
        return (string) preg_replace_callback('/[\x80-\xFF]/', fn (array $byte) => rawurlencode($byte[0]), $url);
    }

    /** @param  array<string, mixed>  $parts */
    private static function isThisOrigin(array $parts): bool
    {
        $request = request();
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        return $scheme === strtolower($request->getScheme())
            && $host !== ''
            && $host === strtolower($request->getHost())
            && $port === (int) $request->getPort()
            && ! isset($parts['user']) && ! isset($parts['pass']);
    }
}
