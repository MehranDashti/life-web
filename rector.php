<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;
use Rector\Set\ValueObject\LevelSetList;
use RectorLaravel\Set\LaravelLevelSetList;
use RectorLaravel\Rector\MethodCall\ContainerBindConcreteWithClosureOnlyRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    ->withSkip([
        // Laravel can infer the binding key from the closure's return type, but
        // spelling the interface out keeps the container map greppable — which is
        // the whole point of having one place where adapters are chosen.
        ContainerBindConcreteWithClosureOnlyRector::class,

        __DIR__.'/bootstrap/cache',
    ])
    ->withPhpSets(php83: true)
    ->withSets([
        LevelSetList::UP_TO_PHP_83,
        // …_WITHOUT_ATTRIBUTES on purpose: models declare $fillable/$hidden as
        // properties rather than Laravel 13's #[Fillable]/#[Hidden] attributes,
        // because Larastan's model-property analysis still reads the properties.
        LaravelLevelSetList::UP_TO_LARAVEL_130_WITHOUT_ATTRIBUTES,
        SetList::DEAD_CODE,
        SetList::TYPE_DECLARATION,
        SetList::EARLY_RETURN,
    ])
    ->withImportNames(importShortClasses: false, removeUnusedImports: true);
