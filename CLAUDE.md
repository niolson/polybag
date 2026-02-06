# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Web-based shipping application for packing and labeling shipments. Built with Laravel 12 + Filament.

**Tech Stack:** PHP 8.2+, Laravel 12.0, Filament 4.0, Vite 7.0, Tailwind CSS 4.0, Pest 4.2

**Database:** MySQL

**Local Hardware Integration:**
- **Printing:** QZ Tray (local WebSocket print agent)
- **Scale:** WebHID API (browser-native USB access)

## Template Reference

Based on a prior `filament-shipping` template with these key changes:

| Feature | Template (filament-shipping) | This App |
|---------|------------------------------|----------|
| Printing | PrintNode API (cloud) | QZ Tray (local) |
| Scale | PrintNode WebSocket | WebHID API |
| Platform | Web app | Web app |
| Device Settings | Server-side (PrintNode IDs) | Browser localStorage |

## Domain Model

- **Shipment** - Order to be shipped (address, items, validation status)
- **ShipmentItem** - Line items in a shipment
- **Package** - Physical package with tracking, label, dimensions
- **PackageItem** - Items packed in a package (with transparency codes)
- **ShippingMethod** - Available shipping options
- **Carrier** / **CarrierService** - USPS, FedEx and their services
- **BoxSize** - Predefined box dimensions (scanned by code)
- **Channel** - Sales channel source

## Key Workflows

1. **Packing** (`/pack/{shipment_id}`) - Scan box code, scan items, read weight from scale
2. **Shipping** (`/ship/{package_id}`) - Get rates, buy postage, print label
3. **Update Weight** (`/update-weight`) - Scan product barcode, read scale, update product weight

## Hardware Integration

### QZ Tray (Printing)
- Must be installed on each workstation: https://qz.io/download/
- Connects via WebSocket to `wss://localhost:8181`
- Printer selection stored in browser `localStorage`
- Configured via Device Settings page

### WebHID (Scale)
- Browser-native USB scale access (Chrome/Edge only)
- Scale vendor/product IDs stored in browser `localStorage`
- Auto-connects to previously paired scales

## API Integrations

- **USPS** - Address validation, domestic/international rates, label generation (via Saloon)
- **FedEx** - Rate quotes and shipment creation (via Saloon)

## Data Import

The template app used a proprietary ERP database connection. This app needs a generic, pluggable system for importing shipment data from external sources:
- API endpoints
- ODBC tables
- Custom database connectors

The previous proprietary database can still be used for testing data, but the import system should be abstracted.

## Commands

```bash
# Development
composer run setup       # Initial setup (install deps, migrate, build assets)
composer run dev         # Run server + queue + Vite concurrently
npm run dev              # Vite dev server only
npm run build            # Production build

# Testing
composer run test        # Run Pest tests (clears config first)

# Database
php artisan migrate      # Run migrations
php artisan tinker       # Interactive PHP shell

# Code Style
php artisan pint         # Format PHP code with Laravel Pint
```

## Architecture

### Service Providers
- `AppPanelProvider` (Filament) - Main UI panel at `/app` with auth required, amber theme

### UI (Filament)
- Auto-discovers resources from `app/Filament/Resources/`
- Auto-discovers pages from `app/Filament/Pages/`
- Auto-discovers widgets from `app/Filament/Widgets/`

### Database
- MySQL for data storage
- Migrations in `database/migrations/`

### Frontend
- Tailwind CSS 4.0 config in `resources/css/app.css`
- Vite handles HMR and builds
- QZ Tray integration in `resources/js/qz-tray.js`

## Key Files

- `app/Providers/Filament/AppPanelProvider.php` - Filament panel config
- `app/Filament/Pages/Ship.php` - Label generation and print dispatch
- `app/Filament/Pages/Pack.php` - Packing workflow with scale integration
- `app/Filament/Pages/DeviceSettings.php` - Printer/scale configuration
- `resources/js/qz-tray.js` - QZ Tray client wrapper

## Testing

Tests use Pest framework. Test files in `tests/Feature/` and `tests/Unit/`.

Run single test:
```bash
php artisan test --filter=TestName
```
