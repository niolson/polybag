<?php

namespace App\Filament\Resources\CarrierServiceResource\Pages;

use App\Filament\Resources\CarrierServiceResource;
use App\Models\ShippingRule;
use App\Models\SourceServiceMapping;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditCarrierService extends EditRecord
{
    protected static string $resource = CarrierServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->before(function (Actions\DeleteAction $action): void {
                    // The rule's foreign key restricts deletion, so a rule
                    // never silently disappears with the service it names.
                    $rule = ShippingRule::query()->with('shippingMethod')->where('carrier_service_id', $this->getRecord()->getKey())->first();

                    if ($rule instanceof ShippingRule) {
                        Notification::make()
                            ->title('Cannot delete carrier service')
                            ->body("The shipping rule {$rule->describe()} names this service. Change the rule, or deactivate the service instead.")
                            ->danger()
                            ->send();

                        $action->cancel();

                        return;
                    }

                    // The mapping's foreign key restricts deletion; refuse here
                    // so the person reads why instead of a database error.
                    $mapping = SourceServiceMapping::query()->where('carrier_service_id', $this->getRecord()->getKey())->first();

                    if ($mapping instanceof SourceServiceMapping) {
                        Notification::make()
                            ->title('Cannot delete carrier service')
                            ->body("{$mapping->describe()} is mapped to this service. Unmap it on Map Carrier Services, or deactivate the service instead.")
                            ->danger()
                            ->send();

                        $action->cancel();
                    }
                }),
        ];
    }
}
