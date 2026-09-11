import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Proxy', href: '/proxy' },
];

interface InventorySummary {
    endpoints: number;
    states: Record<string, number>;
    quarantined: number;
}

export default function ProxyDashboard({
    inventory,
}: {
    inventory: InventorySummary;
}) {
    const stateCards = Object.entries(inventory.states).map(
        ([state, count]) => ({
            label: state.charAt(0).toUpperCase() + state.slice(1),
            value: count,
        }),
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Proxy Dashboard" />

            <h1 className="sr-only">Proxy Dashboard</h1>

            <div className="space-y-6 p-6">
                <Heading
                    variant="small"
                    title="Proxy Operations"
                    description="Overview of proxy inventory and health."
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        label="Total Endpoints"
                        value={inventory.endpoints}
                    />
                    <StatCard
                        label="Quarantined"
                        value={inventory.quarantined}
                        variant={
                            inventory.quarantined > 0 ? 'warning' : 'default'
                        }
                    />
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {stateCards.map((card) => (
                        <StatCard
                            key={card.label}
                            label={card.label}
                            value={card.value}
                        />
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}

function StatCard({
    label,
    value,
    variant = 'default',
}: {
    label: string;
    value: number;
    variant?: 'default' | 'warning';
}) {
    return (
        <div
            className={`rounded-lg border p-4 ${
                variant === 'warning'
                    ? 'border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-950'
                    : 'border-border bg-card'
            }`}
        >
            <p className="text-sm text-muted-foreground">{label}</p>
            <p className="mt-1 text-2xl font-semibold">{value}</p>
        </div>
    );
}
