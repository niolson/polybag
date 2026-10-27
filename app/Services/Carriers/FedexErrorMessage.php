<?php

namespace App\Services\Carriers;

use Illuminate\Support\Str;

/**
 * Turns the `errors` of a failed FedEx reply into a message the person who can
 * fix it understands.
 *
 * FedEx's `message` is often only "Validation failed for object='…'. Error
 * count: N", with the actual problem in `parameterList`: a `key` naming the
 * constraint, the request object and the field
 * (`NotNull.shipShipmentInputVO.requestedShipment.shipper.contact.phoneNumber`)
 * and a `value` saying what is wrong with it ("phoneNumber cannot be null").
 * Every error and every field is kept, since FedEx can report several at once.
 * Fields a user can fix in PolyBag are pointed at where to fix them.
 */
final class FedexErrorMessage
{
    private const GENERIC_VALIDATION_MESSAGE = '/^Validation failed for object=/i';

    /**
     * @param  array<array-key, mixed>  $errors  The reply's `errors`
     */
    public static function from(array $errors, string $fallback = 'FedEx API error'): string
    {
        $sentences = collect($errors)
            ->filter(fn (mixed $error): bool => is_array($error))
            ->flatMap(fn (array $error): array => self::sentencesFor($error))
            ->map(fn (string $sentence): string => self::terminated($sentence))
            ->unique()
            ->values();

        return $sentences->isEmpty() ? $fallback : $sentences->implode(' ');
    }

    /**
     * The field a parameter key names, without its constraint and object
     * prefix: `NotNull.shipShipmentInputVO.requestedShipment.shipper.contact.phoneNumber`
     * is `shipper.contact.phoneNumber`.
     */
    public static function fieldPath(string $key): string
    {
        if (str_contains($key, 'requestedShipment.')) {
            return Str::after($key, 'requestedShipment.');
        }

        $segments = explode('.', $key);

        // A bean-validation constraint (NotNull, Size, Pattern) leads with a capital.
        if (count($segments) > 1 && ctype_upper($segments[0][0] ?? '')) {
            array_shift($segments);
        }

        if (count($segments) > 1 && str_ends_with($segments[0], 'VO')) {
            array_shift($segments);
        }

        return implode('.', $segments);
    }

    /**
     * What to tell the user about one rejected field.
     */
    public static function describeField(string $key, string $value): string
    {
        $path = self::fieldPath($key);
        $detail = $value !== '' ? "{$value} ({$path})" : $path;

        if ($path === 'shipper.contact.phoneNumber' && str_starts_with($key, 'NotNull.')) {
            return 'The ship-from location has no phone number. Add one under Locations.';
        }

        if (str_starts_with($path, 'shipper.')) {
            return "The ship-from location was rejected: {$detail}. Check it under Locations.";
        }

        if (preg_match('/^recipients?(\[\d+\])?\.(address|contact)\./', $path) === 1) {
            return "The shipment's address was rejected: {$detail}. Check the shipment's address.";
        }

        return $detail;
    }

    /**
     * @param  array<array-key, mixed>  $error
     * @return list<string>
     */
    private static function sentencesFor(array $error): array
    {
        $message = is_string($error['message'] ?? null) ? trim($error['message']) : '';
        $fields = collect($error['parameterList'] ?? [])
            ->filter(fn (mixed $parameter): bool => is_array($parameter) && is_string($parameter['key'] ?? null) && $parameter['key'] !== '')
            ->map(fn (array $parameter): string => self::describeField(
                $parameter['key'],
                is_scalar($parameter['value'] ?? null) ? trim((string) $parameter['value']) : '',
            ))
            ->values()
            ->all();

        if ($fields === []) {
            $code = is_string($error['code'] ?? null) ? $error['code'] : '';

            return [$message !== '' ? $message : ($code !== '' ? $code : 'Unknown FedEx error')];
        }

        if ($message !== '' && preg_match(self::GENERIC_VALIDATION_MESSAGE, $message) !== 1) {
            array_unshift($fields, $message);
        }

        return $fields;
    }

    private static function terminated(string $sentence): string
    {
        return preg_match('/[.!?]$/', $sentence) === 1 ? $sentence : $sentence.'.';
    }
}
