<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;

beforeAll(function (): void {
    $this->share('prepared', ['file']);
    $this->share('trace', ['file.before_all']);
});

beforeEach(function (): void {
    $this->share('trace', [...$this->get('trace'), 'file.before_each.1']);
});

beforeEach(function (): void {
    $this->share('trace', [...$this->get('trace'), 'file.before_each.2']);
});

afterEach(function (): void {
    $this->share('trace', [...$this->get('trace'), 'file.after_each.1']);
});

afterEach(function (): void {
    $this->share('trace', [...$this->get('trace'), 'file.after_each.2']);
});

afterAll(function (): void {
    if ($this->get('prepared') !== ['file']) {
        throw new RuntimeException('A child mutated the prepared file scope.');
    }
});

describe('parallel', function (): void {
    beforeAll(function (): void {
        $this->share('prepared', [...$this->get('prepared'), 'parallel']);
    });

    for ($worker = 0; $worker < 12; $worker++) {
        test('worker '.$worker, function () use ($worker): void {
            if ($this->get('prepared') !== ['file', 'parallel']) {
                throw new RuntimeException('A parallel worker inherited the wrong snapshot.');
            }

            usleep((24 - $worker) * 10_000);
            echo 'worker:'.$worker;
        });
    }
});

describe('serial', function (): void {
    for ($worker = 0; $worker < 3; $worker++) {
        test('serial worker '.$worker, function (): void {
            usleep(60_000);
        });
    }
});

describe('snapshots', function (): void {
    beforeAll(function (): void {
        $this->share('prepared', [...$this->get('prepared'), 'snapshots']);
    });

    describe('same', function (): void {
        beforeAll(function (): void {
            $this->share('prepared', [...$this->get('prepared'), 'first']);
        });

        test('reads first duplicate snapshot', function (): void {
            if ($this->get('prepared') !== ['file', 'snapshots', 'first']) {
                throw new RuntimeException('The first duplicate scope inherited the wrong snapshot.');
            }
        });
    });

    describe('same', function (): void {
        beforeAll(function (): void {
            $this->share('prepared', [...$this->get('prepared'), 'second']);
        });

        test('reads second duplicate snapshot', function (): void {
            if ($this->get('prepared') !== ['file', 'snapshots', 'second']) {
                throw new RuntimeException('The second duplicate scope inherited the wrong snapshot.');
            }
        });
    });
});

describe('setup unwind', function (): void {
    beforeEach(function (): void {
        $this->share('trace', [...$this->get('trace'), 'unwind.before_each.1']);
    });

    beforeEach(function (): void {
        throw new RuntimeException('setup failed');
    });

    afterEach(function (): void {
        $this->share('trace', [...$this->get('trace'), 'unwind.after_each']);
    });

    test('unwinds completed levels only', function (): void {
        $this->share('trace', [...$this->get('trace'), 'unwind.body']);
    });
});

describe('assertion and teardown', function (): void {
    afterEach(function (): void {
        throw new RuntimeException('teardown failed');
    });

    test('keeps body and teardown failures', function (): never {
        throw new AssertionFailedError('assertion failed');
    });
});

describe('before all fails', function (): void {
    beforeAll(function (): never {
        throw new RuntimeException('before all failed');
    });

    afterAll(function (): never {
        throw new RuntimeException('afterAll ran for an uninitialized scope.');
    });

    test('is blocked by before all', function (): void {
        $this->share('forbidden', true);
    });

    describe('blocked child', function (): void {
        beforeAll(function (): never {
            throw new RuntimeException('A blocked child scope was materialized.');
        });

        test('is recursively blocked', function (): void {
            $this->share('forbidden', true);
        });
    });
});

describe('sibling after blocked scope', function (): void {
    test('still runs sibling scope', function (): void {
        echo 'sibling passed';
    });
});

describe('after all fails', function (): void {
    afterAll(function (): never {
        throw new RuntimeException('after all failed');
    });

    test('keeps passing child result', function (): void {
        //
    });
});

describe('process failures', function (): void {
    test('classifies php exception', function (): never {
        throw new RuntimeException('ordinary exception');
    });

    test('classifies php fatal', function (): never {
        trigger_error('fatal child', E_USER_ERROR);
    });

    test('classifies signal', function (): never {
        posix_kill(getmypid(), SIGTERM);

        exit(99);
    });

    test('classifies missing terminal frame', function (): never {
        exit(23);
    });

    test('kills a timed out process tree', function (): never {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, SIG_IGN);
        $taskPid = getmypid();
        $marker = '/tmp/drove-timeout-tree-'.$taskPid;
        @unlink($marker);
        $grandchild = pcntl_fork();

        if ($grandchild === -1) {
            throw new RuntimeException('Unable to fork timeout grandchild.');
        }

        if ($grandchild === 0) {
            usleep(250_000);
            file_put_contents($marker, 'escaped');

            while (true) {
                usleep(10_000);
            }
        }

        while (true) {
            usleep(10_000);
        }
    });

    test('frames large output', function (): void {
        echo str_repeat('x', 1_200_000);
    });

    test('runs sentinel after failures', function (): void {
        echo 'sentinel passed';
    });
});
