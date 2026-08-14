import { Head } from '@inertiajs/react';

/**
 * The operator back-office landing — a cross-model stats roll-up. Published here by
 * `splicewire/laravel-beam-accounts` (`splicewire:beam:install`, tag `beam-accounts-operator-shell`) so
 * a fresh beam host gets a real `/operator` page with zero authorship — this file is yours the moment
 * it lands on disk, edit it like any other page. Props are optional so the same component still renders
 * sensibly if a host later reuses it inside a windowed surface (only shared props thread there).
 */
type Props = {
    staff?: { name: string; email: string };
    stats?: { users: number };
};

function Stat({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-xl border border-border bg-card p-5">
            <div className="font-mono text-3xl font-semibold">{value}</div>
            <div className="mt-1 text-xs tracking-wide text-muted-foreground uppercase">{label}</div>
        </div>
    );
}

export default function OperatorDashboard({ staff, stats }: Props) {
    const s = staff ?? { name: 'Operator', email: 'operator@example.test' };
    const st = stats ?? { users: 0 };

    return (
        <div className="mx-auto max-w-4xl px-6 py-10">
            <Head title="Operator" />
            <h1 className="text-3xl font-semibold">Operator</h1>
            <p className="mt-1 text-sm text-muted-foreground">
                Signed in as {s.name} ({s.email})
            </p>

            <div className="mt-6 grid grid-cols-3 gap-4">
                <Stat label="Users" value={st.users} />
            </div>

            <p className="mt-8 text-sm text-muted-foreground">
                This is the operator realm&apos;s front-end, published by{' '}
                <code className="rounded bg-muted px-1 py-0.5">splicewire/laravel-beam-accounts</code>.
                It&apos;s an ordinary page you own now — add more stats, wire in Frame&apos;s generic
                particle CRUD resource lists, or replace it outright.
            </p>
        </div>
    );
}
