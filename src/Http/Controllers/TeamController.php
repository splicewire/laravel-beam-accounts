<?php

namespace Splicewire\Beam\Accounts\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;
use Splicewire\Beam\Accounts\Data\Pages\CreateTeamPageData;
use Splicewire\Beam\Accounts\Ops\CreateTeam;

/**
 * The `teams.create` page — the form that posts to the {@see CreateTeam} operation.
 *
 * An `account/*` page so the packaged layout frames it in the account shell beside Team and API tokens.
 * The write is the operation's; this controller only renders the form and tells it where to post.
 */
class TeamController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('account/create-team', new CreateTeamPageData(
            action: route('teams.store', [], false),
            cancelUrl: Route::has('dashboard') ? route('dashboard', [], false) : null,
        ));
    }
}
