<?php

namespace Splicewire\Beam\Accounts\Actions;

use Illuminate\Support\Facades\Route;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Accounts\Data\Pages\AgreementPromptData;
use Splicewire\Beam\Agreements\Data\OutstandingRequirementData;
use Splicewire\Beam\Agreements\RequirementAdmission;
use Splicewire\Beam\Agreements\RequirementDeclarations;

/** Build the optional account-form props from the Requirements and Agreement read routes already mounted by the host. */
final class AgreementPageProps
{
    /**
     * @return array{agreements: list<AgreementPromptData>} Empty when this host has no Agreement UI to render.
     */
    public static function forBehavior(string $behavior, mixed $party = null, mixed $subject = null): array
    {
        if (! app()->bound(RequirementDeclarations::class)
            || ! app()->bound(RequirementAdmission::class)
            || ! Route::has('beam.agreements.agreements.show')) {
            return [];
        }

        $requirements = app(RequirementDeclarations::class)->requirementsFor($behavior);

        if ($requirements === []) {
            return [];
        }

        $refusal = app(RequirementAdmission::class)->inspect($party, $behavior, $subject, []);
        $outstandingByKey = [];

        foreach ($refusal?->requirements ?? [] as $outstanding) {
            if ($outstanding instanceof OutstandingRequirementData) {
                $outstandingByKey[$outstanding->key] = $outstanding;
            }
        }

        $keys = [];

        foreach ($requirements as $requirement) {
            $keys[$requirement->agreement] = true;
        }

        $prompts = [];

        foreach (array_keys($keys) as $key) {
            $current = $outstandingByKey[$key] ?? null;
            $readUrl = route('beam.agreements.agreements.show', ['id' => $key], false);
            $prompts[] = new AgreementPromptData(
                key: $key,
                readUrl: $readUrl,
                required: $current !== null,
                version: $current !== null ? $current->version : new Optional,
                digest: $current !== null ? $current->digest : new Optional,
            );
        }

        return $prompts === [] ? [] : ['agreements' => $prompts];
    }
}
