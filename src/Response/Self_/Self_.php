<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Self_;

use Setono\Economic\Response\Resource;

/**
 * Represents `GET /self` — the agreement and user the current credentials are bound to.
 *
 * Class name uses the trailing-underscore PHP convention because `Self` is a reserved word.
 *
 * `loggedInUserType` and `serverTime` are absent from the current schema but kept for BC
 * (`serverTime` deliberately stays a string for the same reason).
 */
final class Self_ extends Resource
{
    /**
     * @param list<Module> $modules
     */
    public function __construct(
        public readonly ?string $loggedInUserType = null,
        public readonly ?string $serverTime = null,
        public readonly ?int $agreementNumber = null,
        public readonly ?string $userName = null,
        public readonly ?\DateTimeImmutable $signupDate = null,
        public readonly ?string $companyAffiliation = null,
        public readonly ?bool $canSendElectronicInvoice = null,
        public readonly ?AgreementType $agreementType = null,
        public readonly ?User $user = null,
        public readonly ?Company $company = null,
        public readonly ?BankInformation $bankInformation = null,
        public readonly ?Application $application = null,
        public readonly ?Settings $settings = null,
        public readonly array $modules = [],
    ) {
    }
}
