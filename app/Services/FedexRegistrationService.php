<?php

namespace App\Services;

use App\Exceptions\FedexRegistrationMaxRetriesException;
use App\Http\Integrations\Fedex\FedexConnector;
use App\Http\Integrations\Fedex\FedexRegistrationProxyConnector;
use App\Http\Integrations\Fedex\Requests\Registration\SendPin;
use App\Http\Integrations\Fedex\Requests\Registration\ValidateAddress;
use App\Http\Integrations\Fedex\Requests\Registration\VerifyInvoice;
use App\Http\Integrations\Fedex\Requests\Registration\VerifyPin;
use App\Models\CarrierAccount;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Request;
use Saloon\Http\Response;

class FedexRegistrationService
{
    /**
     * Error codes that indicate MFA lockout (max retries reached).
     */
    private const MAX_RETRIES_CODES = [
        'PINGENERATION.MAXRETRY.EXCEEDED',
        'PINVALIDATION.MAXRETRY.EXCEEDED',
        'INVOICEVALIDATION.MAXRETRY.EXCEEDED',
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly FedexMfaAuditService $audit,
    ) {}

    /**
     * Returns the proxy connector when a broker URL is configured,
     * otherwise falls back to the direct FedEx authenticated connector.
     */
    private function getConnector(CarrierAccount $account): FedexConnector|FedexRegistrationProxyConnector
    {
        if (filled(config('services.oauth.broker_url'))) {
            return new FedexRegistrationProxyConnector;
        }

        return FedexConnector::getAuthenticatedConnector($account);
    }

    /**
     * Validate account number and address (Factor 1).
     *
     * Returns an array with:
     *   - 'accountAuthToken': JWT to pass to subsequent requests
     *   - 'mfaRequired': bool
     *   - 'email': masked email (if available)
     *   - 'phoneNumber': masked phone (if available)
     *   - 'options': ['invoice' => bool, 'secureCode' => string[]]
     *   - 'credentials': child_Key + child_secret if MFA bypassed (null otherwise)
     *
     * @return array{accountAuthToken: string, mfaRequired: bool, email: ?string, phoneNumber: ?string, options: array, credentials: ?array}
     */
    public function validateAddress(
        CarrierAccount $account,
        string $accountNumber,
        string $customerName,
        bool $residential,
        string $street1,
        string $street2,
        string $city,
        string $stateOrProvinceCode,
        string $postalCode,
        string $countryCode,
    ): array {
        $connector = $this->getConnector($account);
        $apiRequest = new ValidateAddress(
            accountNumber: $accountNumber,
            customerName: $customerName,
            residential: $residential,
            street1: $street1,
            street2: $street2,
            city: $city,
            stateOrProvinceCode: $stateOrProvinceCode,
            postalCode: $postalCode,
            countryCode: $countryCode,
        );
        try {
            $response = $connector->send($apiRequest);
        } catch (RequestException $exception) {
            $response = $exception->getResponse();
        }
        $this->recordExchange('address-validation', $apiRequest, $response);

        if (! $response->successful()) {
            $this->throwFromResponse($response->json('errors.0.code'), $response->json('errors.0.message'));
        }

        $output = $response->json('output');

        // MFA bypassed — credentials returned immediately
        if (! empty($output['credentials'])) {
            return [
                'accountAuthToken' => null,
                'mfaRequired' => false,
                'email' => null,
                'phoneNumber' => null,
                'options' => [],
                'credentials' => $output['credentials'],
            ];
        }

        $mfa = $output['mfaOptions'][0] ?? null;

        if (! $mfa) {
            throw new RuntimeException('Unexpected response from FedEx address validation.');
        }

        return [
            'accountAuthToken' => $mfa['accountAuthToken'],
            'mfaRequired' => true,
            'email' => $mfa['email'] ?? null,
            'phoneNumber' => $mfa['phoneNumber'] ?? null,
            'options' => [
                'invoice' => ! empty($mfa['options']['invoice']),
                'secureCode' => $mfa['options']['secureCode'] ?? [],
            ],
        ];
    }

    /**
     * Send a PIN to the customer via their chosen delivery method (SMS, CALL, or EMAIL).
     */
    public function sendPin(CarrierAccount $account, string $accountAuthToken, string $option): void
    {
        $connector = $this->getConnector($account);
        $apiRequest = new SendPin($accountAuthToken, $option);

        try {
            $response = $connector->send($apiRequest);
        } catch (RequestException $exception) {
            $response = $exception->getResponse();
        }
        $this->recordExchange('send-pin', $apiRequest, $response);

        if (! $response->successful()) {
            $this->throwFromResponse($response->json('errors.0.code'), $response->json('errors.0.message'));
        }
    }

    /**
     * Verify the 6-digit PIN and return child credentials on success.
     *
     * @return array{child_Key: string, child_secret: string}
     */
    public function verifyPin(CarrierAccount $account, string $accountAuthToken, string $pin): array
    {
        $connector = $this->getConnector($account);
        $apiRequest = new VerifyPin($accountAuthToken, $pin);

        try {
            $response = $connector->send($apiRequest);
        } catch (RequestException $exception) {
            $response = $exception->getResponse();
        }
        $this->recordExchange('verify-pin', $apiRequest, $response);

        if (! $response->successful()) {
            $this->throwFromResponse($response->json('errors.0.code'), $response->json('errors.0.message'));
        }

        return $this->extractCredentials($response);
    }

    /**
     * Verify invoice details and return child credentials on success.
     *
     * @return array{child_Key: string, child_secret: string}
     */
    public function verifyInvoice(
        CarrierAccount $account,
        string $accountAuthToken,
        int $invoiceNumber,
        string $invoiceDate,
        float $invoiceAmount,
        string $invoiceCurrency,
    ): array {
        $connector = $this->getConnector($account);
        $apiRequest = new VerifyInvoice(
            accountAuthToken: $accountAuthToken,
            invoiceNumber: $invoiceNumber,
            invoiceDate: $invoiceDate,
            invoiceAmount: $invoiceAmount,
            invoiceCurrency: $invoiceCurrency,
        );

        try {
            $response = $connector->send($apiRequest);
        } catch (RequestException $exception) {
            $response = $exception->getResponse();
        }
        $this->recordExchange('verify-invoice', $apiRequest, $response);

        if (! $response->successful()) {
            $this->throwFromResponse($response->json('errors.0.code'), $response->json('errors.0.message'));
        }

        return $this->extractCredentials($response);
    }

    /**
     * Store child credentials on a specific CarrierAccount.
     */
    public function saveChildCredentialsToAccount(
        string $childKey,
        string $childSecret,
        CarrierAccount $account,
        ?string $accountNumber = null,
    ): void {
        $oldChildKey = $account->secret('child_key');
        $oldEnv = $account->credential('child_env') ?? 'production';

        if ($oldChildKey) {
            $this->clearFedexAuthenticatorCaches($oldChildKey, $oldEnv);
        }

        $isSandbox = (bool) $this->settings->get('sandbox_mode', false);
        $env = $isSandbox ? 'sandbox' : 'production';

        $account->mergeCredential('child_env', $env);
        if (filled($accountNumber)) {
            $account->mergeCredential($env.'_account_number', $accountNumber);
        }
        $account->mergeSecret('child_key', $childKey);
        $account->mergeSecret('child_secret', $childSecret);
        $account->save();

        $this->clearFedexAuthenticatorCaches($childKey, $env);
    }

    private function clearFedexAuthenticatorCaches(?string $childKey = null, string $childEnv = 'production'): void
    {
        Cache::forget('fedex_authenticator');
        Cache::forget('fedex_authenticator_sandbox');

        if (blank($childKey)) {
            return;
        }

        Cache::forget('fedex_authenticator_child_'.$childEnv.'_'.hash('sha256', $childKey));
    }

    /**
     * Extract child credentials from a successful verify response.
     * Tries output.credentials first, then output directly, to handle
     * variations between sandbox and production response shapes.
     *
     * @return array{child_Key: string, child_secret: string}
     */
    private function extractCredentials(Response $response): array
    {
        $credentials = $response->json('output.credentials')
            ?? $response->json('output');

        if (! is_array($credentials) || empty($credentials)) {
            throw new RuntimeException('Unexpected credentials response: '.$response->body());
        }

        return $credentials;
    }

    /**
     * Throw the appropriate exception based on the FedEx error code.
     */
    private function throwFromResponse(?string $code, ?string $message): void
    {
        if ($code && in_array($code, self::MAX_RETRIES_CODES, strict: true)) {
            throw new FedexRegistrationMaxRetriesException(
                fedexCode: $code,
                lockedMethods: match ($code) {
                    'PINGENERATION.MAXRETRY.EXCEEDED' => ['SMS', 'CALL', 'EMAIL'],
                    'PINVALIDATION.MAXRETRY.EXCEEDED' => ['SMS', 'CALL', 'EMAIL'],
                    'INVOICEVALIDATION.MAXRETRY.EXCEEDED' => ['INVOICE'],
                    default => [],
                },
            );
        }

        $actionableMessage = match ($code) {
            'USER.ACCOUNT.NOTFOUND' => 'FedEx could not match the account number, customer name, address, and residential classification. Check that these values match the FedEx account record. For a home-based business, leave Residential off unless FedEx classifies the account address as residential.',
            'PIN.ISSUE.FAILED' => 'FedEx could not send a PIN using that method. Try another available method, but avoid repeated requests because FedEx limits PIN attempts across email, SMS, and phone.',
            default => $message ?? 'FedEx registration request failed.',
        };

        throw new RuntimeException($actionableMessage);
    }

    private function recordExchange(string $step, Request $request, Response $response): void
    {
        $this->audit->recordExchange(
            $step,
            [
                'uri' => $request->resolveEndpoint(),
                'headers' => $request->headers()->all(),
                'body' => $request->body()->all(),
            ],
            [
                'status' => $response->status(),
                'body' => $response->json() ?? ['body' => $response->body()],
            ],
        );
    }
}
