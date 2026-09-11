<?php

declare(strict_types=1);

namespace Splicewire\Beam\Accounts\Data\Pages;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The `entry` prop a hand-written Inertia page shares so its Mainframe host can address the beam-ux row
 * the page means.
 *
 * It used to carry `{id, slug}` alone — enough to address a WRITE and nothing else — and both of the
 * G2 authoring defects measured on beam.test 2026-09-11 were that omission:
 *
 *  - **no artifact** (G2-BEAM-AUTHOR-ENTRY): the owner authored `/`, Save reported "Saved" truthfully,
 *    and no reader ever saw the change — the page rendered its packaged default tree because the props
 *    named no compiled body to read. {@see PageEntryArtifactData}.
 *  - **no format** (the severe half of the same row): the operator dock opened the JsonDoc canvas on an
 *    mdx entry and one Save replaced the mdx source with a canvas tree, blanking the public page. The
 *    server refuses that write now; `format` is what lets a client not attempt it.
 *
 * Both are things only the SERVER knows — a compile-time frontend map cannot carry a per-database uuid,
 * an artifact version, or a row's body language — which is the same reason this shape exists at all.
 */
#[TypeScript]
final class PageEntryData extends Data
{
    /**
     * @param  string  $id  the entry's uuid — the ADR-0214 §2 addressing key for the body transport
     * @param  string  $slug  the domain slug; the editor's label and the frontend default-tree seed key
     * @param  string|null  $format  the entry's body language (`tsx` / `mdx` / `css` / …, beam-ux's
     *                               `UxFormat`). **Null means the host did not say**, which a client
     *                               reads as unknown and never as "any" — a page that does not declare
     *                               its format must not be offered an editor that could destroy it.
     * @param  PageEntryArtifactData|null  $artifact  where to READ this entry's compiled body, or null
     *                                                when it has never been authored (which a reader
     *                                                states as such, rather than telling a guest to run
     *                                                an artisan command)
     */
    public function __construct(
        public string $id,
        public string $slug,
        public ?string $format = null,
        public ?PageEntryArtifactData $artifact = null,
    ) {}
}
