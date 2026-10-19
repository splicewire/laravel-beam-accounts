import { Head } from '@inertiajs/react';

/**
 * The operator back-office landing: the account figures and links to the operator pages this host mounts. Published
 * here by `splicewire/laravel-beam-accounts` (`vendor:publish --tag=beam-accounts-operator-shell`); the `/operator`
 * route renders this file instead of the package's server-rendered default the moment it exists. It is yours now: add
 * figures, wire in Frame's resource lists, or replace it outright. Props are optional so the same component still
 * renders sensibly if a host later reuses it inside a windowed surface (only shared props thread there).
 *
 * Props: `OperatorHomePageData`. `stats.teams` and `stats.pendingInvitations` are null when this host has no such
 * figure; `areas` are the operator pages the viewer may open (label + path).
 */
type Props = {
    staff?: { name: string; email: string };
    stats?: { users: number; teams?: number | null; pendingInvitations?: number | null };
    areas?: { label: string; href: string }[];
};

function Stat({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-xl border border-border bg-card p-5">
            <div className="font-mono text-3xl font-semibold">{value}</div>
            <div className="mt-1 text-xs tracking-wide text-muted-foreground uppercase">{label}</div>
        </div>
    );
}

export default function OperatorDashboard({ staff, stats, areas }: Props) {
    const s = staff ?? { name: 'Operator', email: 'operator@example.test' };
    const st = stats ?? { users: 0 };
    const links = areas ?? [];

    return (
        <div className="mx-auto max-w-4xl px-6 py-10">
            <Head title="Operator" />
            <h1 className="text-3xl font-semibold">Operator</h1>
            <p className="mt-1 text-sm text-muted-foreground">
                Signed in as {s.name} ({s.email})
            </p>

            <div className="mt-6 grid grid-cols-3 gap-4">
                <Stat label="Users" value={st.users} />
                {st.teams != null && <Stat label="Teams" value={st.teams} />}
                {st.pendingInvitations != null && <Stat label="Pending invitations" value={st.pendingInvitations} />}
            </div>

            <h2 className="mt-10 mb-3 text-xs font-semibold tracking-wide text-muted-foreground uppercase">Manage</h2>
            {links.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    This site has no operator pages yet. When it adds some, they are listed here.
                </p>
            ) : (
                <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    {links.map((area) => (
                        <li key={area.href}>
                            <a
                                href={area.href}
                                className="block rounded-xl border border-border bg-card px-5 py-4 font-medium hover:border-foreground/30"
                            >
                                {area.label}
                            </a>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
