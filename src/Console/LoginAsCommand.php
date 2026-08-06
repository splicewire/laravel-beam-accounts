<?php

namespace Splicewire\Beam\Accounts\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;
use Splicewire\Beam\Accounts\Support\Demo;

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
        if (! Demo::enabled()) {
            $this->error('Demo affordances are disabled in this environment.');

            return self::FAILURE;
        }

        $subject = (string) $this->argument('subject');

        if (! Demo::has($subject)) {
            $this->error("Unknown subject [{$subject}]. Expected one of: ".implode(', ', Demo::keys()));

            return self::FAILURE;
        }

        $minutes = (int) $this->option('minutes');

        $url = URL::temporarySignedRoute(
            'splicewire.account.login-as',
            now()->addMinutes($minutes),
            ['subject' => $subject],
        );

        $this->info("Signed login link for demo {$subject} ({$minutes} min):");
        $this->line($url);

        return self::SUCCESS;
    }
}
