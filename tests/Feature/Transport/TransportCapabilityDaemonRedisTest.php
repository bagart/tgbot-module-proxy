<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Audit\RedisStreamsAuditDeliveryQueue;
use BAGArt\ProxyOperations\Transport\TransportCapabilityDaemon;
use BAGArt\ProxyOperations\Wire\AuditResultStatus;
use BAGArt\ProxyOperations\Wire\AuditTaskV1;

/**
 * End-to-end daemon test over real Redis Streams:
 * enqueue task → daemon tick → fiber executes → result appears in results stream.
 * Skipped when no Redis is reachable.
 */
it('daemon tick loop produces results over real Redis Streams', function (): void {
    $redis = auditDeliveryTestRedis();

    if ($redis === null) {
        markTestSkipped('No Redis available for the daemon feature test.');
    }

    $queue = new RedisStreamsAuditDeliveryQueue(
        redis: $redis,
        tasksStream: 'proxy:audit:tasks:daemon:'.uniqid(),
        resultsStream: 'proxy:audit:results:daemon:'.uniqid(),
    );

    $governor = w1bGovernor();
    $scheduler = new W1bScheduler;

    $daemon = new TransportCapabilityDaemon(
        handler: w1bHandler(tool: new W1bOkTool, governor: $governor),
        queue: $queue,
        governor: $governor,
        scheduler: $scheduler,
        taskBatchSize: 1,
        pressureQueueCapacity: 256,
    );

    $task = w1bTask('redis-e2e-1');
    $queue->enqueue($task);

    $daemon->tick(0);

    $scheduler->tick(0);

    usleep(200_000);

    $results = $queue->consumeResults(10);

    expect($results)->toHaveCount(1);
    expect($results[0]->taskId)->toBe('task-redis-e2e-1');
    expect($results[0]->status)->toBe(AuditResultStatus::Completed);
    expect($results[0]->checkerNodeId)->not->toBeEmpty();
})->covers(\BAGArt\ProxyOperations\Transport\TransportCapabilityDaemon::class);
