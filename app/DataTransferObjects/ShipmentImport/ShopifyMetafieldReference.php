<?php

namespace App\DataTransferObjects\ShipmentImport;

use App\Enums\ShopifyMetafieldOwner;

/**
 * Which Shopify metafield a connection reads a value from: the owner it hangs
 * off and its `namespace.key`.
 *
 * Stored on a connection's settings as `{owner, namespace, key}` and carried
 * by the connection form's select as one string, `{owner}:{namespace}.{key}`.
 */
final readonly class ShopifyMetafieldReference
{
    /**
     * Shopify's namespace and key characters, plus the `$app:` prefix an app
     * namespace may be written with.
     */
    private const NAMESPACE_KEY_PATTERN = '([A-Za-z0-9_$:-]+)\.([A-Za-z0-9_-]+)';

    public function __construct(
        public ShopifyMetafieldOwner $owner,
        public string $namespace,
        public string $key,
    ) {}

    public static function fromSetting(mixed $setting): ?self
    {
        if (! is_array($setting)) {
            return null;
        }

        $owner = ShopifyMetafieldOwner::tryFrom((string) ($setting['owner'] ?? ''));
        $namespace = $setting['namespace'] ?? null;
        $key = $setting['key'] ?? null;

        if ($owner === null || ! is_string($namespace) || ! is_string($key)) {
            return null;
        }

        return self::fromNamespaceKey("{$namespace}.{$key}", $owner);
    }

    public static function fromOptionValue(mixed $value): ?self
    {
        if (! is_string($value) || ! str_contains($value, ':')) {
            return null;
        }

        [$owner, $namespaceKey] = explode(':', $value, 2);
        $owner = ShopifyMetafieldOwner::tryFrom($owner);

        return $owner === null ? null : self::fromNamespaceKey($namespaceKey, $owner);
    }

    /**
     * A typed `namespace.key`, or null when it is not one.
     */
    public static function fromNamespaceKey(string $namespaceKey, ShopifyMetafieldOwner $owner): ?self
    {
        if (preg_match('/^'.self::NAMESPACE_KEY_PATTERN.'$/', trim($namespaceKey), $matches) !== 1) {
            return null;
        }

        return new self($owner, $matches[1], $matches[2]);
    }

    /**
     * A regex rule for a typed `namespace.key`.
     */
    public static function namespaceKeyRule(): string
    {
        return 'regex:/^'.self::NAMESPACE_KEY_PATTERN.'$/';
    }

    /**
     * @return array{owner: string, namespace: string, key: string}
     */
    public function toSetting(): array
    {
        return [
            'owner' => $this->owner->value,
            'namespace' => $this->namespace,
            'key' => $this->key,
        ];
    }

    public function optionValue(): string
    {
        return "{$this->owner->value}:{$this->namespaceKey()}";
    }

    public function namespaceKey(): string
    {
        return "{$this->namespace}.{$this->key}";
    }

    public function label(): string
    {
        return "{$this->namespaceKey()} ({$this->owner->getLabel()})";
    }
}
