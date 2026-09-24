<?php

namespace Splicewire\Beam\Accounts\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Splicewire\Beam\Accounts\Data\Pages\AcceptInvitationPageData;
use Splicewire\Beam\Accounts\Ops\RedeemInvitation;
use Splicewire\Beam\Accounts\Teams\InvitationRedemption;

/**
 * `GET invitations/{token}` (`invitations.accept`) — the page the invitation email links to.
 *
 * Deliberately NOT behind `auth`: the invitee usually has no account yet. The page reads the link and
 * the viewer and renders {@see InvitationRedemption::verdict()}'s answer; the seat itself is taken by
 * the {@see RedeemInvitation} operation the page posts to, which IS behind `auth`.
 *
 * The signature is checked here rather than with the `signed` middleware so a bad or expired link
 * renders the page's own explanation instead of a bare 403. For a GUEST the full signed URL is stored
 * as the session's intended URL, so registering (Fortify's register response redirects `intended()`)
 * or logging in brings them straight back to this page, signed-in, to accept.
 */
class InvitationAcceptController extends Controller
{
    public function show(Request $request, InvitationRedemption $redemption, string $token): Response
    {
        $invitation = $redemption->find($token);
        $viewer = $request->user();

        $state = $redemption->verdict(
            $invitation,
            $viewer,
            signed: URL::hasCorrectSignature($request),
            signatureExpired: ! URL::signatureHasNotExpired($request),
        );

        if ($state === InvitationRedemption::GUEST) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        $live = $invitation !== null && $state !== InvitationRedemption::INVALID;

        return Inertia::render('auth/accept-invitation', new AcceptInvitationPageData(
            state: $state,
            teamName: $live ? $redemption->teamName($invitation) : null,
            email: $live ? $invitation->email : null,
            role: $live ? (string) $invitation->role : null,
            inviterName: $live ? $redemption->inviterName($invitation) : null,
            expiresAt: $live ? $redemption->expiresAt($invitation)->toIso8601String() : null,
            viewerEmail: $viewer === null ? null : (string) data_get($viewer, 'email'),
            acceptUrl: $state === InvitationRedemption::READY ? $redemption->redeemUrl($invitation) : null,
            registerUrl: self::routeOrNull('register', $live ? ['email' => $invitation->email] : []),
            loginUrl: self::routeOrNull('login'),
            logoutUrl: self::routeOrNull('logout'),
        ));
    }

    /** @param  array<string, string>  $query */
    private static function routeOrNull(string $name, array $query = []): ?string
    {
        return Route::has($name) ? route($name, $query, false) : null;
    }
}
