<?php

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Accounts\Actions\AgreementPageProps;
use Splicewire\Beam\Agreements\Data\OutstandingRequirementData;
use Splicewire\Beam\Agreements\RequirementAdmission;
use Splicewire\Beam\Agreements\RequirementDeclarations;
use Splicewire\Beam\Agreements\RequirementResolver;
use Splicewire\Beam\Agreements\Requires;

it('omits agreement props when the seam read route or declarations are absent', function (): void {
    expect(AgreementPageProps::forBehavior('auth.register'))->toBe([]);

    app()->instance(RequirementDeclarations::class, new Agreements14PageDeclarations([new Requires('terms')]));

    expect(AgreementPageProps::forBehavior('auth.register'))->toBe([]);
});

it('uses declared keys and the named read route, adding member coordinates only for an outstanding requirement', function (): void {
    $route = Route::get('/beam/agreements/agreements/{id}', fn () => [])->name('beam.agreements.agreements.show');
    app('router')->getRoutes()->refreshNameLookups();
    app()->instance(RequirementDeclarations::class, new Agreements14PageDeclarations([new Requires('terms'), new Requires('privacy')]));
    app()->instance(RequirementAdmission::class, new RequirementAdmission(new Agreements14PageResolver));
    expect($route->getName())->toBe('beam.agreements.agreements.show');

    $prompts = AgreementPageProps::forBehavior('auth.register')['agreements'];

    expect($prompts)->toHaveCount(2)
        ->and($prompts[0]->toArray())->toBe([
            'key' => 'terms',
            'readUrl' => '/beam/agreements/agreements/terms',
            'required' => true,
            'version' => 3,
            'digest' => 'frozen-digest',
        ])
        ->and($prompts[1]->toArray())->toBe([
            'key' => 'privacy',
            'readUrl' => '/beam/agreements/agreements/privacy',
            'required' => false,
        ]);
});

final class Agreements14PageDeclarations implements RequirementDeclarations
{
    /** @param list<Requires> $requirements */
    public function __construct(private array $requirements) {}

    public function declares(string $behavior): bool
    {
        return $this->requirementsFor($behavior) !== [];
    }

    public function requirementsFor(string $behavior): array
    {
        return $behavior === 'auth.register' ? $this->requirements : [];
    }

    public function all(): array
    {
        return ['auth.register' => $this->requirements];
    }
}

final class Agreements14PageResolver implements RequirementResolver
{
    public function outstanding(mixed $party, string $behavior, mixed $subject, array $submittedMembers): array
    {
        return [new OutstandingRequirementData(
            key: 'terms',
            version: 3,
            digest: 'frozen-digest',
            kind: 'contract',
            title: 'Terms',
            scope: 'party',
            subject: null,
            readUrl: '/beam/agreements/agreements/terms',
            acceptOperation: 'agreements.accept',
            member: null,
        )];
    }
}
