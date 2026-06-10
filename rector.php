<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
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
            // Request `Payload` DTOs are deliberately mutable: the read-modify-write flow for
            // full-replace PUT updates is "prefill via fromResponse() → assign fields → update()".
            __DIR__ . '/src/Request/Customer',
            __DIR__ . '/src/Request/Order',
        ],
        ReadOnlyPropertyRector::class => [
            // Same rationale — promoted properties on request DTOs must stay writable.
            __DIR__ . '/src/Request/Customer',
            __DIR__ . '/src/Request/Order',
        ],
    ]);
};
