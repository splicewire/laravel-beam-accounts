<?php

namespace Splicewire\Beam\Accounts\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Facades\BeamDemo;

/**
 * Prints a signed browser login link for a demo subject. A session can't be minted from
 * the CLI into a browser, so — mirroring marquee's preview links — the command hands you
 * a signed URL you paste into a browser (works behind a reverse proxy, in preview too).
 */
class LoginAsCommand extends Command
{
    protected $signature = 'splicewire:beam:accounts:login-as {subject : owner|admin|member|solo} {--minutes=30 : How long the signed link stays valid}';

    protected $description = 'Print a signed browser login link for a demo subject (dev/preview only).';

    public function handle(): int
    {
        if (! BeamDemo::enabled()) {
            $this->error('Demo affordances are disabled in this environment.');

            return self::FAILURE;
        }

        $subject = (string) $this->argument('subject');

        if (! BeamDemo::has($subject)) {
            $this->error("Unknown subject [{$subject}]. Expected one of: ".implode(', ', BeamDemo::keys()));

            return self::FAILURE;
        }

        $minutes = (int) $this->option('minutes');

        // Resolve the subject KEY to the demo user's id: the link now targets the particle operation
        // `users/{id}/op/login-as`, which resolves `{id}` against the user model like every other op.
        // The key stays the CLI's argument — it is the nicer thing to type — and the mapping to a
        // user happens here, once, instead of on every request the old bespoke route served.
        $user = BeamAccounts::userModel()::query()
            ->where('email', BeamDemo::email($subject))
            ->first();

        if ($user === null) {
            $this->error("Demo subject [{$subject}] has no user yet. Seed it first (`db:seed --class=DemoTeamSeeder`).");

            return self::FAILURE;
        }

        $url = URL::temporarySignedRoute(
            'users.op.login-as',
            now()->addMinutes($minutes),
            ['id' => $user->getKey()],
        );

        $this->info("Signed login link for demo {$subject} ({$minutes} min):");
        $this->line($url);

        return self::SUCCESS;
    }
}
