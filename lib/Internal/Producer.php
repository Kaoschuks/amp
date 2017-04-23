<?php

namespace Amp\Internal;

use Amp\Coroutine;
use Amp\Deferred;
use Amp\Loop;
use Amp\Promise;
use Amp\Success;
use React\Promise\PromiseInterface as ReactPromise;

/**
 * Trait used by Stream implementations. Do not use this trait in your code, instead compose your class from one of
 * the available classes implementing \Amp\Stream.
 * Note that it is the responsibility of the user of this trait to ensure that listeners have a chance to listen first
 * before emitting values.
 *
 * @internal
 */
trait Producer {
    use Placeholder {
        resolve as complete;
    }

    /** @var callable[] */
    private $listeners = [];

    /**
     * @param callable $onEmit
     */
    public function onEmit(callable $onEmit) {
        if ($this->resolved) {
            return;
        }

        $this->listeners[] = $onEmit;
    }

    /**
     * Emits a value from the stream. The returned promise is resolved with the emitted value once all listeners
     * have been invoked.
     *
     * @param mixed $value
     *
     * @return \Amp\Promise
     *
     * @throws \Error If the stream has resolved.
     */
    private function emit($value): Promise {
        if ($this->resolved) {
            throw new \Error("Streams cannot emit values after calling resolve");
        }

        if ($value instanceof ReactPromise) {
            $value = Promise\adapt($value);
        }

        if ($value instanceof Promise) {
            $deferred = new Deferred;
            $value->onResolve(function ($e, $v) use ($deferred) {
                if ($this->resolved) {
                    $deferred->fail(
                        new \Error("The stream was resolved before the promise result could be emitted")
                    );
                    return;
                }

                if ($e) {
                    $this->fail($e);
                    $deferred->fail($e);
                    return;
                }

                $deferred->resolve($this->emit($v));
            });

            return $deferred->promise();
        }

        $promises = [];

        foreach ($this->listeners as $onEmit) {
            try {
                $result = $onEmit($value);
                if ($result === null) {
                    continue;
                }
                if ($result instanceof \Generator) {
                    $result = new Coroutine($result);
                } elseif ($result instanceof ReactPromise) {
                    $result = Promise\adapt($result);
                }
                if ($result instanceof Promise) {
                    $promises[] = $result;
                }
            } catch (\Throwable $e) {
                Loop::defer(function () use ($e) {
                    throw $e;
                });
            }
        }

        if (!$promises) {
            return new Success($value);
        }

        $deferred = new Deferred;
        $count = \count($promises);
        $f = static function ($e) use ($deferred, $value, &$count) {
            if ($e) {
                Loop::defer(function () use ($e) {
                    throw $e;
                });
            }
            if (!--$count) {
                $deferred->resolve($value);
            }
        };

        foreach ($promises as $promise) {
            $promise->onResolve($f);
        }

        return $deferred->promise();
    }

    /**
     * Resolves the stream with the given value.
     *
     * @param mixed $value
     *
     * @throws \Error If the stream has already been resolved.
     */
    private function resolve($value = null) {
        $this->complete($value);
        $this->listeners = [];
    }
}
