<?php

use Symfony\Component\Process\Process;

it('auto dismisses success notifications after four seconds', function () {
    runAppNotificationContract(<<<'JS'
const clock = new FakeClock();
const notification = createAppNotification({
    clock,
    message: 'Changes saved.',
    type: 'success',
});

notification.init();

assert.equal(notification.visible, true);
assert.equal(clock.nextDelay(), 4_000);

clock.advance(3_999);
assert.equal(notification.visible, true);

clock.advance(1);
assert.equal(notification.visible, false);
JS);
});

it('keeps error notifications visible until the user dismisses them', function () {
    runAppNotificationContract(<<<'JS'
const clock = new FakeClock();
const notification = createAppNotification({ clock });

notification.show({
    message: 'The changes could not be saved.',
    type: 'error',
});

assert.equal(notification.visible, true);
assert.equal(notification.type, 'error');
assert.equal(clock.nextDelay(), null);

clock.advance(30_000);
assert.equal(notification.visible, true);

notification.dismiss();
assert.equal(notification.visible, false);
JS);
});

it('restarts the dismissal timer when a new success arrives', function () {
    runAppNotificationContract(<<<'JS'
const clock = new FakeClock();
const notification = createAppNotification({ clock });

notification.show({ message: 'First save.', type: 'success' });
clock.advance(3_000);
notification.show({ message: 'Second save.', type: 'success' });

clock.advance(1_000);
assert.equal(notification.visible, true);
assert.equal(notification.message, 'Second save.');

clock.advance(3_000);
assert.equal(notification.visible, false);
JS);
});

function runAppNotificationContract(string $contract): void
{
    $script = <<<'JS'
import assert from 'node:assert/strict';
import { createAppNotification } from './resources/js/app-notification.js';

class FakeClock {
    constructor() {
        this.currentTime = 0;
        this.nextTimerId = 1;
        this.timers = new Map();
    }

    setTimeout = (callback, delay) => {
        const timerId = this.nextTimerId++;
        this.timers.set(timerId, {
            callback,
            dueAt: this.currentTime + delay,
        });

        return timerId;
    };

    clearTimeout = (timerId) => {
        this.timers.delete(timerId);
    };

    advance(milliseconds) {
        this.currentTime += milliseconds;

        for (const [timerId, timer] of [...this.timers.entries()]) {
            if (timer.dueAt <= this.currentTime) {
                this.timers.delete(timerId);
                timer.callback();
            }
        }
    }

    nextDelay() {
        const dueAt = [...this.timers.values()]
            .map((timer) => timer.dueAt)
            .sort((left, right) => left - right)[0];

        return dueAt === undefined ? null : dueAt - this.currentTime;
    }
}
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script."\n".$contract),
        dirname(__DIR__, 2),
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
}
