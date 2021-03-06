<?php

namespace Amp\Loop;

use Amp\Coroutine;
use Amp\Promise;
use React\Promise\PromiseInterface as ReactPromise;
use function Amp\Promise\rethrow;

class UvDriver extends Driver {
    /** @var resource A uv_loop resource created with uv_loop_new() */
    private $handle;

    /** @var resource[] */
    private $events = [];

    /** @var \Amp\Loop\Watcher[]|\Amp\Loop\Watcher[][] */
    private $watchers = [];

    /** @var int[] */
    private $io = [];

    /** @var resource[] */
    private $streams = [];

    /** @var callable */
    private $ioCallback;

    /** @var callable */
    private $timerCallback;

    /** @var callable */
    private $signalCallback;

    public function __construct() {
        $this->handle = \uv_loop_new();

        $this->ioCallback = function ($event, $status, $events, $resource) {
            $watchers = $this->watchers[(int) $event];

            switch ($status) {
                case 0: // OK
                    break;

                // If $status is a severe error, disable all related watchers and notify the loop error handler.
                case \UV::EACCES:
                case \UV::EBADF:
                case \UV::EINVAL:
                case \UV::ENOTSOCK:
                    foreach ($watchers as $watcher) {
                        $this->disable($watcher);
                    }
                    $this->error(new \Error(
                        \sprintf("UV_%s: %s", \uv_err_name($status), \ucfirst(\uv_strerror($status)))
                    ));
                    return;

                default: // Ignore other (probably) trivial warnings and continuing polling.
                    return;
            }

            foreach ($watchers as $watcher) {
                if (!($watcher->type & $events)) {
                    continue;
                }

                try {
                    $result = ($watcher->callback)($watcher->id, $resource, $watcher->data);

                    if ($result === null) {
                        continue;
                    }

                    if ($result instanceof \Generator) {
                        $result = new Coroutine($result);
                    }

                    if ($result instanceof Promise || $result instanceof ReactPromise) {
                        rethrow($result);
                    }
                } catch (\Throwable $exception) {
                    $this->error($exception);
                }
            }
        };

        $this->timerCallback = function ($event) {
            $watcher = $this->watchers[(int) $event];

            if ($watcher->type & Watcher::DELAY) {
                $this->cancel($watcher->id);
            }

            try {
                $result = ($watcher->callback)($watcher->id, $watcher->data);

                if ($result === null) {
                    return;
                }

                if ($result instanceof \Generator) {
                    $result = new Coroutine($result);
                }

                if ($result instanceof Promise || $result instanceof ReactPromise) {
                    rethrow($result);
                }
            } catch (\Throwable $exception) {
                $this->error($exception);
            }
        };

        $this->signalCallback = function ($event, $signo) {
            $watcher = $this->watchers[(int) $event];

            try {
                $result = ($watcher->callback)($watcher->id, $signo, $watcher->data);

                if ($result === null) {
                    return;
                }

                if ($result instanceof \Generator) {
                    $result = new Coroutine($result);
                }

                if ($result instanceof Promise || $result instanceof ReactPromise) {
                    rethrow($result);
                }
            } catch (\Throwable $exception) {
                $this->error($exception);
            }
        };
    }

    /**
     * {@inheritdoc}
     */
    public function cancel(string $watcherId) {
        parent::cancel($watcherId);

        if (!isset($this->events[$watcherId])) {
            return;
        }

        $event = $this->events[$watcherId];
        $eventId = (int) $event;

        if (empty($this->watchers[$eventId])) {
            if (isset($this->io[$eventId])) {
                unset($this->streams[$this->io[$eventId]], $this->io[$eventId]);
            }
            \uv_close($event);
        }

        unset($this->events[$watcherId]);
    }

    public static function isSupported(): bool {
        return \extension_loaded("uv");
    }

    /**
     * {@inheritdoc}
     */
    public function getHandle() {
        return $this->handle;
    }

    /**
     * {@inheritdoc}
     */
    protected function dispatch(bool $blocking) {
        \uv_run($this->handle, $blocking ? \UV::RUN_ONCE : \UV::RUN_NOWAIT);
    }

    /**
     * {@inheritdoc}
     */
    protected function activate(array $watchers) {
        foreach ($watchers as $watcher) {
            $id = $watcher->id;

            switch ($watcher->type) {
                case Watcher::READABLE:
                case Watcher::WRITABLE:
                    $streamId = (int) $watcher->value;

                    if (isset($this->streams[$streamId])) {
                        $event = $this->streams[$streamId];
                    } elseif (isset($this->events[$id])) {
                        $event = $this->streams[$streamId] = $this->events[$id];
                    } else {
                        $event = $this->streams[$streamId] = \uv_poll_init_socket($this->handle, $watcher->value);
                    }

                    $eventId = (int) $event;
                    $this->events[$id] = $event;
                    $this->watchers[$eventId][$id] = $watcher;
                    $this->io[$eventId] = $streamId;

                    $flags = 0;
                    foreach ($this->watchers[$eventId] as $watcher) {
                        $flags |= $watcher->type;
                    }
                    \uv_poll_start($event, $flags, $this->ioCallback);
                    break;

                case Watcher::DELAY:
                case Watcher::REPEAT:
                    if (isset($this->events[$id])) {
                        $event = $this->events[$id];
                    } else {
                        $event = $this->events[$id] = \uv_timer_init($this->handle);
                    }

                    $this->watchers[(int) $event] = $watcher;

                    \uv_timer_start(
                        $event,
                        $watcher->value,
                        $watcher->type & Watcher::REPEAT ? $watcher->value : 0,
                        $this->timerCallback
                    );
                    break;

                case Watcher::SIGNAL:
                    if (isset($this->events[$id])) {
                        $event = $this->events[$id];
                    } else {
                        $event = $this->events[$id] = \uv_signal_init($this->handle);
                    }

                    $this->watchers[(int) $event] = $watcher;

                    \uv_signal_start($event, $this->signalCallback, $watcher->value);
                    break;

                default:
                    // @codeCoverageIgnoreStart
                    throw new \Error("Unknown watcher type");
                    // @codeCoverageIgnoreEnd
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function deactivate(Watcher $watcher) {
        $id = $watcher->id;

        if (!isset($this->events[$id])) {
            return;
        }

        $event = $this->events[$id];
        $eventId = (int) $event;

        switch ($watcher->type) {
            case Watcher::READABLE:
            case Watcher::WRITABLE:
                unset($this->watchers[$eventId][$id]);

                if (empty($this->watchers[$eventId])) {
                    unset($this->watchers[$eventId]);

                    if (\uv_is_active($event)) {
                        \uv_poll_stop($event);
                    }
                } else {
                    $flags = 0;
                    foreach ($this->watchers[$eventId] as $watcher) {
                        $flags |= $watcher->type;
                    }
                    \uv_poll_start($event, $flags, $this->ioCallback);
                }
                break;

            case Watcher::DELAY:
            case Watcher::REPEAT:
                unset($this->watchers[$eventId]);
                if (\uv_is_active($event)) {
                    \uv_timer_stop($event);
                }
                break;

            case Watcher::SIGNAL:
                unset($this->watchers[$eventId]);
                if (\uv_is_active($event)) {
                    \uv_signal_stop($event);
                }
                break;

            default:
                // @codeCoverageIgnoreStart
                throw new \Error("Unknown watcher type");
                // @codeCoverageIgnoreEnd
        }
    }
}
