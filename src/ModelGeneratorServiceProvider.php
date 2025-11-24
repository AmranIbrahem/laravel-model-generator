<?php

namespace AmranIbrahem\ModelGenerator;

use Illuminate\Support\ServiceProvider;
use AmranIbrahem\ModelGenerator\Commands\GenerateModelsCommand;

class ModelGeneratorServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->commands([
            GenerateModelsCommand::class,
        ]);
    }

    public function boot()
    {

    }
}
