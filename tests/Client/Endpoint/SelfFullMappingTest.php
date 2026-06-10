<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Setono\Economic\Client\Client;
use Setono\Economic\Response\Self_\AgreementType;
use Setono\Economic\Response\Self_\Application;
use Setono\Economic\Response\Self_\BankInformation;
use Setono\Economic\Response\Self_\Company;
use Setono\Economic\Response\Self_\Language;
use Setono\Economic\Response\Self_\Module;
use Setono\Economic\Response\Self_\Self_;
use Setono\Economic\Response\Self_\Settings;
use Setono\Economic\Response\Self_\User;
use Setono\Economic\TestDouble\ScriptedHttpClient;
use Webmozart\Assert\Assert;

#[CoversClass(SelfEndpoint::class)]
#[CoversClass(Self_::class)]
#[CoversClass(AgreementType::class)]
#[CoversClass(User::class)]
#[CoversClass(Language::class)]
#[CoversClass(Company::class)]
#[CoversClass(BankInformation::class)]
#[CoversClass(Application::class)]
#[CoversClass(Settings::class)]
#[CoversClass(Module::class)]
final class SelfFullMappingTest extends TestCase
{
    #[Test]
    public function every_first_level_self_field_maps_to_a_typed_property(): void
    {
        $body = json_encode([
            'loggedInUserType' => 'api',
            'serverTime' => '2026-06-10T13:24:08',
            'agreementNumber' => 123456,
            'userName' => 'API user',
            'signupDate' => '2015-01-31',
            'companyAffiliation' => 'standard',
            'canSendElectronicInvoice' => true,
            'agreementType' => ['agreementTypeNumber' => 21, 'name' => 'e-conomic komplet'],
            'user' => [
                'agreementNumber' => 123456,
                'email' => 'api@acme.test',
                'language' => ['languageNumber' => 1, 'name' => 'Dansk', 'culture' => 'da-DK', 'self' => 'https://restapi.e-conomic.com/languages/1'],
                'loginId' => 'api-user',
                'name' => 'API User',
            ],
            'company' => [
                'addressLine1' => 'Main Street 1',
                'addressLine2' => '2nd floor',
                'attention' => 'Jane Doe',
                'city' => 'Aarhus',
                'companyIdentificationNumber' => '12345678',
                'country' => 'Denmark',
                'email' => 'info@acme.test',
                'name' => 'Acme',
                'phoneNumber' => '+45 11111111',
                'vatNumber' => 'DK12345678',
                'website' => 'https://acme.test',
                'zip' => '8000',
            ],
            'bankInformation' => [
                'bankAccountNumber' => '1234567890',
                'bankGiroNumber' => '987654',
                'bankName' => 'Danske Bank',
                'bankSortCode' => '3000',
                'pbsCustomerGroupNumber' => '12345',
                'pbsFiSupplierNumber' => '67890',
            ],
            'application' => [
                'appNumber' => 999,
                'name' => 'My Integration',
                'appPublicToken' => 'public-token',
                'created' => '2019-05-01T07:00:00Z',
                'requiredRoles' => [['name' => 'admin']],
                'self' => 'https://restapi.e-conomic.com/self/user/apps/999',
            ],
            'settings' => [
                'baseCurrency' => 'DKK',
                'defaultPaymentTerm' => 'Netto 8 dage',
                'internationalLedger' => 'false',
            ],
            'modules' => [
                ['moduleNumber' => 5, 'name' => 'Inventory', 'self' => 'https://restapi.e-conomic.com/modules/5'],
                ['moduleNumber' => 9, 'name' => 'Projects', 'self' => 'https://restapi.e-conomic.com/modules/9'],
            ],
            'self' => 'https://restapi.e-conomic.com/self',
        ], \JSON_THROW_ON_ERROR);

        $http = new ScriptedHttpClient()
            ->on('https://restapi.e-conomic.com/self', $body)
        ;

        $client = new Client('app', 'agreement', httpClient: $http);
        $self = $client->self()->get();

        // BC fields (absent from the current schema) still map.
        self::assertSame('api', $self->loggedInUserType);
        self::assertSame('2026-06-10T13:24:08', $self->serverTime);

        self::assertSame(123456, $self->agreementNumber);
        self::assertSame('API user', $self->userName);
        self::assertNotNull($self->signupDate);
        self::assertSame('2015-01-31T00:00:00+00:00', $self->signupDate->format('Y-m-d\TH:i:sP'));
        self::assertSame('standard', $self->companyAffiliation);
        self::assertTrue($self->canSendElectronicInvoice);

        self::assertNotNull($self->agreementType);
        self::assertSame(21, $self->agreementType->agreementTypeNumber);
        self::assertSame('e-conomic komplet', $self->agreementType->name);

        self::assertNotNull($self->user);
        self::assertSame(123456, $self->user->agreementNumber);
        self::assertSame('api@acme.test', $self->user->email);
        self::assertSame('api-user', $self->user->loginId);
        self::assertSame('API User', $self->user->name);
        self::assertNotNull($self->user->language);
        self::assertSame(1, $self->user->language->languageNumber);
        self::assertSame('da-DK', $self->user->language->culture);

        self::assertNotNull($self->company);
        self::assertSame('Main Street 1', $self->company->addressLine1);
        self::assertSame('2nd floor', $self->company->addressLine2);
        self::assertSame('Jane Doe', $self->company->attention);
        self::assertSame('Aarhus', $self->company->city);
        self::assertSame('12345678', $self->company->companyIdentificationNumber);
        self::assertSame('Denmark', $self->company->country);
        self::assertSame('info@acme.test', $self->company->email);
        self::assertSame('Acme', $self->company->name);
        self::assertSame('+45 11111111', $self->company->phoneNumber);
        self::assertSame('DK12345678', $self->company->vatNumber);
        self::assertSame('https://acme.test', $self->company->website);
        self::assertSame('8000', $self->company->zip);

        self::assertNotNull($self->bankInformation);
        self::assertSame('1234567890', $self->bankInformation->bankAccountNumber);
        self::assertSame('987654', $self->bankInformation->bankGiroNumber);
        self::assertSame('Danske Bank', $self->bankInformation->bankName);
        self::assertSame('3000', $self->bankInformation->bankSortCode);
        self::assertSame('12345', $self->bankInformation->pbsCustomerGroupNumber);
        self::assertSame('67890', $self->bankInformation->pbsFiSupplierNumber);

        self::assertNotNull($self->application);
        self::assertSame(999, $self->application->appNumber);
        self::assertSame('My Integration', $self->application->name);
        self::assertSame('public-token', $self->application->appPublicToken);
        self::assertNotNull($self->application->created);
        self::assertSame('2019-05-01T07:00:00+00:00', $self->application->created->format('Y-m-d\TH:i:sP'));
        self::assertSame('https://restapi.e-conomic.com/self/user/apps/999', $self->application->self);

        self::assertNotNull($self->settings);
        self::assertSame('DKK', $self->settings->baseCurrency);
        self::assertSame('Netto 8 dage', $self->settings->defaultPaymentTerm);
        self::assertSame('false', $self->settings->internationalLedger);

        self::assertCount(2, $self->modules);
        self::assertContainsOnlyInstancesOf(Module::class, $self->modules);
        self::assertSame(5, $self->modules[0]->moduleNumber);
        self::assertSame('Inventory', $self->modules[0]->name);
        self::assertSame(9, $self->modules[1]->moduleNumber);

        // `application.requiredRoles` and the root `self` link are deliberately untyped.
        $applicationRaw = $self->raw['application'];
        Assert::isArray($applicationRaw);
        self::assertSame([['name' => 'admin']], $applicationRaw['requiredRoles']);
        self::assertSame('https://restapi.e-conomic.com/self', $self->raw['self']);
    }
}
