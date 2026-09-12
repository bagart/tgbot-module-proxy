<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Http\Controllers;

use BAGArt\ProxyOperations\Audit\JobStarter;
use BAGArt\ProxyOperations\Models\ProxyAuditJob;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class ProxyJobsController
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    public function index(): Response
    {
        $this->tenant->set(Auth::id());

        $jobs = ProxyAuditJob::query()
            ->orderByDesc('created_at')
            ->paginate(30)
            ->through(fn (ProxyAuditJob $job) => [
                'id' => $job->id,
                'trigger' => $job->trigger->value,
                'status' => $job->status->value,
                'target_count' => $job->target_count,
                'started_at' => $job->started_at?->toIso8601String(),
                'completed_at' => $job->completed_at?->toIso8601String(),
                'created_at' => $job->created_at->toIso8601String(),
            ]);

        $this->tenant->forget();

        return Inertia::render('proxy/jobs', [
            'jobs' => $jobs,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'target_ids' => 'required|array|min:1',
            'target_ids.*' => 'string',
        ]);

        $this->tenant->set(Auth::id());

        $jobStarter = app(JobStarter::class);
        $jobStarter->createJob(
            trigger: 'manual',
            targetIds: $validated['target_ids'],
        );

        $this->tenant->forget();

        return redirect()->route('proxy.jobs.index');
    }
}
