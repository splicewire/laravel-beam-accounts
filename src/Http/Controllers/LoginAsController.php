<?php

namespace Splicewire\Beam\Accounts\Http\Controllers;

use Illuminate\Http\Request;
use Splicewire\Beam\Accounts\Actions\LogInAs;
use Splicewire\Beam\Accounts\Support\Demo;

class LoginAsController
{
    public function __invoke(Request $request, LogInAs $login, string $subject)
    {
        abort_unless(Demo::enabled(), 403);
        abort_unless(Demo::has($subject), 404);

        // Outside local/testing the link must be signed — the `splicewire:beam:account-login-as` command
        // mints one — so the affordance can ride along in a preview deploy without
        // becoming an open back door.
        if (! app()->environment('local', 'testing') && ! $request->hasValidSignature()) {
            abort(403, 'This demo login link is invalid or has expired.');
        }

        $login($subject);
        $request->session()->regenerate();

        return redirect()->to(config('beam-accounts.demo.redirect', '/'));
    }
}
