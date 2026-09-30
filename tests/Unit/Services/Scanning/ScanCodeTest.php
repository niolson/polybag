<?php

use App\Enums\ScanCodeType;
use App\Enums\ScanCommand;
use App\Models\BoxSize;
use App\Models\Package;
use App\Models\Shipment;
use App\Services\Scanning\ScanCode;

beforeEach(function (): void {
    config(['app.scan_code_prefix' => 'PB']);
});

it('reads a PolyBag code as a type and a body', function (string $scan, ScanCodeType $type, ?int $id, ?string $name): void {
    $code = ScanCode::parse($scan);

    expect($code?->type)->toBe($type)
        ->and($code?->id)->toBe($id)
        ->and($code?->name)->toBe($name);
})->with([
    'shipment' => ['PBS216', ScanCodeType::Shipment, 216, null],
    'package' => ['PBP229', ScanCodeType::Package, 229, null],
    'box size' => ['PBB17', ScanCodeType::BoxSize, 17, null],
    'command' => ['PBCSHIP', ScanCodeType::Command, null, 'SHIP'],
    'operator action' => ['PBM12', ScanCodeType::Action, 12, null],
    'lower case, as Caps Lock sends it' => ['pbs216', ScanCodeType::Shipment, 216, null],
    'leading zeros' => ['PBS000216', ScanCodeType::Shipment, 216, null],
    'surrounding whitespace' => ["  PBS216\n", ScanCodeType::Shipment, 216, null],
]);

it('reads a scan without the prefix as an external identifier', function (string $scan): void {
    expect(ScanCode::parse($scan))->toBeNull();
})->with([
    'an order reference' => ['#1247'],
    'bare digits' => ['216'],
    'a bare letter code' => ['S216'],
    'an old command barcode' => ['*1'],
    'a box code' => ['01'],
]);

it('reads the prefix with anything it does not understand as unrecognised, never as external', function (string $scan): void {
    $code = ScanCode::parse($scan);

    expect($code)->not->toBeNull()
        ->and($code?->isRecognised())->toBeFalse();
})->with([
    'an unknown type token' => ['PBX12'],
    'a record with a word body' => ['PBSABC'],
    'a command with digits' => ['PBC123'],
    'a zero ID' => ['PBS0'],
    'more digits than an ID holds' => ['PBS1234567890123456789'],
    'the prefix alone' => ['PB'],
]);

it('uses the install\'s configured prefix', function (): void {
    config(['app.scan_code_prefix' => 'zq9']);

    $shipment = new Shipment;
    $shipment->id = 216;

    expect(ScanCode::forShipment($shipment))->toBe('ZQ9S216')
        ->and(ScanCode::parse('ZQ9S216')?->id)->toBe(216)
        ->and(ScanCode::parse('PBS216'))->toBeNull();
});

it('refuses a prefix that is not a letter then up to three letters or digits', function (string $prefix): void {
    config(['app.scan_code_prefix' => $prefix]);

    ScanCode::prefix();
})->with([
    'empty' => [''],
    'punctuation' => ['PB-'],
    'too long' => ['TOOLONG'],
    'a symbol' => ['*'],
    'a leading digit, which would claim numeric UPCs' => ['1PB'],
])->throws(InvalidArgumentException::class);

it('spells codes that parse back to their records and commands', function (): void {
    $shipment = new Shipment;
    $shipment->id = 216;
    $package = new Package;
    $package->id = 229;
    $boxSize = new BoxSize;
    $boxSize->id = 17;

    expect(ScanCode::forShipment($shipment))->toBe('PBS216')
        ->and(ScanCode::forPackage($package))->toBe('PBP229')
        ->and(ScanCode::forBoxSize($boxSize))->toBe('PBB17')
        ->and(ScanCode::parse(ScanCode::forPackage($package))?->type)->toBe(ScanCodeType::Package);

    foreach (ScanCommand::cases() as $command) {
        expect(ScanCode::parse(ScanCode::forCommand($command))?->command())->toBe($command);
    }
});

it('keeps the type tokens prefix-free, so a code parses one way', function (): void {
    $tokens = array_map(fn (ScanCodeType $type): string => $type->value, ScanCodeType::cases());

    foreach ($tokens as $token) {
        foreach ($tokens as $other) {
            if ($token !== $other) {
                expect(str_starts_with($other, $token))->toBeFalse("{$token} is the start of {$other}");
            }
        }
    }
});
