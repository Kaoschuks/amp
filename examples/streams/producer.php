#!/usr/bin/env php
<?php

require __DIR__ . '/../../vendor/autoload.php';

use Amp\Coroutine;
use Amp\Loop;
use Amp\Pause;
use Amp\Producer;
use Amp\Stream;
use Amp\StreamIterator;

Loop::run(function () {
    try {
        $stream = new Producer(function (callable $emit) {
            yield $emit(1);
            yield $emit(new Pause(500, 2));
            yield $emit(3);
            yield $emit(new Pause(300, 4));
            yield $emit(5);
            yield $emit(6);
            yield $emit(new Pause(1000, 7));
            yield $emit(8);
            yield $emit(9);
            yield $emit(new Pause(600, 10));
            return 11;
        });

        while (yield $stream->advance()) {
            printf("Stream emitted %d\n", $stream->getCurrent());
            yield new Pause(100); // Listener consumption takes 100 ms.
        }
    } catch (\Exception $exception) {
        printf("Exception: %s\n", $exception);
    }
});
