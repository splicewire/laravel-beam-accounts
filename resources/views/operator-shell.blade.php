{{--
    The operator home (`operator.home`), server-rendered by splicewire/laravel-beam-accounts. Data:
    `$page` (Splicewire\Beam\Accounts\Data\Pages\OperatorHomePageData) and `$developerNote` (only when the host sets
    `beam.accounts.operator_shell.developer_note`).
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Operator</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { margin: 0; padding: 64px 24px; font-family: ui-sans-serif, system-ui, sans-serif; background: #f4f4f5; color: #18181b; }
        .wrap { max-width: 56rem; margin: 0 auto; }
        h1 { font-size: 1.875rem; font-weight: 600; margin: 0; }
        h2 { font-size: 0.75rem; letter-spacing: 0.05em; text-transform: uppercase; color: #71717a; font-weight: 600; margin: 2.5rem 0 0.75rem; }
        .sub { color: #71717a; font-size: 0.875rem; margin-top: 0.25rem; }
        .stats { display: flex; flex-wrap: wrap; gap: 1rem; margin-top: 1.5rem; }
        .stat { border: 1px solid #e4e4e7; border-radius: 0.75rem; background: #fff; padding: 1.25rem; min-width: 8rem; }
        .stat b { font-size: 1.875rem; font-family: ui-monospace, monospace; display: block; }
        .stat span { font-size: 0.75rem; letter-spacing: 0.05em; text-transform: uppercase; color: #71717a; }
        .areas { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr)); gap: 0.75rem; }
        .areas a { display: block; border: 1px solid #e4e4e7; border-radius: 0.75rem; background: #fff; padding: 1rem 1.25rem; color: inherit; font-weight: 500; text-decoration: none; }
        .areas a:hover, .areas a:focus-visible { border-color: #a1a1aa; }
        .empty { font-size: 0.875rem; color: #52525b; margin: 0; }
        .note { margin-top: 2.5rem; font-size: 0.875rem; color: #71717a; max-width: 46rem; border-top: 1px dashed #d4d4d8; padding-top: 1rem; }
        code { background: #e4e4e7; padding: 0.1rem 0.35rem; border-radius: 0.25rem; }
    </style>
</head>
<body>
    <main class="wrap">
        <h1>Operator</h1>
        <p class="sub">Signed in as {{ $page->staff->name }} ({{ $page->staff->email }})</p>

        <div class="stats">
            <div class="stat"><b>{{ $page->stats->users }}</b><span>Users</span></div>
            @if ($page->stats->teams !== null)
                <div class="stat"><b>{{ $page->stats->teams }}</b><span>Teams</span></div>
            @endif
            @if ($page->stats->pendingInvitations !== null)
                <div class="stat"><b>{{ $page->stats->pendingInvitations }}</b><span>Pending invitations</span></div>
            @endif
        </div>

        <h2 id="operator-areas">Manage</h2>
        @if ($page->areas === [])
            <p class="empty">This site has no operator pages yet. When it adds some, they are listed here.</p>
        @else
            <ul class="areas" aria-labelledby="operator-areas">
                @foreach ($page->areas as $area)
                    <li><a href="{{ $area->href }}">{{ $area->label }}</a></li>
                @endforeach
            </ul>
        @endif

        @if ($developerNote)
            <aside class="note" aria-label="Developer note">
                Developer note, not for operators: it shows because
                <code>beam.accounts.operator_shell.developer_note</code> is on. This page is the default rendered by
                <code>splicewire/laravel-beam-accounts</code> (server-rendered, no build step, no host file). Publish
                <code>resources/js/pages/operator/dashboard.tsx</code>
                (<code>vendor:publish --tag=beam-accounts-operator-shell</code>) to eject into an Inertia page you own;
                this route prefers that file the moment it exists. Name or hide the linked pages with
                <code>beam.accounts.operator_shell.areas</code>.
            </aside>
        @endif
    </main>
</body>
</html>
