<?php

use IvanGrebnev\LaravelRabbitmq\Queue\Jobs\RabbitmqJob;
use PhpAmqpLib\Connection\AMQPStreamConnection;

return [
    'driver' => 'rabbitmq',
    'connection' => [
        'class' => AMQPStreamConnection::class,
        'credentials' => [
            'host' => env('RABBITMQ_HOST', '127.0.0.1'),
            'port' => env('RABBITMQ_PORT', 5672),
            'user' => env('RABBITMQ_USER', 'guest'),
            'password' => env('RABBITMQ_PASSWORD', 'guest'),
            'vhost' => env('RABBITMQ_VHOST', '/'),
        ],
        'options' => []
    ],
    'tasks' => [
        'default' => [
            'queue' => 'default'
        ],
        'delayed' => [
            'queue' => '%queue%',
            'queue_arguments' => [
                'x-dead-letter-exchange' => '%exchange%',
                'x-dead-letter-routing-key' => '%routing_key%',
                'x-message-ttl' => '%ttl%',
                'x-expires' => '%expires%',
            ]
        ]
    ],
    'defaults' => [
        'job' => RabbitmqJob::class,
        'queue_passive' => false, // don't check if a queue with the same name exists
        'queue_durable' => true, // the queue will survive server restarts
        'queue_exclusive' => false, // the queue might be accessed by other channels
        'queue_auto_delete' => false, //the queue will not be deleted once the channel is closed
        'queue_nowait' => false,
        'queue_arguments' => [],
        'exchange_type' => 'direct', // fanout / direct / topic / headers
        'exchange_passive' => false, // don't check if an exchange with the same name exists
        'exchange_durable' => true,  // the exchange will survive server restarts
        'exchange_auto_delete' => false, //the exchange will not be deleted once the channel is closed.
        'consume_no_ack' => false, // if set to true, automatic acknowledgement mode will be used
        'consume_auto_reconnect' => true, // will be reconnecting on message consuming fail
    ],
];

