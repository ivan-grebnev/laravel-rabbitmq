<?php

namespace IvanGrebnev\LaravelRabbitmq\Queue;

use Closure;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Queue\InvalidPayloadException;
use Illuminate\Queue\Queue;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use IvanGrebnev\LaravelRabbitmq\Queue\Jobs\RabbitmqJob;
use PhpAmqpLib\Channel\AbstractChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Exception\AMQPInvalidArgumentException;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class RabbitmqQueue extends Queue implements QueueContract
{
    private AbstractConnection $connection;
    private AbstractChannel $channel;
    private array $config;

    /**
     * @throws \Exception
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->connect();
    }

    private function config(string $key, string $default = '')
    {
        return Arr::get($this->config, $key, Arr::get($this->config, $default));
    }

    private function resolveMap($value, array $map = [])
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->resolveMap($v, $map);
            }
            return $value;
        }

        $newValue = str_replace(
            array_map(fn($key) => '%'.$key.'%', array_keys($map)),
            $map,
            $value
        );

        return is_numeric($newValue) ? (int) $newValue : $newValue;
    }

    public function taskConfig(string $task, string $key, array $map = [])
    {
        $value = $this->config(sprintf('tasks.%s.%s', $task, $key), sprintf('defaults.%s', $key));

        return $this->resolveMap($value, $map);
    }

    /**
     * @throws \Exception
     */
    private function connect()
    {
        /** @var $connectionClass AbstractConnection */
        $connectionClass = $this->config('connection.class');

        /** @var $connection \PhpAmqpLib\Connection\AbstractConnection */
        $this->connection = $connectionClass::create_connection(
            [$this->config('connection.credentials')],
            $this->config('connection.options')
        );

        register_shutdown_function(fn() => $this->disconnect());

        $this->channel = $this->connection->channel();
    }

    private function disconnect(): void
    {
        if (isset($this->channel) && is_a($this->channel, AbstractChannel::class)) {
            try {
                $this->channel->close();
            } catch (\Exception $e) {
            }
        }
        if (isset($this->connection) && is_a($this->connection, AbstractConnection::class)) {
            try {
                $this->connection->close();
            } catch (\Exception $e) {
            }
        }
    }

    public function getChannel(): AbstractChannel
    {
        return $this->channel;
    }

    public function size($task = null): int
    {
        [, $size] = $this->initQueue($task, true);

        return (int) $size;
    }

    public function push($job, $data = '', $task = null)
    {
        $this->pushRaw($this->createMessage($this->createPayload($job, $task, $data)), $task, [], $job);
    }

    /**
     * @param AMQPMessage $message
     * @param string      $queue
     * @param array       $map
     *
     * @return mixed|void
     * @throws \Throwable
     */
    public function pushRaw($message, $task = null, array $map = [], $job = null)
    {
        [$exchange, $queue] = $this->initTask($task, true, $map);

        if (is_object($job) && method_exists($job, 'routingKey')) {
            $routingKey = $job->routingKey();
        } else {
            $routingKey = $this->taskConfig($task, 'routing_key');
        }

        if (is_object($job) && method_exists($job, 'beforePush')) {
            if (false === $job->beforePush($message, Arr::get($this->config, sprintf('tasks.%s', $task)))) {
                return;
            }
        }

        $this->channel->basic_publish(
            $message,
            $queue ? '' : $exchange,
            $queue ?? $routingKey
        );

        if (is_object($job) && method_exists($job, 'afterPush')) {
            $job->afterPush($message, $exchange, $queue, $routingKey);
        }
    }

    /**
     * @throws \Throwable
     */
    public function later($delay, $job, $data = '', $task = null)
    {
        if ($delay <= 0) {
            return $this->push($job, $data, $task);
        }

        [$exchange, $queue] = $this->initTask($task);

        $ttl = $this->secondsUntil($delay) * 1000;

        $this->pushRaw(
            $this->createMessage($this->createPayload($job, $task, $data)),
            'delayed',
            [
                'ttl' => $ttl,
                'expires' => $ttl * 2,
                'queue' => ($exchange ?? $queue).'.delay.'.$ttl,
                'exchange' => $exchange ?? '',
                'routing_key' => $exchange ? $this->taskConfig($task, 'routing_key') : $queue,
            ],
            $job
        );
    }

    public function retry($delay, $message, $task)
    {
        [$queue] = $this->initQueue($task);

        $ttl = $this->secondsUntil($delay) * 1000;

        $this->pushRaw(
            $message,
            'delayed',
            [
                'ttl' => $ttl,
                'expires' => $ttl * 2,
                'queue' => $queue.'.retry.'.$ttl,
                'exchange' => '',
                'routing_key' => $queue,
            ]
        );
    }

    /**
     * @throws \Exception
     */
    public function pop($task = null)
    {
        [$queue] = $this->initQueue($task);

        try {
            if (
                $message = $this->channel->basic_get(
                    $queue,
                    $this->taskConfig($task, 'consume_no_ack')
                )
            ) {
                /** @var $jobClass RabbitmqJob */
                $jobClass = $this->taskConfig($task, 'worker_job');

                return new $jobClass(
                    $this->container,
                    $this,
                    $message,
                    $this->connectionName,
                    $task,
                );
            }
        } catch (AMQPRuntimeException $e) {
            if ($this->taskConfig($task, 'consume_auto_reconnect')) {
                $this->disconnect();
                $this->connect();
                return null;
            }
            throw $e;
        }

        return null;
    }

    protected function createPayload($job, $task, $data = ''): array
    {
        if ($job instanceof Closure) {
            $job = CallQueuedClosure::create($job);
        }

        $body = is_object($job) && method_exists($job, 'body')
            ? $job->body()
            : $this->createPayloadArray($job, $task, $data);

        $properties = [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ];
        if (is_object($job)) {
            if (method_exists($job, 'uuid')) {
                $properties['message_id'] = $job->uuid();
            }
            if (method_exists($job, 'properties')) {
                $properties = array_merge($properties, $job->properties());
            }
        }
        if (!array_key_exists('message_id', $properties)) {
            $properties['message_id'] = Arr::get($body, 'uuid', (string) Str::uuid());
        }

        $headers = [];
        if (is_object($job) && method_exists($job, 'headers')) {
            $headers = array_merge($headers, $job->headers());
        }

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new InvalidPayloadException(
                'Unable to JSON encode payload. Error code: '.json_last_error()
            );
        }

        return [$body, $properties, $headers];
    }

    private function createMessage(array $payload): AMQPMessage
    {
        [$body, $properties, $headers] = $payload;

        $message = new AMQPMessage(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), $properties);
        $message->set('application_headers', new AMQPTable($headers));

        return $message;
    }

    public function ack(string $task, string $deliveryTag)
    {
        if (!$this->taskConfig($task, 'consume_no_ack')) {
            $this->channel->basic_ack($deliveryTag, false);
        }
    }

    public function nack(string $task, string $deliveryTag)
    {
        if (!$this->taskConfig($task, 'consume_no_ack')) {
            $this->channel->basic_nack($deliveryTag);
        }
    }

    private function initTask(string $task, bool $rewriteCache = false, array $map = []): array
    {
        $exchange = $this->initExchange($task);
        [$queue] = $this->initQueue($task, $rewriteCache, $map);

        throw_if(!$exchange && !$queue, AMQPInvalidArgumentException::class, 'Exchange or queue of task "'.$task.'" not set in config');

        return [$exchange, $queue];
    }

    private function initExchange(string $task): ?string
    {
        static $cache = [];

        if (!$exchange = $this->taskConfig($task, 'exchange')) {
            return null;
        }

        if (!array_key_exists($exchange, $cache) || !$cache[$exchange]) {
            $cache[$exchange] = $this->channel->exchange_declare(
                $exchange,
                $this->taskConfig($task, 'exchange_type'),
                $this->taskConfig($task, 'exchange_passive'),
                $this->taskConfig($task, 'exchange_durable'),
                $this->taskConfig($task, 'exchange_auto_delete'),
            );
        }

        return $exchange;
    }

    private function initQueue(string $task, bool $rewriteCache = false, array $map = []): ?array
    {
        static $cache = [];

        if (!$queue = $this->taskConfig($task, 'queue', $map)) {
            return null;
        }

        if (!array_key_exists($queue, $cache) || !$cache[$queue] || $rewriteCache) {
            $cache[$queue] = $this->channel->queue_declare(
                $queue,
                $this->taskConfig($task, 'queue_passive', $map),
                $this->taskConfig($task, 'queue_durable', $map),
                $this->taskConfig($task, 'queue_exclusive', $map),
                $this->taskConfig($task, 'queue_auto_delete', $map),
                $this->taskConfig($task, 'queue_nowait', $map),
                new AMQPTable($this->taskConfig($task, 'queue_arguments', $map))
            );
        }

        return $cache[$queue];
    }
}
