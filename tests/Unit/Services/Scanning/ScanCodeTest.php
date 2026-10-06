<?php

use App\Enums\ScanCodeType;
use App\Enums\ScanCommand;
use App\Models\BoxSize;
use App\Models\Package;
use App\Models\Shipment;
use App\Services\Scanning\ScanCode;

it('reads a PolyBag code as a type and a body', function (string $scan, ScanCodeType $type, ?int $id, ?string $name): void {
    $code = ScanCode::parse($scan);

    expect($code?->type)->toBe($type)
        ->and($code?->id)->toBe($id)
        ->and($code?->name)->toBe($name);
})->with([
    'shipment' => ['%S216', ScanCodeType::Shipment, 216, null],
    'package' => ['%P229', ScanCodeType::Package, 229, null],
    'box size' => ['%B17', ScanCodeType::BoxSize, 17, null],
    'command' => ['%CSHIP', ScanCodeType::Command, null, 'SHIP'],
    'operator action' => ['%M12', ScanCodeType::Action, 12, null],
    'lower case, as Caps Lock sends it' => ['%s216', ScanCodeType::Shipment, 216, null],
    'leading zeros' => ['%S000216', ScanCodeType::Shipment, 216, null],
    'surrounding whitespace' => ["  %S216\n", ScanCodeType::Shipment, 216, null],
]);

it('reads a scan without the prefix as an external identifier', function (string $scan): void {
    expect(ScanCode::parse($scan))->toBeNull();
})->with([
    'an order reference' => ['#1247'],
    'bare digits' => ['216'],
    'a bare letter code' => ['S216'],
    'an old command barcode' => ['*1'],
    'a box code' => ['01'],
    'a code under the old letter prefix' => ['PBS216'],
    'a SKU that begins with the old letter prefix' => ['PBJ100'],
]);

it('reads the prefix with anything it does not understand as unrecognized, never as external', function (string $scan): void {
    $code = ScanCode::parse($scan);

    expect($code)->not->toBeNull()
        ->and($code?->isRecognized())->toBeFalse();
})->with([
    'an unknown type token' => ['%X12'],
    'a record with a word body' => ['%SABC'],
    'a command with digits' => ['%C123'],
    'a zero ID' => ['%S0'],
    'more digits than an ID holds' => ['%S1234567890123456789'],
    'the prefix alone' => ['%'],
]);

it('says whether text would be read as a PolyBag code', function (string $text, bool $claimed): void {
    expect(ScanCode::claims($text))->toBe($claimed);
})->with([
    'a code' => ['%S216', true],
    'any case, with whitespace' => ['  %x ', true],
    'the prefix alone' => ['%', true],
    'the prefix inside the text' => ['A%1', false],
    'the old letter prefix' => ['PBS216', false],
    'a box alias' => ['01', false],
]);

it('spells codes that parse back to their records and commands', function (): void {
    $shipment = new Shipment;
    $shipment->id = 216;
    $package = new Package;
    $package->id = 229;
    $boxSize = new BoxSize;
    $boxSize->id = 17;

    expect(ScanCode::forShipment($shipment))->toBe('%S216')
        ->and(ScanCode::forPackage($package))->toBe('%P229')
        ->and(ScanCode::forBoxSize($boxSize))->toBe('%B17')
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
