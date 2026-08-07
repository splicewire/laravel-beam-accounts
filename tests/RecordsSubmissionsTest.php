<?php

use Illuminate\Support\Facades\Event;
use Splicewire\Beam\Accounts\Submissions\RecordsSubmissions;
use Splicewire\Beam\Events\BeamParticlePersisted;
use Splicewire\Beam\Models\BeamSubmission;

it('persists a capture as a BeamSubmission through the write pipeline', function () {
    $submission = app(RecordsSubmissions::class)->record(
        formKey: 'waitlist',
        schemaRef: 'waitlist/1',
        payload: ['email' => 'ada@example.test'],
        context: ['source' => 'marquee-soon'],
    );

    expect($submission)->toBeInstanceOf(BeamSubmission::class)
        ->and($submission->exists)->toBeTrue()
        ->and($submission->form_key)->toBe('waitlist')
        ->and($submission->schema_ref)->toBe('waitlist/1')
        ->and($submission->payload)->toBe(['email' => 'ada@example.test'])
        ->and($submission->context)->toBe(['source' => 'marquee-soon']);
});

it('accepts a payload that does not conform to any schema — capture, not validated CRUD', function () {
    // No $schema passed at all — the write pipeline's target resolver finds nothing registered
    // for the 'interest' stem, so ValidateStage skips validation entirely regardless of the
    // permissive acceptance gate. Proves the capture flow never rejects at write time.
    $submission = app(RecordsSubmissions::class)->record(
        formKey: 'interest',
        schemaRef: null,
        payload: ['name' => 'partial only'],
    );

    expect($submission->exists)->toBeTrue();
});

it('stamps the caller-resolved schema into meta and emits BeamParticlePersisted carrying it', function () {
    // RecordsSubmissions' contract ends at "write + stamp + emit" — whether that then produces an
    // actual notification send is splicewire/laravel-beam-notifications' own tested concern (its
    // NotifyOnSubmission listener reacts to ANY BeamParticlePersisted, not just a BeamSubmission).
    // beam-accounts does not require beam-notifications, so this only asserts the event contract.
    Event::fake([BeamParticlePersisted::class]);

    $schema = [
        'title' => 'Waitlist',
        'type' => 'object',
        'x-beam-notify' => ['to' => ['ops@site.test'], 'channels' => ['mail']],
    ];

    $submission = app(RecordsSubmissions::class)->record(
        formKey: 'waitlist',
        schemaRef: 'waitlist/1',
        payload: ['email' => 'ada@example.test'],
        schema: $schema,
    );

    expect($submission->meta)->toBe(['schema' => $schema]);

    Event::assertDispatched(
        BeamParticlePersisted::class,
        fn (BeamParticlePersisted $event) => $event->record->is($submission)
            && data_get($event->record, 'meta.schema.x-beam-notify.to') === ['ops@site.test'],
    );
});

it('emits BeamParticlePersisted with no schema in meta when none is passed (nothing for notify to read)', function () {
    Event::fake([BeamParticlePersisted::class]);

    app(RecordsSubmissions::class)->record(
        formKey: 'interest',
        schemaRef: null,
        payload: ['name' => 'Ada'],
    );

    Event::assertDispatched(
        BeamParticlePersisted::class,
        fn (BeamParticlePersisted $event) => data_get($event->record, 'meta.schema') === null,
    );
});
