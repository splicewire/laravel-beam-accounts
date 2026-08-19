<?php

namespace Splicewire\Beam\Accounts\Http\Controllers;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Splicewire\Beam\Accounts\Data\ProfileDeleteInputData;
use Splicewire\Beam\Accounts\Data\ProfileUpdateInputData;

/**
 * The Inertia settings-profile surface.
 *
 * Both writes take a declared input DTO rather than a FormRequest (particle doctrine — the
 * invariant covers every boundary-crossing shape, and an Inertia route is not one of the four
 * exceptions). Injecting the DTO as a typed parameter is safe HERE specifically because these
 * routes are gated by `auth` MIDDLEWARE, which runs before the controller: the api-surface-coherence
 * ticket-27 trap (a DTO injected on a gate-in-the-controller endpoint turns a 403 into a 422) needs
 * the authorization to run *after* resolution, which is not the case on this surface.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(Request $request, ProfileUpdateInputData $input): RedirectResponse
    {
        $user = $request->user();
        $user->fill(['name' => $input->name, 'email' => $input->email]);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return to_route('profile.edit')->with('status', 'profile-updated');
    }

    public function destroy(Request $request, ProfileDeleteInputData $input): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
