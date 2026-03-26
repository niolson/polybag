<?php

namespace App\Filament\Pages\Auth;

use App\Services\PasswordPolicyService;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

class ChangePassword extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'auth/change-password';

    protected static ?string $title = 'Change Password';

    protected string $view = 'filament.pages.auth.change-password';

    public ?array $data = [];

    public function mount(): void
    {
        if (! session('password_expired')) {
            $this->redirect('/');

            return;
        }

        $this->form->fill();
    }

    public function form(Schema $form): Schema
    {
        $policy = app(PasswordPolicyService::class);

        return $form
            ->schema([
                TextInput::make('current_password')
                    ->label('Current Password')
                    ->password()
                    ->required()
                    ->rules(['current_password']),
                TextInput::make('password')
                    ->label('New Password')
                    ->password()
                    ->required()
                    ->rules(['confirmed', $policy->rule()])
                    ->different('current_password'),
                TextInput::make('password_confirmation')
                    ->label('Confirm New Password')
                    ->password()
                    ->required(),
            ])
            ->statePath('data');
    }

    public function changePassword(): void
    {
        $data = $this->form->getState();

        $user = auth()->user();
        $user->password = Hash::make($data['password']);
        $user->save();

        session()->forget('password_expired');

        Notification::make()
            ->success()
            ->title('Password changed successfully.')
            ->send();

        $this->redirect('/');
    }
}
