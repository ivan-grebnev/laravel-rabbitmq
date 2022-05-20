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
    private RabbitmqQueue $rabbitmq;
    private AMQPMessage $message;

    public function __construct(
        Container $container,
        RabbitmqQueue $rabbitmq,
        AMQPMessage $message,
        string $connectionName,
        string $stream
    ) {
        $this->container = $container;
        $this->rabbitmq = $rabbitmq;
        $this->message = $message;
        $this->connectionName = $connectionName;
        $this->queue = $stream;
    }

    public function getJobId(): string
    {
        return $this->payload()['uuid'].'|'.$this->attempts();
    }

    public function getRawBody(): string
    {
        return $this->message->body;
    }

    public function attempts()
    {
        return Arr::get($this->headers(), 'x-death.0.count', 0) + 1;
    }

    public function fire()
    {
        parent::fire();

        $this->delete();
    }

    public function delete()
    {
        if ($this->hasFailed()) {
            $this->rabbitmq->nack($this->queue, $this->message->getDeliveryTag());
        } else {
            $this->rabbitmq->ack($this->queue, $this->message->getDeliveryTag());
        }

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

    public function headers(): array
    {
        return optional(Arr::get($this->message->get_properties(), 'application_headers'))->getNativeData() ?? [];
    }
}
