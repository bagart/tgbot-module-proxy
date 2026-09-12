import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Proxy', href: '/proxy' },
    { title: 'Audit Jobs', href: '/proxy/jobs' },
];

interface AuditJob {
    id: string;
    trigger: string;
    status: string;
    target_count: number;
    started_at: string | null;
    completed_at: string | null;
    created_at: string;
}

export default function ProxyJobs({ jobs }: { jobs: AuditJob[] }) {
    const statusColors: Record<string, string> = {
        pending: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
        running: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
        completed: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
        failed: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        cancelled: 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200',
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Proxy Audit Jobs" />
            <div className="space-y-6 p-6">
                <Heading
                    variant="small"
                    title="Audit Jobs"
                    description="View and trigger proxy audit jobs."
                />

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b bg-muted/50">
                                <th className="px-4 py-2 text-left font-medium">Trigger</th>
                                <th className="px-4 py-2 text-left font-medium">Status</th>
                                <th className="px-4 py-2 text-left font-medium">Targets</th>
                                <th className="px-4 py-2 text-left font-medium">Started</th>
                                <th className="px-4 py-2 text-left font-medium">Completed</th>
                                <th className="px-4 py-2 text-left font-medium">Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            {jobs.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="px-4 py-8 text-center text-muted-foreground">
                                        No audit jobs yet.
                                    </td>
                                </tr>
                            ) : (
                                jobs.map((job) => (
                                    <tr key={job.id} className="border-b last:border-b-0">
                                        <td className="px-4 py-2">
                                            <code className="text-xs">{job.trigger}</code>
                                        </td>
                                        <td className="px-4 py-2">
                                            <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${statusColors[job.status] ?? ''}`}>
                                                {job.status}
                                            </span>
                                        </td>
                                        <td className="px-4 py-2">{job.target_count}</td>
                                        <td className="px-4 py-2 text-xs text-muted-foreground">
                                            {job.started_at ? new Date(job.started_at).toLocaleString() : '—'}
                                        </td>
                                        <td className="px-4 py-2 text-xs text-muted-foreground">
                                            {job.completed_at ? new Date(job.completed_at).toLocaleString() : '—'}
                                        </td>
                                        <td className="px-4 py-2 text-xs text-muted-foreground">
                                            {new Date(job.created_at).toLocaleString()}
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
