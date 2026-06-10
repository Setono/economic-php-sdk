<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Reference;

/**
 * Union of the payment-terms shapes across resources: customer references only populate
 * `paymentTermsNumber` + `self`; order/invoice references also carry the summary fields.
 */
final readonly class PaymentTerms
{
    public function __construct(
        public ?int $paymentTermsNumber = null,
        public ?int $daysOfCredit = null,
        public ?string $name = null,
        public ?string $paymentTermsType = null,
        public ?string $self = null,
    ) {
    }
}
