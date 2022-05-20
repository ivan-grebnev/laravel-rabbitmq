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

    /**
     * @throws \Exception
     */
    private function connect()
    {
        /** @var $connectionClass AbstractConnection */
        $connectionClass = $this->config('connection.class');

        register_shutdown_function(fn() => $this->disconnect());

        /** @var $connection \PhpAmqpLib\Connection\AbstractConnection */
        $this->connection = $connectionClass::create_connection(
            [$this->config('connection.credentials')],
            $this->config('connection.options')
        );

        $this->channel = $this->connection->channel();
    }

    private function disconnect(): void
    {
        if (is_a($this->channel, AbstractChannel::class)) {
            $this->channel->close();
        }
        if (is_a($this->connection, AbstractConnection::class)) {
            try {
                $this->connection->close();
            } catch (\Exception $e) {
            }
        }
    }

    /**
     * @throws \Exception
     */
    private function reconnect()
    {
        $this->disconnect();
        $this->connect();
    }

    public function getChannel(): AbstractChannel
    {
        return $this->channel;
    }

    public function size($queue = null): int
    {
        [, $size] = $this->initQueue($queue, true);

        return (int) $size;
    }

    public function push($job, $data = '', $queue = null)
    {
        $this->pushRaw($this->createPayload($job, $queue, $data), $queue);
    }

    /**
     * @param array  $payload
     * @param string $queue
     * @param array  $options
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $exchange = $this->initExchange(($stream = $queue));

        $this->publish($payload, $exchange, $this->config('streams.'.$stream.'.routing_key'));
    }

    public function retry($delay, $message, $stream)
    {
        $ttl = $this->secondsUntil($delay) * 1000;

        [$queue] = $this->initQueue($stream);

        $delayQueue = $queue.'.ttl.'.$ttl;

        $this->channel->queue_declare(
            $delayQueue,
            false,
            true,
            false,
            false,
            false,
            new AMQPTable([
                'x-dead-letter-exchange' => '',
                'x-dead-letter-routing-key' => $queue,
                'x-message-ttl' => $ttl,
                'x-expires' => $ttl * 2,
            ])
        );

        $this->channel->basic_publish($message, null, $delayQueue);
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        $ttl = $this->secondsUntil($delay) * 1000;
        $payload = $this->createPayload($job, $queue, $data);

        if ($ttl <= 0) {
            return $this->pushRaw($payload, $queue);
        }

        $exchange = $this->initExchange(($stream = $queue));

        $delayQueue = $exchange.'.ttl.'.$ttl;

        $this->channel->queue_declare(
            $delayQueue,
            false,
            true,
            false,
            false,
            false,
            new AMQPTable([
                'x-dead-letter-exchange' => $exchange,
                'x-dead-letter-routing-key' => $this->config('streams.'.$stream.'.routing_key'),
                'x-message-ttl' => $ttl,
                'x-expires' => $ttl * 2,
            ])
        );

        $this->publish($payload, null, $delayQueue);
    }

    /**
     * @throws \Exception
     */
    public function pop($queue = null)
    {
        [$queue] = $this->initQueue(($stream = $queue));

        try {
            if (
                $message = $this->channel->basic_get(
                    $queue,
                    $this->config('streams.'.$stream.'.no_ack', 'options.consume.no_ack')
                )
            ) {
                /** @var $jobClass RabbitmqJob */
                $jobClass = $this->config('streams.'.$stream.'.job', 'job');

                return new $jobClass(
                    $this->container,
                    $this,
                    $message,
                    $this->connectionName,
                    $stream,
                );
            }
        } catch (AMQPRuntimeException $e) {
            if ($this->config('streams.'.$stream.'.auto_reconnect', 'options.consume.auto_reconnect')) {
                $this->reconnect();
                return null;
            }
            throw $e;
        }

        return null;
    }

    protected function createPayload($job, $queue, $data = ''): array
    {
        if ($job instanceof Closure) {
            $job = CallQueuedClosure::create($job);
        }

        $body = is_object($job) && method_exists($job, 'getBody')
            ? $job->getBody()
            : $this->createPayloadArray($job, $queue, $data);

        $properties = [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ];
        if (is_object($job)) {
            if (method_exists($job, 'getJobId')) {
                $properties['message_id'] = $job->getJobId();
            }
            if (method_exists($job, 'getProperties')) {
                $properties = array_merge($properties, $job->getProperties());
            }
        }
        if (!array_key_exists('message_id', $properties)) {
            $properties['message_id'] = Arr::get($body, 'uuid', Str::uuid());
        }

        $headers = [];
        if (is_object($job) && method_exists($job, 'getHeaders')) {
            $headers = array_merge($headers, $job->getHeaders());
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

    private function publish(array $payload, ?string $exchange = '', string $recipient = ''): void
    {
        $this->channel->basic_publish($this->createMessage($payload), $exchange, $recipient);
    }

    public function ack(string $stream, string $deliveryTag)
    {
        if (!$this->config('streams.'.$stream.'.no_ack', 'options.consume.no_ack')) {
            $this->channel->basic_ack($deliveryTag, false);
        }
    }

    public function nack(string $stream, string $deliveryTag)
    {
        if (!$this->config('streams.'.$stream.'.no_ack', 'options.consume.no_ack')) {
            $this->channel->basic_nack($deliveryTag);
        }
    }

    private function initQueue(string $stream, bool $rewriteCache = false)
    {
        static $cache = [];

        if (!$queue = $this->config('streams.'.$stream.'.queue')) {
            throw new AMQPInvalidArgumentException('Queue of stream "'.$stream.'" not set in config');
        }

        if (!array_key_exists($queue, $cache) || !$cache[$queue] || $rewriteCache) {
            $cache[$queue] = $this->channel->queue_declare(
                $queue,
                $this->config('streams.'.$stream.'.passive', 'options.queue.passive'),
                $this->config('streams.'.$stream.'.durable', 'options.queue.durable'),
                $this->config('streams.'.$stream.'.exclusive', 'options.queue.exclusive'),
                $this->config('streams.'.$stream.'.auto_delete', 'options.queue.auto_delete'),
                $this->config('streams.'.$stream.'.nowait', 'options.queue.nowait'),
            );
        }

        return $cache[$queue];
    }

    private function initExchange(string $stream)
    {
        static $cache = [];

        if (!$exchange = $this->config('streams.'.$stream.'.exchange')) {
            throw new AMQPInvalidArgumentException('Exchange of stream "'.$stream.'" not set in config');
        }

        $exchangeType = $this->config('streams.'.$stream.'.exchange_type', 'options.exchange.type');

        if (!in_array($exchangeType, ['fanout', 'direct', 'topic'])) {
            throw new AMQPInvalidArgumentException(
                'Invalid exchange type. Available types: fanout, direct, topic'
            );
        }

        if (!array_key_exists($exchange, $cache) || !$cache[$exchange]) {
            $cache[$exchange] = $this->channel->exchange_declare(
                $exchange,
                $exchangeType,
                $this->config('streams.'.$stream.'.passive', 'options.exchange.passive'),
                $this->config('streams.'.$stream.'.durable', 'options.exchange.durable'),
                $this->config('streams.'.$stream.'.auto_delete', 'options.exchange.auto_delete'),
            );
        }

        return $exchange;
    }
}
