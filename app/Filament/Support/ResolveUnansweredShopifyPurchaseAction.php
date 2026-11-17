<?php

namespace App\Filament\Support;

use App\Enums\Role;
use App\Exceptions\PurchaseNotResolvableException;
use App\Models\Package;
use App\Services\ShopifyShippingLabelService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;

/**
 * The package page's way out of a Shopify label purchase that Shopify never
 * answered.
 *
 * A Shopify purchase keeps no offer, so {@see ResolveUnaccountedPurchaseAction}
 * has nothing to show for it. Shopify cannot be asked about the purchase
 * either, so nothing is bought from it for the package until a manager has
 * looked at the order in the Shopify admin and says no label was bought.
 */
class ResolveUnansweredShopifyPurchaseAction
{
    public const CALLOUT_KEY = 'unanswered_shopify_purchase_notice';

    public static function callout(): Callout
    {
        return Callout::make('Unanswered Shopify Label Purchase')
            ->key(self::CALLOUT_KEY)
            ->danger()
            ->columnSpanFull()
            ->visible(fn (Package $record): bool => app(ShopifyShippingLabelService::class)->hasUnansweredAttempt($record))
            ->description(
                'PolyBag asked Shopify to buy a label for this package and never got a reply, so a label may have been bought. '
                .'Nothing more is bought from Shopify for it until that is settled. Check the order in the Shopify admin: '
                .'if it shows a label, print or void it there; if it shows none, a manager can record that here.'
            )
            ->footerActions([self::nothingWasBought()]);
    }

    public static function nothingWasBought(): Action
    {
        return Action::make('shopifyNothingWasBought')
            ->label('No label was bought')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->visible(fn (Package $record): bool => self::isManager()
                && app(ShopifyShippingLabelService::class)->hasUnansweredAttempt($record))
            ->modalHeading('Record that no label was bought')
            ->modalDescription(
                'Only after checking the order in the Shopify admin. Shopify can take a while to finish a purchase, '
                .'so one checked moments after the attempt may not show its label yet. The package can then be bought again.'
            )
            ->modalSubmitActionLabel('Confirm')
            ->schema([
                Forms\Components\Textarea::make('note')
                    ->label('Note')
                    ->helperText('Optional. Kept in the audit log with your name.')
                    ->rows(2)
                    ->maxLength(1000),
            ])
            ->action(function (Package $record, array $data, Action $action): void {
                try {
                    app(ShopifyShippingLabelService::class)->recordUnansweredAttemptAsNotBought($record, auth()->user(), $data['note'] ?? null);
                } catch (PurchaseNotResolvableException $e) {
                    Notification::make()->title('Not Recorded')->body($e->getMessage())->danger()->send();

                    $action->halt();
                }

                Notification::make()->title('Recorded: no label')->body('The package can be bought again.')->success()->send();

                $record->refresh();
            });
    }

    private static function isManager(): bool
    {
        return auth()->user()?->role->isAtLeast(Role::Manager) ?? false;
    }
}
