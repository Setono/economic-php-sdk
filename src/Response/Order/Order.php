<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Order;

use Setono\Economic\Response\Customer\Customer;
use Setono\Economic\Response\Document\Delivery;
use Setono\Economic\Response\Document\Notes;
use Setono\Economic\Response\Document\Pdf;
use Setono\Economic\Response\Document\Recipient;
use Setono\Economic\Response\Document\References;
use Setono\Economic\Response\Line\Line;
use Setono\Economic\Response\Reference\DeliveryLocation;
use Setono\Economic\Response\Reference\Layout;
use Setono\Economic\Response\Reference\PaymentTerms;
use Setono\Economic\Response\Reference\Project;
use Setono\Economic\Response\Resource;

/**
 * Serves both `orders/drafts` and `orders/sent` — their schemas expose the same fields.
 */
final class Order extends Resource
{
    /**
     * @param list<Line> $lines
     */
    public function __construct(
        public readonly ?int $orderNumber = null,
        public readonly array $lines = [],
        public readonly ?\DateTimeImmutable $date = null,
        public readonly ?string $currency = null,
        public readonly ?float $exchangeRate = null,
        // amounts (server-computed)
        public readonly ?float $netAmount = null,
        public readonly ?float $netAmountInBaseCurrency = null,
        public readonly ?float $grossAmount = null,
        public readonly ?float $grossAmountInBaseCurrency = null,
        public readonly ?float $marginInBaseCurrency = null,
        public readonly ?float $marginPercentage = null,
        public readonly ?float $vatAmount = null,
        public readonly ?float $roundingAmount = null,
        public readonly ?float $costPriceInBaseCurrency = null,
        public readonly ?\DateTimeImmutable $dueDate = null,
        public readonly ?\DateTimeImmutable $lastUpdated = null,
        // references & sub-objects
        public readonly ?PaymentTerms $paymentTerms = null,
        public readonly ?Customer $customer = null,
        public readonly ?Recipient $recipient = null,
        public readonly ?DeliveryLocation $deliveryLocation = null,
        public readonly ?Delivery $delivery = null,
        public readonly ?Notes $notes = null,
        public readonly ?References $references = null,
        public readonly ?Project $project = null,
        public readonly ?Layout $layout = null,
        public readonly ?Pdf $pdf = null,
    ) {
    }
}
