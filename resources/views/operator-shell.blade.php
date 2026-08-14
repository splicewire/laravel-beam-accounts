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
        .sub { color: #71717a; font-size: 0.875rem; margin-top: 0.25rem; }
        .stats { display: flex; gap: 1rem; margin-top: 1.5rem; }
        .stat { border: 1px solid #e4e4e7; border-radius: 0.75rem; background: #fff; padding: 1.25rem; }
        .stat b { font-size: 1.875rem; font-family: ui-monospace, monospace; display: block; }
        .stat span { font-size: 0.75rem; letter-spacing: 0.05em; text-transform: uppercase; color: #71717a; }
        .note { margin-top: 2rem; font-size: 0.875rem; color: #71717a; max-width: 46rem; }
        code { background: #e4e4e7; padding: 0.1rem 0.35rem; border-radius: 0.25rem; }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>Operator</h1>
        <p class="sub">Signed in as {{ $staff['name'] }} ({{ $staff['email'] }})</p>

        <div class="stats">
            <div class="stat"><b>{{ $stats['users'] }}</b><span>Users</span></div>
        </div>

        <p class="note">
            This is the operator realm's front-end — a minimal, package-rendered default from
            <code>splicewire/laravel-beam-accounts</code> (server-rendered, no build step, no host file).
            It's basic on purpose: no resource browsing, no in-place editing yet. Publish
            <code>resources/js/pages/operator/dashboard.tsx</code>
            (<code>vendor:publish --tag=beam-accounts-operator-shell</code>) to eject into a real Inertia
            page you own and can build out — the route prefers that file the moment it exists.
        </p>
    </div>
</body>
</html>
