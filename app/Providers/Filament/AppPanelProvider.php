<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Http\Middleware\EnsureSetupComplete;
use App\Services\SettingsService;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Platform;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $appName = (string) config('app.name', 'PolyBag');
        $escapedAppName = e($appName);

        return $panel
            ->default()
            ->id('app')
            ->path('/')
            ->viteTheme('resources/css/filament/app/theme.css')
            ->login(Login::class)
            ->brandName($appName)
            ->brandLogo(new HtmlString('<div style="display:flex;align-items:center;gap:0.625rem;"><svg width="20" height="24" viewBox="0 0 32 38" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="1.5" y="1.5" width="29" height="35" rx="2" stroke="#0d9488" stroke-width="2.5"/><rect x="1.5" y="1.5" width="29" height="7.5" rx="2" fill="rgba(13,148,136,0.08)" stroke="#0d9488" stroke-width="2.5"/><line x1="15" y1="26" x2="15" y2="34" stroke="#0d9488" stroke-width="2.5" stroke-linecap="round"/><line x1="18.5" y1="26" x2="18.5" y2="34" stroke="#0d9488" stroke-width="2.5" stroke-linecap="round"/><line x1="22" y1="26" x2="22" y2="34" stroke="#0d9488" stroke-width="2.5" stroke-linecap="round"/><line x1="25.5" y1="26" x2="25.5" y2="34" stroke="#0d9488" stroke-width="2.5" stroke-linecap="round"/></svg><span style="font-weight:600;font-size:1.125rem;color:#0f172a;">'.$escapedAppName.'</span></div>'))
            ->darkModeBrandLogo(new HtmlString('<div style="display:flex;align-items:center;gap:0.625rem;"><svg width="20" height="24" viewBox="0 0 32 38" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="0" y="0" width="32" height="38" rx="4" fill="#0d9488"/><rect x="0" y="0" width="32" height="9" rx="4" fill="rgba(255,255,255,0.18)"/><rect x="0" y="5" width="32" height="4" fill="rgba(255,255,255,0.18)"/><line x1="15" y1="26" x2="15" y2="34" stroke="white" stroke-width="2.5" stroke-linecap="round"/><line x1="18.5" y1="26" x2="18.5" y2="34" stroke="white" stroke-width="2.5" stroke-linecap="round"/><line x1="22" y1="26" x2="22" y2="34" stroke="white" stroke-width="2.5" stroke-linecap="round"/><line x1="25.5" y1="26" x2="25.5" y2="34" stroke="white" stroke-width="2.5" stroke-linecap="round"/></svg><span style="font-weight:600;font-size:1.125rem;color:#f1f5f9;">'.$escapedAppName.'</span></div>'))
            ->brandLogoHeight('2rem')
            ->favicon(asset('favicon.svg'))
            ->font('DM Sans')
            ->defaultThemeMode(ThemeMode::System)
            ->colors([
                'primary' => '#0d9488',
                'gray' => Color::Slate,
                'info' => Color::Sky,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'danger' => Color::Rose,
            ])
            ->sidebarWidth('16rem')
            ->maxContentWidth(Width::ScreenTwoExtraLarge)
            ->sidebarCollapsibleOnDesktop()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->globalSearchFieldSuffix(fn (): ?string => match (Platform::detect()) {
                Platform::Windows, Platform::Linux => 'CTRL+K',
                Platform::Mac => '⌘K',
                default => null,
            })
            ->unsavedChangesAlerts()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->navigationGroups(['Ship', 'Manage', 'Reports', 'Settings'])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->databaseNotifications()
            ->widgets([])
            ->renderHook(
                PanelsRenderHook::TOPBAR_LOGO_AFTER,
                fn (): string => app(SettingsService::class)->get('sandbox_mode', false)
                    ? '<span class="text-xs font-medium text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-950 px-2 py-0.5 rounded-full">(sandbox mode)</span>'
                    : '',
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsureSetupComplete::class,
            ]);
    }
}
