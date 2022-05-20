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
    'job' => RabbitmqJob::class,
    'queue' => env('RABBITMQ_QUEUE', 'default'), // default stream
    'streams' => [
        'default' => [
            'queue' => 'default'
        ],
    ],
    'options' => [
        'queue' => [
            'passive' => false, // don't check if a queue with the same name exists
            'durable' => true, // the queue will survive server restarts
            'exclusive' => false, // the queue might be accessed by other channels
            'auto_delete' => false, //the queue will not be deleted once the channel is closed
            'nowait' => false,
        ],
        'exchange' => [
            'type' => 'direct', // fanout / direct / topic / headers
            'passive' => false, // don't check if an exchange with the same name exists
            'durable' => true,  // the exchange will survive server restarts
            'auto_delete' => false, //the exchange will not be deleted once the channel is closed.
        ],
        'consume' => [
            'no_ack' => false, // if set to true, automatic acknowledgement mode will be used
            'auto_reconnect' => true, // will be reconnecting on message consuming fail
        ]
    ],
];

