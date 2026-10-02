<?php

namespace App\Filament\Support;

use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageLabels\LabelVoidResult;
use App\DataTransferObjects\PackageShipping\PackageShippingResult;
use App\Enums\PostageSource;
use App\Enums\Role;
use App\Exceptions\PurchaseNotResolvableException;
use App\Models\Location;
use App\Models\Package;
use App\Models\ShippingOffer;
use App\Services\PostageSources\OfferStore;
use App\Services\PostageSources\UnresolvedPurchaseResolver;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Illuminate\Support\Collection;

/**
 * The package page's way out of a purchase nothing accounts for —
 * `postage-source-split/16`.
 *
 * A notice explains what happened, then offers two steps in order: ask the
 * source again, which can bring the label back with its image; and, when the
 * source cannot say, record what a person found in the account themselves.
 */
class ResolveUnaccountedPurchaseAction
{
    /**
     * Keep the label: the package ships on it.
     */
    public const LABEL_EXISTS = 'label_exists';

    public const VOID_LABEL = 'void_label';

    public const ALREADY_VOIDED = 'already_voided';

    public const NOTHING_BOUGHT = 'nothing_bought';

    public const CALLOUT_KEY = 'unresolved_purchase_notice';

    /**
     * The notice at the top of the package page, with both steps as its
     * actions. Shown to anyone; only a manager can act on it.
     */
    public static function callout(): Callout
    {
        return Callout::make('Unfinished label purchase')
            ->key(self::CALLOUT_KEY)
            ->danger()
            ->columnSpanFull()
            ->visible(fn (Package $record): bool => self::offersFor($record)->isNotEmpty())
            ->description(fn (Package $record): string => self::explain($record))
            ->footerActions([self::askAgain(), self::recordWhatWasFound()]);
    }

    /**
     * The purchases on this package a person has to settle, oldest first.
     *
     * @return Collection<int, ShippingOffer>
     */
    public static function offersFor(Package $package): Collection
    {
        return app(UnresolvedPurchaseResolver::class)
            ->needingAPerson()
            ->where('package_id', $package->id)
            ->orderBy('consumed_at')
            ->get();
    }

    /**
     * Step one: ask the source now, rather than on the next attempt to buy.
     */
    public static function askAgain(): Action
    {
        return Action::make('askAgain')
            ->label(fn (Package $record): string => 'Ask '.self::sourceName(self::offersFor($record)->first()).' again')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->visible(fn (Package $record): bool => self::isManager()
                && ($offer = self::offersFor($record)->first()) !== null
                && self::unrecoverableReason($offer) === null
                && app(UnresolvedPurchaseResolver::class)->canBeAsked($offer))
            ->schema([
                // This workstation's label format, from the browser, for the
                // label a recovery brings back.
                Forms\Components\Hidden::make('label_format')->default('pdf'),
                Forms\Components\Hidden::make('label_dpi'),
                Forms\Components\Hidden::make('has_report_printer')->default(false),
                View::make('filament.components.batch-ship-local-storage'),
            ])
            ->modalHeading(fn (Package $record): string => 'Ask '.self::sourceName(self::offersFor($record)->first()).' again')
            ->modalDescription(function (Package $record): string {
                $source = self::sourceName(self::offersFor($record)->first());

                return "If {$source} has the label, the package is marked shipped and you can print it from this page. "
                    ."If {$source} says nothing was bought, the package can be bought again. Nothing new is bought.";
            })
            ->modalSubmitActionLabel('Ask')
            ->action(function (Package $record, array $data): void {
                $source = self::sourceName(self::offersFor($record)->first());

                $result = app(PackageShippingWorkflow::class)->checkEarlierPurchases(
                    $record,
                    (string) ($data['label_format'] ?? 'pdf'),
                    filled($data['label_dpi'] ?? null) ? (int) $data['label_dpi'] : null,
                    auth()->id(),
                );

                $record->refresh();

                match (true) {
                    $result === null => Notification::make()
                        ->title('Nothing was bought')
                        ->body("{$source} says no label exists, so the package can be bought again.")
                        ->success()
                        ->send(),
                    $result->success => Notification::make()
                        ->title('Label found')
                        ->body("The package is shipped on {$record->tracking_number}. Print the label from this page.")
                        ->success()
                        ->send(),
                    $result->title !== PackageShippingResult::UNFINISHED_PURCHASE => Notification::make()
                        ->title($result->title ?? 'Still unresolved')
                        ->body($result->message)
                        ->warning()
                        ->send(),
                    ($reason = self::unrecoverableReason(self::offersFor($record)->first())) !== null => Notification::make()
                        ->title("{$source} cannot send this label again")
                        ->body("{$reason} Void or keep the label instead.")
                        ->warning()
                        ->send(),
                    default => Notification::make()
                        ->title("Still no answer from {$source}")
                        ->body("Try again later, or look the package up in your {$source} account and use Record what I found.")
                        ->warning()
                        ->send(),
                };
            });
    }

    /**
     * Step two, when asking cannot settle it: say what should happen.
     *
     * For a sale the source confirmed, the label exists whatever anyone finds,
     * so the question is what to do with it — void it, keep it, or note that
     * it was already voided. For a purchase nobody heard back about, the
     * person has looked in the account, and "there is no label" is a fourth
     * answer.
     */
    public static function recordWhatWasFound(): Action
    {
        $offer = fn (Package $record, Get $get): ?ShippingOffer => self::offersFor($record)
            ->firstWhere('id', (int) $get('offer'))
            ?? self::offersFor($record)->first();

        $confirmed = fn (Package $record, ?Get $get = null): bool => ($get ? $offer($record, $get) : self::offersFor($record)->first())
            ?->isKnownSold() ?? false;

        return Action::make('recordWhatWasFound')
            ->label(fn (Package $record): string => $confirmed($record) ? 'Void or keep the label' : 'Record what I found')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            // A manager's call: every answer but "keep it" lets the package be
            // bought again.
            ->visible(fn (Package $record): bool => self::isManager() && self::offersFor($record)->isNotEmpty())
            ->modalHeading(fn (Package $record): string => $confirmed($record)
                ? self::sourceName(self::offersFor($record)->first()).' sold this label'
                : 'Record what you found')
            ->modalDescription(function (Package $record) use ($confirmed): string {
                $source = self::sourceName(self::offersFor($record)->first());

                return $confirmed($record)
                    ? "{$source} confirmed it sold this label, but PolyBag never saved it, so it cannot be printed from here. Choose what should happen to it."
                    : "Look this package up in your {$source} account, then say what is there.";
            })
            ->modalSubmitActionLabel('Confirm')
            ->schema([
                Forms\Components\Select::make('offer')
                    ->label('Purchase')
                    ->options(fn (Package $record): array => self::offersFor($record)
                        ->mapWithKeys(fn (ShippingOffer $offer): array => [$offer->id => self::describe($offer)])
                        ->all())
                    ->default(fn (Package $record): ?int => self::offersFor($record)->first()?->id)
                    ->visible(fn (Package $record): bool => self::offersFor($record)->count() > 1)
                    ->required()
                    ->live(),
                Forms\Components\Radio::make('outcome')
                    ->label(fn (Package $record, Get $get): string => $confirmed($record, $get) ? 'What should happen to it?' : 'What did you find?')
                    ->options(fn (Package $record, Get $get): array => self::outcomes($offer($record, $get)))
                    ->descriptions(fn (Package $record, Get $get): array => self::outcomeDescriptions($offer($record, $get)))
                    ->default(self::VOID_LABEL)
                    ->required()
                    ->live(),
                Forms\Components\TextInput::make('tracking_number')
                    ->label('Tracking number')
                    ->default(fn (Package $record, Get $get): ?string => self::knownTrackingNumber($offer($record, $get)))
                    // What the source confirmed is not for retyping.
                    ->readOnly(fn (Package $record, Get $get): bool => $confirmed($record, $get)
                        && self::knownTrackingNumber($offer($record, $get)) !== null)
                    ->visible(fn (Get $get): bool => filled($get('outcome')) && $get('outcome') !== self::NOTHING_BOUGHT)
                    ->required(fn (Get $get): bool => filled($get('outcome')) && $get('outcome') !== self::NOTHING_BOUGHT)
                    ->maxLength(255),
                Forms\Components\Textarea::make('note')
                    ->label('Note')
                    ->helperText('Optional. Kept in the audit log with your name.')
                    ->rows(2)
                    ->maxLength(1000),
            ])
            ->action(function (Package $record, array $data, Action $action): void {
                $offer = self::offersFor($record)->firstWhere('id', (int) ($data['offer'] ?? 0))
                    ?? self::offersFor($record)->first();

                if ($offer === null) {
                    Notification::make()->title(PurchaseNotResolvableException::alreadyResolved()->getMessage())->warning()->send();

                    return;
                }

                $resolver = app(UnresolvedPurchaseResolver::class);
                $user = auth()->user();
                $trackingNumber = (string) ($data['tracking_number'] ?? '');
                $note = $data['note'] ?? null;

                try {
                    if ($data['outcome'] === self::VOID_LABEL) {
                        self::notifyVoid($resolver->recordLabelAndVoid($offer, $trackingNumber, $user, $note));
                    } elseif ($data['outcome'] === self::ALREADY_VOIDED) {
                        self::notifyVoid($resolver->recordLabelAlreadyVoided($offer, $trackingNumber, $user, $note));
                    } elseif ($data['outcome'] === self::LABEL_EXISTS) {
                        $resolver->recordLabel($offer, $trackingNumber, $user, $note);
                        Notification::make()->title('Label kept')->body('The package is shipped on this label.')->success()->send();
                    } else {
                        $resolver->recordNothingBought($offer, $user, $note);
                        Notification::make()->title('Recorded: no label')->body('The package can be bought again.')->success()->send();
                    }
                } catch (PurchaseNotResolvableException $e) {
                    Notification::make()->title('Not recorded')->body($e->getMessage())->danger()->send();

                    $action->halt();
                }

                $record->refresh();
            });
    }

    /**
     * The answers a person can give about this purchase.
     *
     * @return array<string, string>
     */
    public static function outcomes(?ShippingOffer $offer): array
    {
        $source = self::sourceName($offer);

        if ($offer?->isKnownSold()) {
            return [
                self::VOID_LABEL => 'Void it',
                self::LABEL_EXISTS => 'Keep it',
                self::ALREADY_VOIDED => "It was already voided at {$source}",
            ];
        }

        return [
            self::VOID_LABEL => 'The label exists, and should be voided',
            self::LABEL_EXISTS => 'The label exists, and is on the parcel',
            self::ALREADY_VOIDED => "The label exists, and was already voided at {$source}",
            self::NOTHING_BOUGHT => 'There is no label',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function outcomeDescriptions(?ShippingOffer $offer): array
    {
        $source = self::sourceName($offer);

        return [
            self::VOID_LABEL => "{$source} is asked to cancel it, or to refund it if it is too late to cancel. The package can be bought again.",
            self::LABEL_EXISTS => 'Only if it was printed some other way and is on the parcel. The package is marked shipped on it. PolyBag has no image of it, so it cannot be printed from here.',
            self::ALREADY_VOIDED => "Recorded as voided without contacting {$source}. The package can be bought again.",
            self::NOTHING_BOUGHT => 'The package can be bought again.',
        ];
    }

    /**
     * The label is recorded whether or not the void went through; say which.
     */
    private static function notifyVoid(LabelVoidResult $result): void
    {
        if ($result->success) {
            LabelVoidNotification::send($result);

            return;
        }

        Notification::make()
            ->title('Recorded, not voided')
            ->body("The label is recorded on the package, but voiding it failed: {$result->message} Try Void again from this page.")
            ->danger()
            ->persistent()
            ->send();
    }

    /**
     * What happened, in the words a person would use, and what to do next.
     */
    public static function explain(Package $package): string
    {
        $offer = self::offersFor($package)->first();

        if ($offer === null) {
            return '';
        }

        $source = self::sourceName($offer);
        $what = sprintf(
            '%s label for $%s on %s',
            self::serviceName($offer),
            number_format((float) $offer->price, 2),
            $offer->consumed_at?->tz(Location::timezone())->format('M j, Y g:i A') ?? 'an earlier date',
        );

        $happened = $offer->isKnownSold()
            ? "{$source} sold a {$what}, but PolyBag failed to save it."
            : "PolyBag tried to buy a {$what}, but never heard back from {$source}, so the label may or may not exist.";

        $confirmed = $offer->isKnownSold();
        $canBeAsked = app(UnresolvedPurchaseResolver::class)->canBeAsked($offer);

        $next = match (true) {
            ! self::isManager() => 'A manager needs to settle this before another label can be bought.',
            ($reason = self::unrecoverableReason($offer)) !== null => "{$reason} No other label can be bought until it is voided or kept.",
            $confirmed && $canBeAsked => "No other label can be bought until this is settled. Ask {$source} again first: it may still send the label, so it can be printed. Otherwise, void or keep it.",
            $confirmed => 'No other label can be bought until it is voided or kept.',
            $canBeAsked => "No other label can be bought until this is settled. Ask {$source} again first. If {$source} still cannot say, look the package up in your {$source} account and record what you found.",
            default => "No other label can be bought until this is settled. Look the package up in your {$source} account and record what you found.",
        };

        return "{$happened} {$next}";
    }

    /**
     * Why the source will never hand this label over again, when it said so.
     */
    public static function unrecoverableReason(?ShippingOffer $offer): ?string
    {
        $reason = $offer?->purchase_context[OfferStore::UNRECOVERABLE_REASON] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /**
     * Where the purchase stands, for a person choosing between two.
     */
    public static function status(ShippingOffer $offer): string
    {
        return match (true) {
            $offer->isKnownSold() => 'bought, never saved',
            $offer->recovery_unanswered_at !== null => 'asked again, no answer',
            default => 'no reply',
        };
    }

    /**
     * The tracking number the source already gave, where it gave one: in a
     * reply nobody could read, or as the reference a direct carrier stamps.
     */
    public static function knownTrackingNumber(?ShippingOffer $offer): ?string
    {
        if ($offer === null) {
            return null;
        }

        $reported = $offer->purchase_context[OfferStore::REPORTED_TRACKING_NUMBER] ?? null;

        if (is_string($reported) && $reported !== '') {
            return $reported;
        }

        return $offer->postage_source === PostageSource::CarrierAccount ? $offer->purchase_reference : null;
    }

    public static function describe(?ShippingOffer $offer): string
    {
        if ($offer === null) {
            return '—';
        }

        return sprintf(
            '%s, $%s, %s (%s)',
            self::serviceName($offer),
            number_format((float) $offer->price, 2),
            $offer->consumed_at?->tz(Location::timezone())->format('M j, Y g:i A') ?? '—',
            self::status($offer),
        );
    }

    public static function sourceName(?ShippingOffer $offer): string
    {
        return $offer?->sellerName() ?? 'the carrier';
    }

    private static function serviceName(ShippingOffer $offer): string
    {
        // Direct USPS names its services with the carrier already in front.
        return str_starts_with((string) $offer->service_name, $offer->carrier)
            ? (string) $offer->service_name
            : trim("{$offer->carrier} {$offer->service_name}");
    }

    private static function isManager(): bool
    {
        return auth()->user()?->role->isAtLeast(Role::Manager) ?? false;
    }
}
