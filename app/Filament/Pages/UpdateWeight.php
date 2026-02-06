<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Filament\Concerns\NotifiesUser;
use App\Models\Product;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

class UpdateWeight extends Page implements HasForms
{
    use InteractsWithForms;
    use NotifiesUser;

    public ?array $data = [];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationLabel = 'Update Weights';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.update-weight';

    public static function canAccess(): bool
    {
        return auth()->user()?->role->isAtLeast(Role::User) ?? false;
    }

    public ?Product $currentProduct = null;

    public array $recentUpdates = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                TextInput::make('barcode')
                    ->label('Scan Product Barcode')
                    ->required()
                    ->autofocus()
                    ->autocomplete(false)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state) => $this->lookupProduct($state)),
                TextInput::make('weight')
                    ->label('Weight (lbs)')
                    ->required()
                    ->numeric()
                    ->step(0.01)
                    ->minValue(0.01)
                    ->suffix('lbs')
                    ->helperText('Connect scale and place product to auto-fill, or enter manually'),
            ])
            ->statePath('data');
    }

    public function lookupProduct(?string $barcode): void
    {
        if (! $barcode) {
            $this->currentProduct = null;

            return;
        }

        $this->currentProduct = $this->findProductByBarcode($barcode);

        if (! $this->currentProduct) {
            $this->notifyWarning('Product Not Found', "No product found with barcode: {$barcode}");
        }
    }

    public function update(): void
    {
        $data = $this->form->getState();

        $product = $this->findProductByBarcode($data['barcode']);

        if (! $product) {
            $this->notifyError('Product Not Found', 'No product found with the scanned barcode.');

            return;
        }

        $oldWeight = $product->weight;
        $product->weight = $data['weight'];
        $product->save();

        array_unshift($this->recentUpdates, [
            'sku' => $product->sku,
            'name' => $product->name,
            'old_weight' => $oldWeight,
            'new_weight' => $data['weight'],
            'updated_at' => now()->format('H:i:s'),
        ]);

        $this->recentUpdates = array_slice($this->recentUpdates, 0, 10);

        $this->notifySuccess('Weight Updated', "{$product->sku}: {$oldWeight} lbs -> {$data['weight']} lbs");

        $this->currentProduct = null;
        $this->form->fill();
    }

    /**
     * Find a product by SKU or UPC barcode.
     */
    private function findProductByBarcode(string $barcode): ?Product
    {
        return Product::where('sku', $barcode)
            ->orWhere('upc', $barcode)
            ->first();
    }
}
