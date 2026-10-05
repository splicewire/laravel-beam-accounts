{{-- ux-walkthrough UX-11: the packaged GET /logout confirm. Signing out is a POST with a CSRF token, never a GET. --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Sign out · {{ $appName }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { margin: 0; padding: 96px 24px; font-family: ui-sans-serif, system-ui, sans-serif; background: #f4f4f5; color: #18181b; }
        .card { max-width: 24rem; margin: 0 auto; background: #fff; border: 1px solid #e4e4e7; border-radius: 0.75rem; padding: 1.5rem; }
        h1 { font-size: 1.25rem; font-weight: 600; margin: 0 0 0.5rem; }
        p { color: #52525b; font-size: 0.875rem; margin: 0 0 1.25rem; }
        .row { display: flex; gap: 0.75rem; align-items: center; }
        button { background: #18181b; color: #fff; border: 0; border-radius: 0.5rem; padding: 0.55rem 1rem; font-size: 0.875rem; cursor: pointer; }
        a { color: #52525b; font-size: 0.875rem; }
    </style>
</head>
<body>
    <main class="card">
        <h1>Sign out?</h1>
        <p>You will be signed out of {{ $appName }} on this browser.</p>
        <form method="POST" action="{{ $action }}" class="row">
            @csrf
            <button type="submit">Sign out</button>
            <a href="{{ url('/') }}">Cancel</a>
        </form>
    </main>
</body>
</html>
