<?php

namespace App\Enums;

enum AmazonMarketplace: string
{
    case UnitedStates = 'ATVPDKIKX0DER';
    case Canada = 'A2EUQ1WTGCTBG2';
    case Mexico = 'A1AM78C64UM0Y8';
    case Brazil = 'A2Q3Y263D00KWC';

    public function label(): string
    {
        return match ($this) {
            self::UnitedStates => 'Amazon.com',
            self::Canada => 'Amazon.ca',
            self::Mexico => 'Amazon.com.mx',
            self::Brazil => 'Amazon.com.br',
        };
    }

    public function countryCode(): string
    {
        return match ($this) {
            self::UnitedStates => 'US',
            self::Canada => 'CA',
            self::Mexico => 'MX',
            self::Brazil => 'BR',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $marketplace) {
            $options[$marketplace->value] = "{$marketplace->label()} ({$marketplace->countryCode()})";
        }

        return $options;
    }

    /**
     * These marketplaces by label and country code, as the connection form
     * names them; an unsupported ID as itself.
     *
     * @param  list<string>  $marketplaceIds
     */
    public static function names(array $marketplaceIds): string
    {
        return implode(', ', array_map(
            fn (string $marketplaceId): string => ($marketplace = self::tryFrom($marketplaceId)) === null
                ? $marketplaceId
                : "{$marketplace->label()} ({$marketplace->countryCode()})",
            $marketplaceIds,
        ));
    }
}
