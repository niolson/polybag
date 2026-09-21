<?php

namespace App\Filament\Resources\CarrierAccounts\Concerns;

use App\Exceptions\FedexRegistrationMaxRetriesException;
use App\Filament\Support\AddressForm;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Services\AddressReferenceService;
use App\Services\FedexRegistrationService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use LogicException;

trait HasFedexRegistration
{
    public bool $fedexEulaAccepted = false;

    public ?string $fedexAccountAuthToken = null;

    public ?string $fedexFactor2Method = null;

    public ?string $fedexMaskedEmail = null;

    public ?string $fedexMaskedPhone = null;

    /** @var string[] */
    public array $fedexSecureCodeOptions = [];

    public bool $fedexInvoiceAvailable = false;

    /** @var string[] */
    public array $fedexLockedFactor2Methods = [];

    public bool $fedexSupportFallbackActive = false;

    private function fedexCarrierAccount(): CarrierAccount
    {
        $record = $this->getRecord();

        if (! $record instanceof CarrierAccount) {
            throw new LogicException('FedEx registration requires a carrier account record.');
        }

        return $record;
    }

    public function resendFedexPin(): void
    {
        if (! $this->fedexAccountAuthToken || ! $this->fedexFactor2Method) {
            return;
        }

        try {
            app(FedexRegistrationService::class)->sendPin(
                $this->fedexCarrierAccount(),
                $this->fedexAccountAuthToken,
                $this->fedexFactor2Method,
            );
            Notification::make()
                ->success()
                ->title('PIN request accepted by FedEx.')
                ->body('Delivery may take a few minutes. Avoid repeated requests because FedEx limits attempts across all methods.')
                ->send();
        } catch (FedexRegistrationMaxRetriesException $e) {
            $this->handleFedexRegistrationLockout($e);
        } catch (\Throwable $e) {
            $this->notifyFedexRegistrationError($e);
        }
    }

    public function closeFedexRegistrationModal(): void
    {
        $this->unmountAction(false);
    }

    private function resetFedexRegistrationState(): void
    {
        $this->fedexEulaAccepted = false;
        $this->fedexAccountAuthToken = null;
        $this->fedexFactor2Method = null;
        $this->fedexMaskedEmail = null;
        $this->fedexMaskedPhone = null;
        $this->fedexSecureCodeOptions = [];
        $this->fedexInvoiceAvailable = false;
        $this->fedexLockedFactor2Methods = [];
        $this->fedexSupportFallbackActive = false;
    }

    /**
     * @return array<string, string>
     */
    public function getFedexAvailableVerificationOptions(): array
    {
        $options = [];

        foreach ($this->fedexSecureCodeOptions as $code) {
            if (in_array($code, $this->fedexLockedFactor2Methods, strict: true)) {
                continue;
            }

            $options[$code] = match ($code) {
                'SMS' => 'PIN via SMS'.($this->fedexMaskedPhone ? " ({$this->fedexMaskedPhone})" : ''),
                'CALL' => 'PIN via Phone Call'.($this->fedexMaskedPhone ? " ({$this->fedexMaskedPhone})" : ''),
                'EMAIL' => 'PIN via Email'.($this->fedexMaskedEmail ? " ({$this->fedexMaskedEmail})" : ''),
                default => $code,
            };
        }

        if ($this->fedexInvoiceAvailable && ! in_array('INVOICE', $this->fedexLockedFactor2Methods, strict: true)) {
            $options['INVOICE'] = 'Invoice Validation';
        }

        return $options;
    }

    public function hasAvailableFedexFactor2Methods(): bool
    {
        return $this->getFedexAvailableVerificationOptions() !== [];
    }

    /**
     * @param  array{accountAuthToken: string, email: ?string, phoneNumber: ?string, options: array{invoice: bool, secureCode: array<int, string>}}  $result
     */
    private function storeFedexVerificationState(array $result): void
    {
        $this->fedexAccountAuthToken = $result['accountAuthToken'];
        $this->fedexMaskedEmail = $result['email'];
        $this->fedexMaskedPhone = $result['phoneNumber'];
        $this->fedexSecureCodeOptions = $result['options']['secureCode'];
        $this->fedexInvoiceAvailable = $result['options']['invoice'];
        $this->fedexLockedFactor2Methods = [];
        $this->fedexSupportFallbackActive = false;

        $this->refreshMountedFedexAction();
    }

    private function renderFedexWizardSubmitAction(): HtmlString
    {
        if ($this->fedexSupportFallbackActive) {
            return new HtmlString(
                Blade::render('<x-filament::button type="button" wire:click="closeFedexRegistrationModal" color="gray">Close</x-filament::button>')
            );
        }

        return new HtmlString(
            Blade::render('<x-filament::button type="button" wire:click="callMountedAction">Add Account</x-filament::button>')
        );
    }

    private function refreshMountedFedexAction(): void
    {
        if (empty($this->mountedActions ?? [])) {
            return;
        }

        $this->cachedMountedActions = null;

        foreach ($this->cachedSchemas as $schemaName => $schema) {
            if (str($schemaName)->startsWith('mountedActionSchema')) {
                unset($this->cachedSchemas[$schemaName]);
            }
        }

        $this->cacheMountedActions($this->mountedActions);
    }

    private function notifyFedexRegistrationError(\Throwable $exception): void
    {
        Notification::make()->danger()->title('FedEx Error')->body($exception->getMessage())->send();
    }

    private function handleFedexRegistrationLockout(FedexRegistrationMaxRetriesException $exception): void
    {
        $this->fedexLockedFactor2Methods = array_values(array_unique([
            ...$this->fedexLockedFactor2Methods,
            ...$exception->lockedMethods,
        ]));
        $this->fedexSupportFallbackActive = true;

        $mountedActionIndex = array_key_last($this->mountedActions ?? []);

        if ($mountedActionIndex === null) {
            return;
        }

        data_set($this->mountedActions[$mountedActionIndex], 'data.fedex_factor2_method', null);
        data_set($this->mountedActions[$mountedActionIndex], 'data.fedex_pin', null);
        data_set($this->mountedActions[$mountedActionIndex], 'data.fedex_invoice_number', null);
        data_set($this->mountedActions[$mountedActionIndex], 'data.fedex_invoice_date', null);
        data_set($this->mountedActions[$mountedActionIndex], 'data.fedex_invoice_amount', null);
        data_set($this->mountedActions[$mountedActionIndex], 'data.fedex_invoice_currency', 'USD');

        $this->refreshMountedFedexAction();
    }

    private function completeFedexAccountRegistration(string $accountNumber, string $childKey, string $childSecret): void
    {
        $account = $this->fedexCarrierAccount();

        app(FedexRegistrationService::class)->saveChildCredentialsToAccount(
            $childKey,
            $childSecret,
            $account,
            $accountNumber,
        );
    }

    protected function fedexRegisterAction(): Action
    {
        return Action::make('fedex_register')
            ->label(fn (): string => filled($this->record?->secret('child_key')) ? 'Reconnect FedEx Account' : 'Connect FedEx Account')
            ->icon('heroicon-o-link')
            ->color(fn (): string => filled($this->record?->secret('child_key')) ? 'warning' : 'primary')
            ->visible(fn (): bool => $this->record?->carrier?->name === 'FedEx')
            ->modalHeading(fn (): HtmlString => new HtmlString(
                '<span class="flex items-center gap-2"><img src="'.Carrier::logoUrlForName('FedEx').'" alt="FedEx" class="h-8 inline-block">'
                    .(filled($this->record?->secret('child_key')) ? 'Reconnect FedEx Account' : 'Connect FedEx Account')
                    .'</span>'
            ))
            ->modalWidth('7xl')
            ->extraModalWindowAttributes(['style' => 'max-width: 96rem;'])
            ->closeModalByClickingAway(false)
            ->closeModalByEscaping(false)
            ->modalSubmitAction(false)
            ->mountUsing(function (?Schema $schema): void {
                $this->resetFedexRegistrationState();
                $schema?->fill();
            })
            ->modifyWizardUsing(fn (Wizard $wizard): Wizard => $wizard
                ->submitAction($this->renderFedexWizardSubmitAction())
                ->nextAction(fn (Action $action): Action => $action->disabled(! $this->fedexEulaAccepted))
                ->previousAction(
                    fn (Action $action): Action => $action
                        ->hidden($this->fedexSupportFallbackActive && ! $this->hasAvailableFedexFactor2Methods())
                        ->disabled($this->fedexSupportFallbackActive && ! $this->hasAvailableFedexFactor2Methods())
                ))
            ->steps([
                Step::make('Terms of Service')
                    ->description('Review and accept the FedEx EULA')
                    ->schema([
                        Html::make(fn (): HtmlString => new HtmlString(
                            view('filament.pages.settings.fedex-eula')->render()
                        ))->columnSpanFull(),
                        Hidden::make('eula_accepted')->default(false),
                    ])
                    ->afterValidation(function (): void {
                        if (! $this->fedexEulaAccepted) {
                            $this->addError('fedexEulaAccepted', 'You must scroll to the bottom and accept the FedEx EULA to continue.');

                            throw new Halt;
                        }
                    }),

                Step::make('Account Verification')
                    ->description('Enter your FedEx account number and address')
                    ->schema([
                        TextInput::make('fedex_reg_account_number')
                            ->label('FedEx Account Number')
                            ->required()
                            ->length(9)
                            ->numeric(),
                        TextInput::make('fedex_reg_customer_name')
                            ->label('Company / Customer Name')
                            ->required()
                            ->maxLength(50)
                            ->columnSpanFull()
                            ->helperText('Must match the name on your FedEx account.'),
                        Toggle::make('fedex_reg_residential')
                            ->label('FedEx classifies this account address as residential')
                            ->helperText('This must match how FedEx classifies the address on the account. Leave it off for a home-based business unless FedEx identifies the account address as residential.')
                            ->default(false)
                            ->columnSpanFull(),
                        AddressForm::countrySelect('fedex_reg_country', 'fedex_reg_state')
                            ->label('Country')
                            ->columnSpanFull(),
                        TextInput::make('fedex_reg_street1')
                            ->label('Street Address')
                            ->required()
                            ->maxLength(35)
                            ->columnSpanFull(),
                        TextInput::make('fedex_reg_street2')
                            ->label('Street Address Line 2')
                            ->maxLength(35)
                            ->columnSpanFull(),
                        TextInput::make('fedex_reg_city')
                            ->label('City')
                            ->required()
                            ->maxLength(35),
                        Select::make('fedex_reg_state')
                            ->label(fn (Get $get): string => app(AddressReferenceService::class)->getAdministrativeAreaLabel($get('fedex_reg_country')))
                            ->options(fn (Get $get): array => app(AddressReferenceService::class)->getSubdivisionOptions($get('fedex_reg_country')))
                            ->native(true)
                            ->required(fn (Get $get): bool => app(AddressReferenceService::class)->isAdministrativeAreaRequired($get('fedex_reg_country')))
                            ->hidden(fn (Get $get): bool => app(AddressReferenceService::class)->getSubdivisionOptions($get('fedex_reg_country')) === [])
                            ->live(),
                        TextInput::make('fedex_reg_postal_code')
                            ->label('ZIP / Postal Code')
                            ->required()
                            ->maxLength(10),
                    ])
                    ->columns(3)
                    ->afterValidation(function (Get $get): void {
                        try {
                            $result = app(FedexRegistrationService::class)->validateAddress(
                                account: $this->fedexCarrierAccount(),
                                accountNumber: $get('fedex_reg_account_number'),
                                customerName: $get('fedex_reg_customer_name'),
                                residential: (bool) ($get('fedex_reg_residential') ?? false),
                                street1: $get('fedex_reg_street1'),
                                street2: $get('fedex_reg_street2') ?? '',
                                city: $get('fedex_reg_city'),
                                stateOrProvinceCode: $get('fedex_reg_state') ?? '',
                                postalCode: $get('fedex_reg_postal_code'),
                                countryCode: $get('fedex_reg_country'),
                            );
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('FedEx Error')->body($e->getMessage())->send();

                            throw new Halt;
                        }

                        if (! $result['mfaRequired']) {
                            try {
                                $this->completeFedexAccountRegistration(
                                    accountNumber: $get('fedex_reg_account_number'),
                                    childKey: $result['credentials']['child_Key'],
                                    childSecret: $result['credentials']['child_secret'],
                                );
                                Notification::make()->success()->title('FedEx Account added Successfully.')->send();
                                $this->redirect(static::getUrl(['record' => $this->record->id]));

                                throw new Halt;
                            } catch (Halt $exception) {
                                throw $exception;
                            } catch (\Throwable $e) {
                                $this->notifyFedexRegistrationError($e);

                                throw new Halt;
                            }
                        }

                        $this->storeFedexVerificationState($result);
                    }),

                Step::make('Verification Method')
                    ->description('Choose how to verify your identity')
                    ->schema(fn (): array => [
                        Radio::make('fedex_factor2_method')
                            ->label('Verification Method')
                            ->helperText('FedEx limits PIN requests across email, SMS, and phone. Allow a few minutes for delivery before requesting another PIN.')
                            ->options($this->getFedexAvailableVerificationOptions())
                            ->required()
                            ->live()
                            ->columnSpanFull(),
                    ])
                    ->afterValidation(function (Get $get): void {
                        $this->fedexFactor2Method = $get('fedex_factor2_method');
                        $this->fedexSupportFallbackActive = false;
                        $this->refreshMountedFedexAction();

                        if ($this->fedexFactor2Method !== 'INVOICE') {
                            try {
                                app(FedexRegistrationService::class)->sendPin(
                                    $this->fedexCarrierAccount(),
                                    $this->fedexAccountAuthToken,
                                    $this->fedexFactor2Method,
                                );
                            } catch (FedexRegistrationMaxRetriesException $e) {
                                $this->handleFedexRegistrationLockout($e);
                            } catch (\Throwable $e) {
                                $this->notifyFedexRegistrationError($e);

                                throw new Halt;
                            }
                        }
                    }),

                Step::make('Enter Verification')
                    ->description(fn (): string => $this->fedexSupportFallbackActive
                        ? 'Contact customer service'
                        : ($this->fedexFactor2Method === 'INVOICE' ? 'Enter a recent FedEx invoice' : 'Enter the PIN from FedEx'))
                    ->schema(function (): array {
                        if ($this->fedexSupportFallbackActive) {
                            $hasPinLockout = array_intersect(['SMS', 'CALL', 'EMAIL'], $this->fedexLockedFactor2Methods) !== [];
                            $retryMessage = $hasPinLockout
                                ? 'FedEx has temporarily blocked more PIN attempts. The retry limit is shared across email, SMS, and phone. Wait before starting over, or contact FedEx Customer Service for technical support.'
                                : 'FedEx has temporarily blocked more invoice verification attempts. Wait before starting over, or contact FedEx Customer Service for technical support.';
                            $body = $this->hasAvailableFedexFactor2Methods()
                                ? $retryMessage.' You may also go back and choose a different validation method.'
                                : $retryMessage;

                            return [
                                Placeholder::make('fedex_support_fallback')
                                    ->hiddenLabel()
                                    ->content(new HtmlString(
                                        '<div>'
                                            .'<div class="rounded-lg border border-danger-200 bg-danger-50 p-4 text-sm font-medium text-danger-700 dark:border-danger-800 dark:bg-danger-950/40 dark:text-danger-300">'
                                            .$body
                                            .'</div>'
                                            .'</div>'
                                    ))
                                    ->columnSpanFull(),
                            ];
                        }

                        if ($this->fedexFactor2Method === 'INVOICE') {
                            return [
                                TextInput::make('fedex_invoice_number')
                                    ->label('Invoice Number')
                                    ->required()
                                    ->integer()
                                    ->maxLength(9),
                                DatePicker::make('fedex_invoice_date')
                                    ->label('Invoice Date')
                                    ->required()
                                    ->maxDate(now())
                                    ->minDate(now()->subDays(90))
                                    ->helperText('Invoice must be within the last 90 days.'),
                                TextInput::make('fedex_invoice_amount')
                                    ->label('Invoice Amount')
                                    ->required()
                                    ->numeric()
                                    ->minValue(0),
                                Select::make('fedex_invoice_currency')
                                    ->label('Currency')
                                    ->options(['USD' => 'USD', 'CAD' => 'CAD', 'EUR' => 'EUR', 'GBP' => 'GBP'])
                                    ->default('USD')
                                    ->required(),
                            ];
                        }

                        return [
                            TextInput::make('fedex_pin')
                                ->label('6-Digit PIN')
                                ->required()
                                ->length(6)
                                ->numeric()
                                ->columnSpanFull(),
                            Html::make(fn (): HtmlString => new HtmlString(
                                '<button type="button" wire:click="resendFedexPin" class="text-sm text-primary-600 hover:underline dark:text-primary-400">Resend PIN</button>'
                            ))->columnSpanFull(),
                        ];
                    })
                    ->columns(2),
            ])
            ->action(function (array $data): void {
                if ($this->fedexSupportFallbackActive) {
                    return;
                }

                try {
                    if ($this->fedexFactor2Method === 'INVOICE') {
                        $credentials = app(FedexRegistrationService::class)->verifyInvoice(
                            account: $this->fedexCarrierAccount(),
                            accountAuthToken: $this->fedexAccountAuthToken,
                            invoiceNumber: (int) $data['fedex_invoice_number'],
                            invoiceDate: $data['fedex_invoice_date'],
                            invoiceAmount: (float) $data['fedex_invoice_amount'],
                            invoiceCurrency: $data['fedex_invoice_currency'],
                        );
                    } else {
                        $credentials = app(FedexRegistrationService::class)->verifyPin(
                            account: $this->fedexCarrierAccount(),
                            accountAuthToken: $this->fedexAccountAuthToken,
                            pin: $data['fedex_pin'],
                        );
                    }

                    $this->completeFedexAccountRegistration(
                        accountNumber: $data['fedex_reg_account_number'],
                        childKey: $credentials['child_Key'],
                        childSecret: $credentials['child_secret'],
                    );
                } catch (FedexRegistrationMaxRetriesException $e) {
                    $this->handleFedexRegistrationLockout($e);

                    throw new Halt;
                } catch (\Throwable $e) {
                    $this->notifyFedexRegistrationError($e);

                    throw new Halt;
                }

                Notification::make()->success()->title('FedEx Account added Successfully.')->send();
                $this->redirect(static::getUrl(['record' => $this->record->id]));
            });
    }

    protected function fedexDisconnectAction(): Action
    {
        return Action::make('fedex_disconnect')
            ->label('Disconnect FedEx')
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->visible(fn (): bool => $this->record?->carrier?->name === 'FedEx' && filled($this->record?->secret('child_key')))
            ->requiresConfirmation()
            ->modalHeading('Disconnect FedEx Account')
            ->modalDescription('This will remove your FedEx child credentials. You can reconnect anytime.')
            ->action(function (): void {
                $childKey = $this->record->secret('child_key');
                $childEnv = $this->record->credential('child_env') ?? 'production';

                if ($childKey) {
                    Cache::forget('fedex_authenticator_child_'.$childEnv.'_'.hash('sha256', $childKey));
                }

                $secrets = $this->record->secret_credentials ?? [];
                unset($secrets['child_key'], $secrets['child_secret']);
                $this->record->secret_credentials = $secrets;

                $credentials = $this->record->credentials ?? [];
                unset($credentials['child_env']);
                $this->record->credentials = $credentials;

                $this->record->save();

                Notification::make()->success()->title('FedEx account disconnected.')->send();
                $this->redirect(static::getUrl(['record' => $this->record->id]));
            });
    }
}
