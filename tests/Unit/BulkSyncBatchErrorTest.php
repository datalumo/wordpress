<?php

use Brain\Monkey\Functions;
use Datalumo\Wp\Sync\BulkSync;

beforeEach(function () {
    seedSyncConfig();
    Functions\when('get_posts')->justReturn([new WP_Post()]);
    putSyncState(['run' => 'r1', 'total' => 400, 'processed' => 350, 'started' => 1, 'finished' => null]);
});

it('stops the run on a 4xx the API rejects, without rescheduling', function () {
    $sync = new BulkSync(fakeClient(422), fakePreparer());

    $sync->processBatch('sync-1', 350, 'r1');

    expect(syncState()['finished'])->not->toBeNull()
        ->and(syncState()['error'])->toContain('350')
        ->and(scheduledActions())->toBeEmpty();
});

it('stops the run on an authentication failure', function () {
    $sync = new BulkSync(fakeClient(401), fakePreparer());

    $sync->processBatch('sync-1', 350, 'r1');

    expect(syncState()['finished'])->not->toBeNull()
        ->and(scheduledActions())->toBeEmpty();
});

it('retries a transient 500 with backoff, then stops once the cap is hit', function () {
    $sync = new BulkSync(fakeClient(500), fakePreparer());

    // MAX_BATCH_ATTEMPTS is 6: the first six failures reschedule the same offset.
    for ($i = 0; $i < 6; $i++) {
        $sync->processBatch('sync-1', 350, 'r1');
    }

    expect(scheduledActions())->toHaveCount(6)
        ->and(scheduledActions()[0]['args'])->toBe(['sync-1', 350, 'r1'])
        ->and(syncState()['finished'])->toBeNull();

    // The seventh exhausts the cap and stops the run with the error shown.
    $sync->processBatch('sync-1', 350, 'r1');

    expect(syncState()['finished'])->not->toBeNull()
        ->and(syncState()['error'])->toContain('attempts');
});

it('advances the offset and clears retry state on a successful batch', function () {
    putSyncState(['run' => 'r1', 'total' => 400, 'processed' => 350, 'started' => 1, 'finished' => null, 'retry_offset' => 350, 'retry_count' => 3]);

    $sync = new BulkSync(fakeClient(), fakePreparer());

    $sync->processBatch('sync-1', 350, 'r1');

    expect(syncState()['processed'])->toBe(351)
        ->and(syncState())->not->toHaveKey('retry_offset')
        ->and(scheduledActions()[0]['args'])->toBe(['sync-1', 400, 'r1']);
});

it('leaves a superseded run untouched when its batch errors', function () {
    // The live run is r2, but a leftover r1 batch fails mid-push.
    putSyncState(['run' => 'r2', 'total' => 400, 'processed' => 10, 'started' => 1, 'finished' => null]);

    $sync = new BulkSync(fakeClient(500), fakePreparer());
    $sync->processBatch('sync-1', 350, 'r1');

    expect(syncState()['run'])->toBe('r2')
        ->and(syncState()['finished'])->toBeNull()
        ->and(syncState()['processed'])->toBe(10)
        ->and(scheduledActions())->toBeEmpty();
});
