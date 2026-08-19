<?php

namespace Splicewire\Beam\Accounts\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Accounts\Actions\LogInAs;
use Splicewire\Beam\Accounts\Facades\BeamDemo;

class LoginAsController
{
    public function __invoke(Request $request, LogInAs $login, string $subject)
    {
        abort_unless(BeamDemo::enabled(), 403);
        abort_unless(BeamDemo::has($subject), 404);

        // Outside local/testing the link must be signed — the `splicewire:beam:accounts:login-as` command
        // mints one — so the affordance can ride along in a preview deploy without
        // becoming an open back door.
        if (! app()->environment('local', 'testing') && ! $request->hasValidSignature()) {
            abort(403, 'This demo login link is invalid or has expired.');
        }

        $login($subject);
        $request->session()->regenerate();

        return redirect()->to($this->redirectFor($subject));
    }

    /**
     * The designated operator demo subject ({@see BeamDemo::isOperator()}) lands on the operator shell
     * when one is routed (its own `bootOperatorShell()` default, or a host's own `operator.home`) —
     * the whole point of a DIFFERENT demo subject per role is landing somewhere that actually shows
     * what that role can reach, not the same generic default every subject shares. Every other
     * subject keeps the plain `beam.accounts.demo.redirect` default.
     */
    private function redirectFor(string $subject): string
    {
        if (BeamDemo::isOperator($subject) && Route::has('operator.home')) {
            return route('operator.home');
        }

        return config('beam.accounts.demo.redirect', '/');
    }
}
