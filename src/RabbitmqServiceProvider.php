<?php

namespace IvanGrebnev\LaravelRabbitmq;

use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\ServiceProvider;
use IvanGrebnev\LaravelRabbitmq\Queue\Connectors\RabbitmqConnector;

class RabbitmqServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function register()
    {
        if (! ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached())) {
            $config = $this->app->make('config');

            $config->set('queue.connections.rabbitmq', array_replace_recursive(
                require __DIR__.'/config/default.php',
                $config->get('queue.connections.rabbitmq', [])
            ));
        }
    }

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        /** @var $queueManager \Illuminate\Queue\QueueManager */
        $queueManager = $this->app['queue'];
        $queueManager->addConnector('rabbitmq', function() {
            return new RabbitmqConnector();
        });
    }
}
