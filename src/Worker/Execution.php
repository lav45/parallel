<?php

namespace Amp\Parallel\Worker;

use Amp\Future;
use Amp\Sync\Channel;

/**
 * @template TResult
 * @template TReceive
 * @template TSend
 * @template TCache
 */
final class Execution
{
    /**
     * @param Task<TResult, TReceive, TSend, TCache> $task
     * @param Channel<TSend, TReceive> $channel
     * @param Future<TResult> $result
     */
    public function __construct(
        private Task $task,
        private Channel $channel,
        private Future $result,
    ) {
    }

    /**
     * @return Task<TResult, TReceive, TSend>
     */
    public function getTask(): Task
    {
        return $this->task;
    }

    /**
     * @return Channel<TSend, TReceive>
     */
    public function getChannel(): Channel
    {
        return $this->channel;
    }

    /**
     * @return Future<TResult>
     */
    public function getResult(): Future
    {
        return $this->result;
    }
}