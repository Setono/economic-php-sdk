<?php

declare(strict_types=1);

namespace Setono\Economic\Request\Order;

/**
 * The recipient's NemHandel identification type. Per the e-conomic schema, allowed
 * values are `ean`, `corporateIdentificationNumber`, `pNumber`, and `peppol`, or
 * absent. Modelled as a PHP backed enum so the SDK refuses invalid values at the
 * type system level.
 */
enum NemHandelType: string
{
    case Ean = 'ean';
    case CorporateIdentificationNumber = 'corporateIdentificationNumber';
    case PNumber = 'pNumber';
    case Peppol = 'peppol';
}
