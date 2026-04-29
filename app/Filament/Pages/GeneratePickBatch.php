<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Filament\Resources\PickBatches\PickBatchResource;
use App\Models\Channel;
use App\Models\ShippingMethod;
use App\Services\PickBatchService;
use App\Services\SettingsService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use UnitEnum;

class GeneratePickBatch extends Page implements HasForms
{
    use InteractsWithForms;

    public ?array $data = [];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?string $navigationLabel = 'Generate Pick Batch';

    protected static UnitEnum|string|null $navigationGroup = 'Manage';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.generate-pick-batch';

    protected static ?string $slug = 'generate-pick-batch';

    protected ?string $heading = 'Generate Pick Batch';

    public static function canAccess(): bool
    {
        return (auth()->user()?->role->isAtLeast(Role::Manager) ?? false)
            && app(SettingsService::class)->get('picking_enabled', false);
    }

    public function mount(): void
    {
        $this->form->fill([
            'batch_size' => 25,
            'prioritize_expedited' => true,
            'channel_id' => null,
            'shipping_method_id' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextInput::make('batch_size')
                            ->label('Batch Size')
                            ->helperText('Maximum number of orders to include in this batch.')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->maxValue(500)
                            ->default(25),
                        Toggle::make('prioritize_expedited')
                            ->label('Expedited First')
                            ->helperText('Place orders with expedited shipping methods at the top of the queue.')
                            ->default(true),
                        Select::make('channel_id')
                            ->label('Channel')
                            ->options(fn () => Channel::query()->orderBy('name')->pluck('name', 'id'))
                            ->placeholder('All Channels')
                            ->searchable(),
                        Select::make('shipping_method_id')
                            ->label('Shipping Method')
                            ->options(fn () => ShippingMethod::query()->where('active', true)->orderBy('name')->pluck('name', 'id'))
                            ->placeholder('All Methods')
                            ->searchable(),
                    ])
                    ->footerActions([
                        Action::make('generate')
                            ->label('Generate Batch')
                            ->action(fn () => $this->generate()),
                    ]),
            ])
            ->statePath('data');
    }

    public function generate(): void
    {
        $data = $this->form->getState();

        $batch = app(PickBatchService::class)->autoGenerate(
            batchSize: (int) $data['batch_size'],
            prioritizeExpedited: (bool) ($data['prioritize_expedited'] ?? false),
            channelId: $data['channel_id'] ? (int) $data['channel_id'] : null,
            shippingMethodId: $data['shipping_method_id'] ? (int) $data['shipping_method_id'] : null,
            user: auth()->user(),
        );

        if ($batch->total_shipments === 0) {
            Notification::make()
                ->warning()
                ->title('No pending shipments')
                ->body('There are no pending shipments matching the selected filters.')
                ->send();

            return;
        }

        $this->redirect(PickBatchResource::getUrl('view', ['record' => $batch]));
    }
}
