Laravel queue RabbitMQ driver
=============================

By default, the Laravel framework doesn't include a RabbitMQ driver for the queue feature. 
This package adds compatibility with this message broker.

You will also be able to exchange messages with other systems that don't support 
Laravel native queue message format.

Installation
------------

Just install this package by Composer:

```shell
composer require ivan-grebnev/laravel-rabbitmq
```

And set environment variables with credentials to connect to RabbitMQ server, for example in ```.env``` file:
```dotenv
RABBITMQ_HOST=some.hostname # or IP-address 
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/
```

Configuration
-------------

Default configuration places in ```src/config/default.php```. 
You can override any config options in your project's ```config/queue.php``` in the **rabbitmq** section, for example: 
```php
...
'rabbitmq' => [
    'tasks' => [
        // parameters of concrete task
        'SomeSend' => [
            'exchange' => 'Some.Send.Exchange',
            'routing_key' => 'Any.Routing.Key',
        ],
        'SomeRecieve' => [
            'queue' => 'Some.Recieve.Queue',
            'job' => \App\Jobs\Exchange\RecieveJob::class,
        ]
    ],
    // here default parameters for all tasks
    'defaults' => [
        'worker_job' => \App\Jobs\Exchange\CustomWorkerJob::class,
        'exchange_type' => 'topic',
        'consume_no_ack' => true,
    ],
],
...
```

For all tasks config takes from "defaults" section, but can be overridden in parameters of concrete task.

All possible settings you can see in source config file with comments [https://github.com/ivan-grebnev/laravel-rabbitmq/blob/master/src/config/default.php](https://github.com/ivan-grebnev/laravel-rabbitmq/blob/master/src/config/default.php).

Tasks
-----

What does it mean **task**? 
The Laravel uses the term "queue" to refer to an application's messaging channel with a queue server 
in either direction. But the RabbitMQ message broker additionally has **exchange** as its entry point, 
and **exchange** can have exactly the same name as **queue**. For this reason, the concept of **task** was introduced 
to separate configuration settings. So the example below will work: 

```php
// config/queue.php
...
'rabbitmq' => [
    'tasks' => [
        'UsersSendTask' => [
            'exchange' => 'Site.Users',
        ],
        'UsersRecieveTask' => [
            'queue' => 'Site.Users',
            'job' => \App\Jobs\Exchange\UsersRecieveJob::class,
        ]
    ],
    'defaults' => [
        'worker_job' => \App\Jobs\Exchange\CustomWorkerJob::class,
    ],
],
...
```

This is necessary when RabbitMQ queues are used not only for the internal needs of the application, 
but for exchanging data with other systems, then we push messages to one exchange or queue, 
and pull our messages from others.

Thus, in the package sources, **task** is like **queue** in Laravel, and **queue** is exactly the **queue** of RabbitMQ.
So to execute the example above:

```php
UsersSendJob::dispatch()->onConnection('rabbitmq')->onQueue('UsersSendTask');
```
and
```shell
artisan queue:work rabbitmq --queue=UsersRecieveTask
```

Custom jobs
-----------
There are three types of Job:
- Sending to exchange / queue job
- Pulling by worker job
- Executing job

...to be continued...
