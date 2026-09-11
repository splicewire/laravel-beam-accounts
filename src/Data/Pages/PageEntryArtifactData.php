<?php

declare(strict_types=1);

namespace Splicewire\Beam\Accounts\Data\Pages;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The **address of a page entry's compiled artifact** — `{url, version}`, the same pair
 * `PublicEntryController` shares for a rendered entry, declared here so a HAND-WRITTEN page can share
 * it too.
 *
 * ## Why the reader needed it
 *
 * Measured on beam.test 2026-09-11 (G2-BEAM-AUTHOR-ENTRY): an owner authored `/` through the in-place
 * editor and Save reported "Saved" — truthfully; the body reached the particle and the artifact
 * compiled — and no reader ever saw the change, as owner or guest. The hand-written `site/home` page
 * rendered its packaged DEFAULT tree, because the route shared only `{id, slug}`: enough to address a
 * SAVE, nothing to address a READ. A rendered entry had carried this pair all along; a hand-written page
 * had no way to say it.
 *
 * ## Why it lives in beam-accounts and not beam-ux
 *
 * It is a beam-ux concept, and beam-ux depends DOWN on this package — declaring it there and referencing
 * it from {@see PageEntryData} would invert that edge into a cycle. What travels here is only the
 * ADDRESS, which is stack-blind: a URL and an opaque version token. Everything that knows how to produce
 * one (`EntryArtifactStore`, the `beam.ux.site.artifact` route) stays in beam-ux, where a host's own
 * resolver reaches it.
 *
 * ## `version` is part of the address
 *
 * Not metadata: an edited body compiles to a DIFFERENT URL, and the client keys its dynamic import on
 * the pair so a changed version re-imports instead of reusing the module already in memory. This is the
 * same reasoning ADR-0209 §7 gives for pinning the version INTO the URL — a version-less address the
 * browser caches immutably is how a body edit never reaches a returning reader.
 */
#[TypeScript]
final class PageEntryArtifactData extends Data
{
    /**
     * @param  string  $url  the artifact module's address, version pinned. Never blank here — a page with
     *                       no compiled artifact shares `null` for the whole ref instead, which is what
     *                       tells the client "this entry has never been authored" rather than "the
     *                       artifact failed to load" (the two were one message until this defect).
     * @param  string|null  $version  the opaque version token, for a client that keys its import on it
     *                                separately from the URL. Callers treat it as a token, never parse it.
     */
    public function __construct(
        public string $url,
        public ?string $version = null,
    ) {}
}
