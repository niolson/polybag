# Repository Guidelines

## Project Structure & Module Organization
PolyBag is a Laravel 12 application with Filament 5 admin pages. Core PHP code lives in `app/`, including `Models/`, `Services/`, `Filament/`, `Http/`, and `DataTransferObjects/`. Blade views and frontend entrypoints live under `resources/views` and `resources/js`, with compiled assets handled by Vite. Database migrations, factories, and seeders live in `database/`. End-to-end coverage is in `e2e/`, while PHPUnit/Pest tests are grouped under `tests/Feature` and `tests/Unit`. Deployment and local infrastructure files are in `docker/`, `infra/`, and `scripts/`.

## Build, Test, and Development Commands
Use the repo scripts instead of ad hoc commands when possible:

- `composer run setup` installs PHP and Node dependencies, creates `.env`, generates the app key, runs migrations, and builds assets.
- `composer run dev` starts the Laravel server, queue worker, scheduler, and Vite dev server together.
- `composer run test` clears config and runs the application test suite.
- `npm run test:e2e` runs Playwright flows from `e2e/`.
- `composer run format` runs Rector, PHPStan, and Pint in sequence.
- `npm run build` creates production frontend assets.

## Coding Style & Naming Conventions
Follow PSR-12 with 4-space indentation for PHP. Run `vendor/bin/pint` before opening a PR. Keep class names singular and descriptive (`ShipmentImportService`, `RateResponse`), and mirror Laravel conventions for models, migrations, and seeders. Use `PascalCase` for PHP classes, `camelCase` for methods, and kebab-case for Blade view filenames where appropriate. Prefer small service classes over controller-heavy logic.

## Testing Guidelines
This repo uses Pest on top of PHPUnit. Add unit tests in `tests/Unit` for isolated services and DTOs, and feature tests in `tests/Feature` for Filament pages, jobs, events, and HTTP flows. Name test files with a `*Test.php` suffix. For browser workflows, add or update Playwright specs in `e2e/*.spec.ts`. Cover new behavior and regressions before merging.

## Commit & Pull Request Guidelines
Recent commits use short, imperative subjects such as `Add setup wizard for guided first-run configuration`. Keep commits focused and descriptive; avoid mixed-purpose changes. PRs should explain the user-visible impact, note schema or config changes, link related issues, and include screenshots for Filament/UI updates. Mention any required follow-up steps such as migrations, seeders, or workstation hardware setup.

## Security & Configuration Tips
Do not commit secrets from `.env`, carrier credentials, or private QZ signing keys. Use `.env` for infrastructure settings and the App Settings UI for encrypted operational credentials. If you touch printing, scale, or OAuth flows, document workstation or callback URL requirements in the PR.
