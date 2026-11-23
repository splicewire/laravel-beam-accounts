<?php

namespace Splicewire\Beam\Accounts\Actions;

use Illuminate\Support\Facades\Route;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Accounts\Data\Pages\AgreementPromptData;
use Splicewire\Beam\Agreements\Http\LegalPagesController;
use Splicewire\Beam\Agreements\Data\OutstandingRequirementData;
use Splicewire\Beam\Agreements\Registry\AgreementRegistry;
use Splicewire\Beam\Agreements\Requirements\AgreementLedger;
use Splicewire\Beam\Agreements\RequirementAdmission;
use Splicewire\Beam\Agreements\RequirementDeclarations;

/** Build optional account-form props from the host's declared Requirements and the current Agreements. */
final class AgreementPageProps
{
    /**
     * @return array{agreements: list<AgreementPromptData>} Empty when this host has no Agreement UI to render.
     */
    public static function forBehavior(string $behavior, mixed $party = null, mixed $subject = null): array
    {
        if (! app()->bound(RequirementDeclarations::class)
            || ! app()->bound(RequirementAdmission::class)) {
            return [];
        }

        $requirements = app(RequirementDeclarations::class)->requirementsFor($behavior);

        if ($requirements === []) {
            return [];
        }

        $keys = [];

        foreach ($requirements as $requirement) {
            $keys[$requirement->agreement] = true;
        }

        // Guest forms cannot call the authenticated Agreement resource. Embed the exact version
        // they are being asked to accept so its digest is the one admission checks later.
        if ($party === null) {
            if (! app()->bound(AgreementRegistry::class)) {
                return [];
            }

            $registry = app(AgreementRegistry::class);
            $prompts = [];

            foreach (array_keys($keys) as $key) {
                $definition = $registry->get($key);
                $version = $definition === null ? null : AgreementLedger::ledger()->inForce($key);

                if ($definition === null || $version === null) {
                    continue;
                }

                $prompts[] = new AgreementPromptData(
                    key: $key,
                    readUrl: new Optional,
                    required: $definition->kindDefinition()->refusesUntilAssented,
                    version: AgreementLedger::ordinal($version),
                    digest: $version->digest,
                    kind: $definition->kind,
                    title: $definition->title,
                    label: $version->label,
                    effectiveAt: $version->effective_at->toIso8601String(),
                    summary: $version->summary,
                    content: $version->content,
                    legalUrl: LegalPagesController::url($key),
                );
            }

            return $prompts === [] ? [] : ['agreements' => $prompts];
        }

        // Signed-in callers keep the existing read route and Requirement admission behavior.
        if (! Route::has('beam.agreements.agreements.show')) {
            return [];
        }

        $refusal = app(RequirementAdmission::class)->inspect($party, $behavior, $subject, []);
        $outstandingByKey = [];

        foreach ($refusal?->requirements ?? [] as $outstanding) {
            if ($outstanding instanceof OutstandingRequirementData) {
                $outstandingByKey[$outstanding->key] = $outstanding;
            }
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
