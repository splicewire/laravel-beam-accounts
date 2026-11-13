<?php

namespace Splicewire\Beam\Accounts\Tests\Analytics;

use Splicewire\Beam\Localization\PseudoLocale;

class LocalizationAudienceWireTest extends AudienceAreaTestCase
{
    private function read(bool $tenant, bool $pseudo): array
    {
        [$owner] = $this->ownerWithTeam();
        $this->analysts[] = $owner->email;
        $this->operators[] = $owner->email;
        if ($tenant) {
            $this->inTenantScope();
        } else {
            $this->frameInRealm('operator');
        }
        if ($pseudo) {
            config(['beam.localization.pseudo' => true]);
            app()->setLocale('en-XA');
        }
        $response = $this->actingAs($owner)->getJson('/frame/resources/analytics-audience')->assertOk();
        $file = getenv('ACCOUNTS_AUDIENCE_SNAPSHOTS');
        if (is_string($file) && $file !== '' && ! $pseudo) {
            file_put_contents($file, json_encode(['test' => $this->name(), 'sequence' => 1, 'method' => 'GET', 'uri' => '/frame/resources/analytics-audience', 'status' => $response->getStatusCode(), 'body' => $response->json()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
        }

        return array_column($response->json('data'), null, 'id');
    }

    public function test_english_workspace_figures_have_unchanged_full_sentences(): void
    {
        $rows = $this->read(true, false);
        $this->assertSame(['Members', 'New members, last 28 days', 'Pending invites'], array_column($rows, 'label'));
    }

    public function test_english_install_figures_are_unchanged(): void
    {
        $rows = $this->read(false, false);
        $this->assertSame(['Users', 'Teams'], array_column($rows, 'label'));
    }

    public function test_pseudo_workspace_figures_mark_the_complete_sentence(): void
    {
        $rows = $this->read(true, true);
        $this->assertSame(['members', 'new-members', 'pending-invites'], array_keys($rows));
        foreach ($rows as $row) {
            $this->assertTrue(PseudoLocale::isMarked($row['label']), $row['label']);
        }
        $this->assertStringContainsString('28', $rows['new-members']['label']);
        $this->assertStringNotContainsString(':days', $rows['new-members']['label']);
    }

    public function test_pseudo_install_figures_are_marked(): void
    {
        $rows = $this->read(false, true);
        $this->assertSame(['users', 'teams'], array_keys($rows));
        foreach ($rows as $row) {
            $this->assertTrue(PseudoLocale::isMarked($row['label']), $row['label']);
        }
    }
}
