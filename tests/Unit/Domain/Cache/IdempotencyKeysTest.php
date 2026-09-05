<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Cache\JobIdempotencyKey;
use BAGArt\ProxyOperations\Domain\Cache\TaskDeliveryIdempotencyKey;
use BAGArt\ProxyOperations\Wire\JobRef;

it('keeps job and task-delivery scopes distinct', function (): void {
    expect(JobIdempotencyKey::SCOPE)->not->toBe(TaskDeliveryIdempotencyKey::SCOPE);
});

it('produces identical keys for the same client key and different keys per input', function (): void {
    $first = JobIdempotencyKey::fromApiClientKey('client-key-1');
    $second = JobIdempotencyKey::fromApiClientKey('client-key-1');
    $other = JobIdempotencyKey::fromImportBatchHash('client-key-1');

    expect($first->value)->toBe($second->value)
        ->and($first->value)->toStartWith(JobIdempotencyKey::SCOPE.':')
        ->and($other->value)->not->toBe($first->value);
});

it('derives task-delivery keys from taskId plus attemptId', function (): void {
    $refA = new JobRef('job-1', 'attempt-1', 'task-1');
    $refB = new JobRef('job-1', 'attempt-2', 'task-1');

    $first = TaskDeliveryIdempotencyKey::fromJobRef($refA);
    $retry = TaskDeliveryIdempotencyKey::fromJobRef($refA);
    $nextAttempt = TaskDeliveryIdempotencyKey::fromJobRef($refB);

    expect($first->value)->toBe($retry->value)
        ->and($nextAttempt->value)->not->toBe($first->value)
        ->and($first->value)->toStartWith(TaskDeliveryIdempotencyKey::SCOPE.':');
});

it('never collides when one source string is fed through the other factory', function (): void {
    $asJob = JobIdempotencyKey::fromApiClientKey('shared-source-string');

    $asTaskDelivery = TaskDeliveryIdempotencyKey::fromJobRef(new JobRef(
        jobId: 'ignored',
        attemptId: 'shared-source-string',
        taskId: 'shared-source-string',
    ));

    expect($asTaskDelivery->value)->not->toBe($asJob->value);
});

it('rejects empty sources', function (): void {
    JobIdempotencyKey::fromApiClientKey('');
})->throws(RuntimeException::class);

it('rejects JobRefs without attempt or task ids', function (): void {
    TaskDeliveryIdempotencyKey::fromJobRef(new JobRef('job-1', '', 'task-1'));
})->throws(RuntimeException::class);

it('cannot be instantiated directly across types', function (): void {
    $job = JobIdempotencyKey::fromApiClientKey('k');
    $delivery = TaskDeliveryIdempotencyKey::fromJobRef(new JobRef('j', 'a', 't'));

    expect(get_class($job))->not->toBe(get_class($delivery))
        ->and($job->value)->toStartWith(JobIdempotencyKey::SCOPE)
        ->and($delivery->value)->toStartWith(TaskDeliveryIdempotencyKey::SCOPE);
});
