import { Head, useForm, router } from '@inertiajs/react';
import Heading from '@/components/heading';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Proxy', href: '/proxy' },
    { title: 'Pools', href: '/proxy/pools' },
];

interface Pool {
    id: string;
    name: string;
    kind: string;
    enabled: boolean;
    description: string | null;
    members_count: number;
    last_materialized_at: string | null;
}

export default function ProxyPools({ pools }: { pools: Pool[] }) {
    const { data, setData, post, processing } = useForm({
        name: '',
        kind: 'static',
        description: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/proxy/pools');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Proxy Pools" />
            <div className="space-y-6 p-6">
                <Heading
                    variant="small"
                    title="Proxy Pools"
                    description="Manage proxy pools for selection and rotation."
                />

                <div className="rounded-lg border p-4">
                    <h3 className="mb-3 text-sm font-medium">Create Pool</h3>
                    <form onSubmit={handleSubmit} className="flex gap-3">
                        <input
                            type="text"
                            placeholder="Pool name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            className="flex-1 rounded-md border bg-background px-3 py-1.5 text-sm"
                        />
                        <select
                            value={data.kind}
                            onChange={(e) => setData('kind', e.target.value)}
                            className="rounded-md border bg-background px-3 py-1.5 text-sm"
                        >
                            <option value="static">Static</option>
                            <option value="dynamic">Dynamic</option>
                            <option value="hybrid">Hybrid</option>
                        </select>
                        <button
                            type="submit"
                            disabled={processing || !data.name}
                            className="rounded-md bg-primary px-4 py-1.5 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                        >
                            Create
                        </button>
                    </form>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b bg-muted/50">
                                <th className="px-4 py-2 text-left font-medium">Name</th>
                                <th className="px-4 py-2 text-left font-medium">Kind</th>
                                <th className="px-4 py-2 text-left font-medium">Members</th>
                                <th className="px-4 py-2 text-left font-medium">Enabled</th>
                                <th className="px-4 py-2 text-left font-medium">Last Materialized</th>
                                <th className="px-4 py-2 text-left font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {pools.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="px-4 py-8 text-center text-muted-foreground">
                                        No pools created yet.
                                    </td>
                                </tr>
                            ) : (
                                pools.map((pool) => (
                                    <tr key={pool.id} className="border-b last:border-b-0">
                                        <td className="px-4 py-2 font-medium">{pool.name}</td>
                                        <td className="px-4 py-2">
                                            <code className="text-xs">{pool.kind}</code>
                                        </td>
                                        <td className="px-4 py-2">{pool.members_count}</td>
                                        <td className="px-4 py-2">
                                            <span className={pool.enabled ? 'text-green-600' : 'text-muted-foreground'}>
                                                {pool.enabled ? 'Yes' : 'No'}
                                            </span>
                                        </td>
                                        <td className="px-4 py-2 text-xs text-muted-foreground">
                                            {pool.last_materialized_at ? new Date(pool.last_materialized_at).toLocaleString() : 'Never'}
                                        </td>
                                        <td className="px-4 py-2">
                                            <div className="flex gap-2">
                                                <button
                                                    onClick={() => router.post(`/proxy/pools/${pool.id}/materialize`)}
                                                    className="text-xs text-blue-600 hover:underline"
                                                >
                                                    Materialize
                                                </button>
                                                <button
                                                    onClick={() => {
                                                        if (confirm('Delete this pool?')) {
                                                            router.delete(`/proxy/pools/${pool.id}`);
                                                        }
                                                    }}
                                                    className="text-xs text-red-600 hover:underline"
                                                >
                                                    Delete
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
