<?php
namespace IvanGrebnev\LaravelRabbitmq\Queue\Connectors;

use Illuminate\Queue\Connectors\ConnectorInterface;
use IvanGrebnev\LaravelRabbitmq\Queue\RabbitmqQueue;

class RabbitmqConnector implements ConnectorInterface
{
    /**
     * @throws \Exception
     */
    public function connect(array $config): \Illuminate\Contracts\Queue\Queue
    {
        return new RabbitmqQueue($config);
    }
}
