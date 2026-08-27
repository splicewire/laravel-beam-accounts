<?php

namespace Splicewire\Beam\Accounts\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Accounts\Actions\DemoLoginLinks;
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

    public function __construct(protected DemoLoginLinks $links)
    {
        parent::__construct();
    }

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

        // The minting itself is {@see DemoLoginLinks}, not a body here — beam-facade 172 gave it a
        // second caller (the login page's one-click demo buttons, which cannot build a signed URL
        // client-side because the signature needs APP_KEY), and two copies of "subject key ⇒ signed
        // route" is exactly how the two affordances would drift apart again.
        //
        // The subject KEY stays the CLI's argument — it is the nicer thing to type — and the mapping
        // to a user happens once, there, rather than on every request the old bespoke route served.
        $url = $this->links->for($subject, $minutes);

        if ($url === null) {
            $this->error("Demo subject [{$subject}] has no user yet. Seed it first (`db:seed --class=DemoTeamSeeder`).");

            return self::FAILURE;
        }

        $this->info("Signed login link for demo {$subject} ({$minutes} min):");
        $this->line($url);

        return self::SUCCESS;
    }
}
