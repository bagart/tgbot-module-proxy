import { Head, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Proxy', href: '/proxy' },
    { title: 'Settings', href: '/proxy/settings' },
];

interface Settings {
    selection_strategy: string;
    lease_ttl_seconds: number;
    reaper_batch_size: number;
    max_concurrent_probes: number;
    hysteresis_degrade: number;
    hysteresis_failing: number;
    hysteresis_dead: number;
}

export default function ProxySettings({ policy }: { policy: Settings }) {
    const { data, setData, put, processing } = useForm(policy);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put('/proxy/settings');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Proxy Settings" />
            <div className="space-y-6 p-6">
                <Heading
                    variant="small"
                    title="Proxy Settings"
                    description="Configure proxy selection, leases, and health thresholds."
                />

                <form onSubmit={handleSubmit} className="space-y-6">
                    <div className="rounded-lg border p-4 space-y-4">
                        <h3 className="text-sm font-medium">Selection &amp; Leases</h3>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label className="mb-1 block text-xs text-muted-foreground">Selection Strategy</label>
                                <select
                                    value={data.selection_strategy}
                                    onChange={(e) => setData('selection_strategy', e.target.value)}
                                    className="w-full rounded-md border bg-background px-3 py-1.5 text-sm"
                                >
                                    <option value="round_robin">Round Robin</option>
                                    <option value="random">Random</option>
                                    <option value="least_used">Least Used</option>
                                    <option value="weighted">Weighted</option>
                                </select>
                            </div>
                            <div>
                                <label className="mb-1 block text-xs text-muted-foreground">Lease TTL (seconds)</label>
                                <input
                                    type="number"
                                    value={data.lease_ttl_seconds}
                                    onChange={(e) => setData('lease_ttl_seconds', parseInt(e.target.value))}
                                    className="w-full rounded-md border bg-background px-3 py-1.5 text-sm"
                                />
                            </div>
                            <div>
                                <label className="mb-1 block text-xs text-muted-foreground">Reaper Batch Size</label>
                                <input
                                    type="number"
                                    value={data.reaper_batch_size}
                                    onChange={(e) => setData('reaper_batch_size', parseInt(e.target.value))}
                                    className="w-full rounded-md border bg-background px-3 py-1.5 text-sm"
                                />
                            </div>
                            <div>
                                <label className="mb-1 block text-xs text-muted-foreground">Max Concurrent Probes</label>
                                <input
                                    type="number"
                                    value={data.max_concurrent_probes}
                                    onChange={(e) => setData('max_concurrent_probes', parseInt(e.target.value))}
                                    className="w-full rounded-md border bg-background px-3 py-1.5 text-sm"
                                />
                            </div>
                        </div>
                    </div>

                    <div className="rounded-lg border p-4 space-y-4">
                        <h3 className="text-sm font-medium">Health Hysteresis</h3>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div>
                                <label className="mb-1 block text-xs text-muted-foreground">Failures to Degraded</label>
                                <input
                                    type="number"
                                    value={data.hysteresis_degrade}
                                    onChange={(e) => setData('hysteresis_degrade', parseInt(e.target.value))}
                                    className="w-full rounded-md border bg-background px-3 py-1.5 text-sm"
                                />
                            </div>
                            <div>
                                <label className="mb-1 block text-xs text-muted-foreground">Failures to Failing</label>
                                <input
                                    type="number"
                                    value={data.hysteresis_failing}
                                    onChange={(e) => setData('hysteresis_failing', parseInt(e.target.value))}
                                    className="w-full rounded-md border bg-background px-3 py-1.5 text-sm"
                                />
                            </div>
                            <div>
                                <label className="mb-1 block text-xs text-muted-foreground">Failures to Dead</label>
                                <input
                                    type="number"
                                    value={data.hysteresis_dead}
                                    onChange={(e) => setData('hysteresis_dead', parseInt(e.target.value))}
                                    className="w-full rounded-md border bg-background px-3 py-1.5 text-sm"
                                />
                            </div>
                        </div>
                    </div>

                    <button
                        type="submit"
                        disabled={processing}
                        className="rounded-md bg-primary px-6 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                    >
                        Save Settings
                    </button>
                </form>
            </div>
        </AppLayout>
    );
}
