<?php

use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Teams\InvitationMailer;
use Splicewire\Beam\Accounts\Teams\InvitationRedemption;
use Splicewire\Beam\Localization\LocaleScope;

it('review invitation does not render under the inviters operator realm', function () {
    config(['beam.localization.supported' => ['en', 'es'], 'app.locale' => 'en']);
    app()->setLocale('en');
    $lang = sys_get_temp_dir().'/review-b12-mail-'.getmypid().'-'.bin2hex(random_bytes(3));
    mkdir($lang.'/vendor/beam-accounts/en/operator', 0777, true);
    file_put_contents($lang.'/vendor/beam-accounts/en/messages.php', "<?php return ['invitation_subject'=>'Base :team'];");
    file_put_contents($lang.'/vendor/beam-accounts/en/operator/messages.php', "<?php return ['invitation_subject'=>'WRONG OPERATOR :team'];");
    app()->useLangPath($lang);
    app()->forgetInstance('translation.loader');
    app()->forgetInstance('translator');
    $seen = null;
    Event::listen(NotificationSending::class, function ($event) use (&$seen) {
        $seen = $event->notification->toMail($event->notifiable)->subject;

        return false;
    });
    $i = new Invitation(['email' => 'unknown@example.test', 'role' => 'member', 'team_id' => 'acme']);
    $i->setAttribute('token', 'tok');
    $redemption = Mockery::mock(InvitationRedemption::class);
    $redemption->shouldReceive('teamName')->andReturn('Acme');
    $redemption->shouldReceive('acceptUrl')->andReturn('https://acme.test/invitations/tok');
    $redemption->shouldReceive('inviterName')->andReturn('Olive');
    $redemption->shouldReceive('expiresAt')->andReturn(Illuminate\Support\Carbon::parse('2026-11-14'));
    $redemption->shouldReceive('team')->andReturn(new Team(['name' => 'Acme']));
    LocaleScope::runIn('es', 'operator', fn () => (new InvitationMailer($redemption))->send($i));
    expect($seen)->toBe('Base Acme');
});
