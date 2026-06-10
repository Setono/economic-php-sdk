<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;
use Rector\Set\ValueObject\LevelSetList;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ]);

    $rectorConfig->sets([
        LevelSetList::UP_TO_PHP_84,
    ]);

    $rectorConfig->skip([
        AddOverrideAttributeToOverriddenMethodsRector::class,
        // Entry-point Response DTOs cannot be `final readonly class` because
        // the endpoint stamps `$raw` on them after Valinor mapping.
        ReadOnlyClassRector::class => [
            __DIR__ . '/src/Response/Product/Product.php',
            __DIR__ . '/src/Response/Order/Order.php',
            __DIR__ . '/src/Response/Invoice/BookedInvoice.php',
            __DIR__ . '/src/Response/Collection/Collection.php',
            __DIR__ . '/src/Response/Self_/Self_.php',
            __DIR__ . '/src/Response/Customer/Customer.php',
        ],
    ]);
};
