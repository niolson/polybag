<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum AuditAction: string implements HasLabel
{
    // Event-driven (shipping operations)
    case PackageCreated = 'package_created';
    case PackageShipped = 'package_shipped';
    case PackageCancelled = 'package_cancelled';
    case LabelPrinted = 'label_printed';
    case ShipmentImported = 'shipment_imported';
    case ShipmentUpdated = 'shipment_updated';
    case ImportCompleted = 'import_completed';
    case BatchStarted = 'batch_started';
    case ManifestCreated = 'manifest_created';
    case AddressValidationFailed = 'address_validation_failed';
    case PurchaseResolvedByHand = 'purchase_resolved_by_hand';

    // Observer-driven (CRUD on config models)
    case ModelCreated = 'model_created';
    case ModelUpdated = 'model_updated';
    case ModelDeleted = 'model_deleted';

    // Setting changes
    case SettingChanged = 'setting_changed';

    // DataSource raw-SQL query execution
    case DataSourceQueryExecuted = 'datasource_query_executed';

    // Authentication events
    case LoginSucceeded = 'login_succeeded';
    case LoginFailed = 'login_failed';
    case Logout = 'logout';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::PackageCreated => 'Package Created',
            self::PackageShipped => 'Package Shipped',
            self::PackageCancelled => 'Package Cancelled',
            self::LabelPrinted => 'Label Printed',
            self::ShipmentImported => 'Shipment Imported',
            self::ShipmentUpdated => 'Shipment Updated',
            self::ImportCompleted => 'Import Completed',
            self::BatchStarted => 'Batch Started',
            self::ManifestCreated => 'Manifest Created',
            self::AddressValidationFailed => 'Address Validation Failed',
            self::PurchaseResolvedByHand => 'Purchase Resolved by Hand',
            self::ModelCreated => 'Created',
            self::ModelUpdated => 'Updated',
            self::ModelDeleted => 'Deleted',
            self::SettingChanged => 'Setting Changed',
            self::DataSourceQueryExecuted => 'Data Source Query Executed',
            self::LoginSucceeded => 'Login Succeeded',
            self::LoginFailed => 'Login Failed',
            self::Logout => 'Logout',
        };
    }
}
