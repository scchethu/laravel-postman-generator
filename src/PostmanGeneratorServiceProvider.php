<?php

namespace Scchethu\PostmanGenerator;

use Illuminate\Support\ServiceProvider;
use Scchethu\PostmanGenerator\Commands\GeneratePostmanCommand;

class PostmanGeneratorServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/postman-generator.php', 'postman-generator');
    }

    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../config/postman-generator.php' => config_path('postman-generator.php'),
        ], 'postman-generator-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                GeneratePostmanCommand::class,
            ]);
        }
    }
}
