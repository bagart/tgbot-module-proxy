import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Proxy', href: '/proxy' },
    { title: 'Inventory', href: '/proxy/inventory' },
];

interface InventoryItem {
    id: string;
    protocol: string;
    host: string;
    port: number;
    state: string;
    quarantined: boolean;
    lastChecked: string | null;
}

export default function ProxyInventory({
    items,
    total,
}: {
    items: InventoryItem[];
    total: number;
}) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Proxy Inventory" />

            <h1 className="sr-only">Proxy Inventory</h1>

            <div className="space-y-6 p-6">
                <Heading
                    variant="small"
                    title="Inventory"
                    description={`${total} proxy endpoints (masked — no credentials shown).`}
                />

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b bg-muted/50">
                                <th className="px-4 py-2 text-left font-medium">
                                    Protocol
                                </th>
                                <th className="px-4 py-2 text-left font-medium">
                                    Host
                                </th>
                                <th className="px-4 py-2 text-left font-medium">
                                    Port
                                </th>
                                <th className="px-4 py-2 text-left font-medium">
                                    State
                                </th>
                                <th className="px-4 py-2 text-left font-medium">
                                    Quarantined
                                </th>
                                <th className="px-4 py-2 text-left font-medium">
                                    Last Checked
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {items.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="px-4 py-8 text-center text-muted-foreground"
                                    >
                                        No proxy endpoints found.
                                    </td>
                                </tr>
                            ) : (
                                items.map((item) => (
                                    <tr
                                        key={item.id}
                                        className="border-b last:border-b-0"
                                    >
                                        <td className="px-4 py-2">
                                            <code className="text-xs">
                                                {item.protocol}
                                            </code>
                                        </td>
                                        <td className="px-4 py-2 font-mono text-xs">
                                            {item.host}
                                        </td>
                                        <td className="px-4 py-2">{item.port}</td>
                                        <td className="px-4 py-2">
                                            <StateBadge state={item.state} />
                                        </td>
                                        <td className="px-4 py-2">
                                            {item.quarantined ? (
                                                <span className="text-amber-600 dark:text-amber-400">
                                                    Yes
                                                </span>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    No
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-2 text-xs text-muted-foreground">
                                            {item.lastChecked ?? 'Never'}
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

function StateBadge({ state }: { state: string }) {
    const colors: Record<string, string> = {
        working: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
        degraded: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
        failing: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        dead: 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200',
        new: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
        testing: 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200',
        retired: 'bg-gray-100 text-gray-500 dark:bg-gray-900 dark:text-gray-400',
    };

    return (
        <span
            className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${
                colors[state] ?? 'bg-gray-100 text-gray-800'
            }`}
        >
            {state}
        </span>
    );
}
