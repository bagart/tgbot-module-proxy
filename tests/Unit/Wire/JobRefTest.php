<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Wire\JobRef;

function wireJobRef(): JobRef
{
    return new JobRef(jobId: 'job-1', attemptId: 'attempt-1', taskId: 'task-1');
}

it('round-trips through JSON', function (): void {
    expect(JobRef::fromJson(wireJobRef()->jsonSerialize()))->toEqual(wireJobRef());
});

it('rejects an unknown schemaVersion', function (): void {
    $data = wireJobRef()->jsonSerialize();
    $data['schemaVersion'] = 99;

    JobRef::fromJson($data);
})->throws(RuntimeException::class, 'Unsupported JobRef schemaVersion');
