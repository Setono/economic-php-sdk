<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint;

use CuyZ\Valinor\MapperBuilder;
use Setono\Economic\Client\ClientInterface;

abstract class Endpoint
{
    public function __construct(
        protected readonly ClientInterface $client,
        protected readonly MapperBuilder $mapperBuilder,
    ) {
    }
}
