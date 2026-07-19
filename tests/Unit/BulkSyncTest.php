<?php

use Brain\Monkey\Functions;
use Datalumo\Wp\Sync\BulkSync;

beforeEach(function () {
    seedSyncConfig();
    $this->sync = new BulkSync();
});

it('starts a run with a fresh token, zeroed progress, and a first batch', function () {
    $status = $this->sync->start('sync-1');

    $state = syncState();

    expect($state['run'])->toBe('run-1')
        ->and($state['processed'])->toBe(0)
        ->and($state['finished'])->toBeNull()
        ->and($status['total'])->toBe(3);

    // The first batch is enqueued at offset 0 carrying the run token.
    expect(scheduledActions())->toHaveCount(1)
        ->and(scheduledActions()[0]['hook'])->toBe(BulkSync::BATCH_HOOK)
        ->and(scheduledActions()[0]['args'])->toBe(['sync-1', 0, 'run-1']);
});

it('mints a new token on restart so the previous run is superseded', function () {
    $this->sync->start('sync-1');
    $this->sync->start('sync-1');

    // Second start wins; its token is the current one.
    expect(syncState()['run'])->toBe('run-2')
        ->and(syncState()['processed'])->toBe(0);

    expect(scheduledActions()[1]['args'])->toBe(['sync-1', 0, 'run-2']);
});

it('cancelling marks the run finished and clears scheduled batches', function () {
    $this->sync->start('sync-1');

    $this->sync->cancel('sync-1');

    expect(syncState()['finished'])->not->toBeNull()
        ->and($GLOBALS['__unscheduled'])->toBe(1);
});

it('ignores a batch whose token is no longer current', function () {
    // A run R2 is live, but a batch left over from R1 fires.
    putSyncState(['run' => 'run-2', 'total' => 3, 'processed' => 1, 'started' => 100, 'finished' => null]);

    // A stale batch bails before it ever queries posts.
    Functions\expect('get_posts')->never();

    $this->sync->processBatch('sync-1', 50, 'run-1');

    // It must not touch progress, finish the run, or schedule a successor.
    expect(syncState()['processed'])->toBe(1)
        ->and(syncState()['finished'])->toBeNull()
        ->and(scheduledActions())->toBeEmpty();
});

it('does not let a stale terminal batch finish a superseded run', function () {
    // This is the "stuck at the previous mark" regression: an old batch that
    // runs past the end must not stamp finished onto the new run.
    putSyncState(['run' => 'run-2', 'total' => 3, 'processed' => 0, 'started' => 100, 'finished' => null]);
    Functions\when('get_posts')->justReturn([]);

    $this->sync->processBatch('sync-1', 999, 'run-1');

    expect(syncState()['finished'])->toBeNull()
        ->and(syncState()['run'])->toBe('run-2');
});

it('finishes the run when the current batch runs past the last post', function () {
    putSyncState(['run' => 'run-1', 'total' => 3, 'processed' => 3, 'started' => 100, 'finished' => null]);
    Functions\when('get_posts')->justReturn([]);

    $this->sync->processBatch('sync-1', 999, 'run-1');

    expect(syncState()['finished'])->not->toBeNull();
});

it('reports running only while a run is open and batches remain', function () {
    $this->sync->start('sync-1');

    $GLOBALS['__has_pending'] = true;
    expect($this->sync->status('sync-1')['running'])->toBeTrue();

    // Once the chain drains, running goes false even before finished is set.
    $GLOBALS['__has_pending'] = false;
    expect($this->sync->status('sync-1')['running'])->toBeFalse();
});

it('surfaces a stored run error through status', function () {
    putSyncState(['run' => 'run-1', 'total' => 3, 'processed' => 1, 'started' => 100, 'finished' => 200, 'error' => 'Plan limit reached.']);

    $status = $this->sync->status('sync-1');

    expect($status['error'])->toBe('Plan limit reached.')
        ->and($status['running'])->toBeFalse();
});
