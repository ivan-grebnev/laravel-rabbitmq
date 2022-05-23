<?php

namespace IvanGrebnev\LaravelRabbitmq\Queue\Jobs;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Support\Arr;
use IvanGrebnev\LaravelRabbitmq\Queue\RabbitmqQueue;
use PhpAmqpLib\Message\AMQPMessage;

class RabbitmqJob extends Job implements JobContract
{
    protected RabbitmqQueue $rabbitmq;
    private AMQPMessage $message;

    public function __construct(
        Container $container,
        RabbitmqQueue $rabbitmq,
        AMQPMessage $message,
        string $connectionName,
        string $task
    ) {
        $this->container = $container;
        $this->rabbitmq = $rabbitmq;
        $this->message = $message;
        $this->connectionName = $connectionName;
        $this->queue = $task;
    }

    public function getJobId(): string
    {
        return $this->uuid().'#'.$this->attempts();
    }

    public function getRawBody(): string
    {
        return $this->message->body;
    }

    public function attempts()
    {
        $ttlHeaders = Arr::get($this->headers(), 'x-death', []);

        $retryHeaders = collect($ttlHeaders)
            ->filter(fn($ar) => is_numeric(strpos($ar['queue'], '.retry.')))
            ->first();

        return Arr::get($retryHeaders, 'count', 0) + 1;
    }

    public function delete()
    {
        $action = $this->hasFailed() ? 'nack' : 'ack';
        $this->rabbitmq->$action($this->queue, $this->message->getDeliveryTag());
        parent::delete();
    }

    public function release($delay = 0)
    {
        try {
            $this->rabbitmq->retry(max($delay, 1), $this->message, $this->queue);
        } finally {
            $this->rabbitmq->ack($this->queue, $this->message->getDeliveryTag());
            parent::release($delay);
        }
    }

    /**
     * @param string|null $key
     *
     * @return array|\ArrayAccess|mixed
     */
    public function headers(?string $key = null)
    {
        $headers = optional(Arr::get($this->message->get_properties(), 'application_headers'))->getNativeData() ?? [];
        return Arr::get($headers, $key);
    }
}
