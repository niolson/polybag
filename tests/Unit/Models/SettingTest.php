<?php

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores and retrieves string values', function (): void {
    $setting = Setting::create([
        'key' => 'test_string',
        'value' => 'hello world',
        'type' => 'string',
    ]);

    $retrieved = Setting::find('test_string');

    expect($retrieved->value)->toBe('hello world');
});

it('stores and retrieves boolean true values', function (): void {
    $setting = Setting::create([
        'key' => 'test_bool_true',
        'type' => 'boolean',
    ]);
    $setting->value = true;
    $setting->save();

    $retrieved = Setting::find('test_bool_true');

    expect($retrieved->value)->toBeTrue();
});

it('stores and retrieves boolean false values', function (): void {
    $setting = Setting::create([
        'key' => 'test_bool_false',
        'type' => 'boolean',
    ]);
    $setting->value = false;
    $setting->save();

    $retrieved = Setting::find('test_bool_false');

    expect($retrieved->value)->toBeFalse();
});

it('stores and retrieves integer values', function (): void {
    $setting = Setting::create([
        'key' => 'test_integer',
        'type' => 'integer',
    ]);
    $setting->value = 42;
    $setting->save();

    $retrieved = Setting::find('test_integer');

    expect($retrieved->value)->toBe(42)
        ->and($retrieved->value)->toBeInt();
});

it('stores and retrieves json values', function (): void {
    $data = ['foo' => 'bar', 'count' => 5];

    $setting = Setting::create([
        'key' => 'test_json',
        'type' => 'json',
    ]);
    $setting->value = $data;
    $setting->save();

    $retrieved = Setting::find('test_json');

    expect($retrieved->value)->toBeArray()
        ->and($retrieved->value['foo'])->toBe('bar')
        ->and($retrieved->value['count'])->toBe(5);
});

it('encrypts values when encrypted flag is true', function (): void {
    $setting = Setting::create([
        'key' => 'test_encrypted',
        'type' => 'string',
        'encrypted' => true,
    ]);
    $setting->value = 'secret_value';
    $setting->save();

    // Get raw value from database
    $rawValue = \DB::table('settings')
        ->where('key', 'test_encrypted')
        ->value('value');

    // Raw value should NOT be the plain text
    expect($rawValue)->not->toBe('secret_value');

    // But when retrieved through the model, it should be decrypted
    $retrieved = Setting::find('test_encrypted');
    expect($retrieved->value)->toBe('secret_value');
});

it('returns null for null values', function (): void {
    Setting::create([
        'key' => 'test_null',
        'value' => null,
        'type' => 'string',
    ]);

    $retrieved = Setting::find('test_null');

    expect($retrieved->value)->toBeNull();
});

it('uses key as primary key', function (): void {
    $setting = Setting::create([
        'key' => 'test_primary_key',
        'value' => 'test',
        'type' => 'string',
    ]);

    expect($setting->getKey())->toBe('test_primary_key')
        ->and($setting->getKeyName())->toBe('key');
});

it('stores group information', function (): void {
    Setting::create([
        'key' => 'test_grouped',
        'value' => 'test',
        'type' => 'string',
        'group' => 'my_group',
    ]);

    $retrieved = Setting::find('test_grouped');

    expect($retrieved->group)->toBe('my_group');
});
