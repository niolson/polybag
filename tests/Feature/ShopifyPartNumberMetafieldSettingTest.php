<?php

use App\DataTransferObjects\ShipmentImport\ShopifyMetafieldReference;
use App\Enums\ShopifyMetafieldOwner;
use App\Filament\Resources\DataSources\Pages\CreateDataSource;
use App\Filament\Resources\DataSources\Pages\EditDataSource;
use App\Http\Integrations\Shopify\Requests\GraphQL;
use App\Models\Channel;
use App\Models\DataSource;
use App\Models\User;
use App\Services\ShipmentImport\Sources\ShopifySource;
use App\Services\ShopifyFulfillmentOrderActivationService;
use Filament\Notifications\Notification;
use Livewire\Livewire;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;

/**
 * @param  array<int, array<string, mixed>>  $nodes
 */
function metafieldDefinitionsPage(array $nodes, ?string $endCursor = null): MockResponse
{
    return MockResponse::make(['data' => ['metafieldDefinitions' => [
        'pageInfo' => ['hasNextPage' => $endCursor !== null, 'endCursor' => $endCursor],
        'nodes' => $nodes,
    ]]]);
}

/**
 * @return array<string, mixed>
 */
function metafieldDefinition(string $name, string $namespaceKey, string $type = 'single_line_text_field'): array
{
    [$namespace, $key] = explode('.', $namespaceKey, 2);

    return ['name' => $name, 'namespace' => $namespace, 'key' => $key, 'type' => ['name' => $type]];
}

/**
 * A Shopify store whose variants define `custom.mpn` (over two pages, behind
 * a non-text `custom.color`), whose products define `custom.part_number`,
 * and whose recent variants carry a Google app's undefined `mm-google-shopping.mpn`
 * beside an undefined JSON metafield and the defined `custom.mpn`.
 *
 * @param  array<int, string>|null  $scopes
 */
function fakeShopifyMetafieldStore(?array $scopes = null): void
{
    Saloon::fake([GraphQL::class => function (PendingRequest $request) use ($scopes): MockResponse {
        $body = $request->body()?->all() ?? [];
        $query = (string) ($body['query'] ?? '');
        $variables = $body['variables'] ?? [];

        if (str_contains($query, 'currentAppInstallation')) {
            return shopifyAccessScopesResponse($scopes);
        }

        if (str_contains($query, 'metafieldDefinitions')) {
            return match ([$variables['ownerType'], $variables['cursor'] ?? null]) {
                ['PRODUCTVARIANT', null] => metafieldDefinitionsPage([
                    metafieldDefinition('Color', 'custom.color', 'color'),
                ], 'page-2'),
                ['PRODUCTVARIANT', 'page-2'] => metafieldDefinitionsPage([
                    metafieldDefinition('MPN', 'custom.mpn'),
                ]),
                ['PRODUCT', null] => metafieldDefinitionsPage([
                    metafieldDefinition('Part numbers', 'custom.part_number', 'list.single_line_text_field'),
                ]),
                default => throw new LogicException('Unexpected metafield definitions request: '.json_encode($variables)),
            };
        }

        return MockResponse::make(['data' => ['productVariants' => ['nodes' => [
            ['metafields' => ['nodes' => [
                ['namespace' => 'mm-google-shopping', 'key' => 'mpn', 'type' => 'single_line_text_field'],
                ['namespace' => 'custom', 'key' => 'mpn', 'type' => 'single_line_text_field'],
            ]]],
            ['metafields' => ['nodes' => [
                ['namespace' => 'mm-google-shopping', 'key' => 'mpn', 'type' => 'single_line_text_field'],
                ['namespace' => 'app', 'key' => 'payload', 'type' => 'json'],
            ]]],
        ]]]]);
    }]);
}

function connectedShopifySource(array $settings = []): DataSource
{
    return createShopifyDataSource(
        array_merge(['channel_name' => Channel::factory()->create(['active' => true])->id], $settings),
        ['oauth_access_token' => 'shpat_test_token'],
    );
}

/**
 * What the select lists when its dropdown opens, as the browser asks for it.
 *
 * @return array<int, array{label: string, value: string}>
 */
function openPartNumberMetafields(DataSource $source): array
{
    $page = Livewire::test(EditDataSource::class, ['record' => $source->id])
        ->call('callSchemaComponentMethod', 'form.settings.part_number_metafield', 'getOptionsForJs');

    return $page->effects['returns'][0] ?? [];
}

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

describe('choices', function (): void {
    it('offers text definitions from both owners and undefined variant metafields, once each', function (): void {
        fakeShopifyMetafieldStore();

        $choices = (new ShopifySource(connectedShopifySource()->settings + ['oauth_access_token' => 'shpat_test_token']))
            ->fetchPartNumberMetafieldChoices();

        expect($choices)->toBe([
            'variant:custom.mpn' => 'MPN — custom.mpn (Variant)',
            'product:custom.part_number' => 'Part numbers — custom.part_number (Product)',
            'variant:mm-google-shopping.mpn' => 'mm-google-shopping.mpn (Variant) — undefined',
        ]);
    });

    it('refuses to list without read_products, naming the scope', function (): void {
        fakeShopifyMetafieldStore(array_values(array_diff(ShopifyFulfillmentOrderActivationService::REQUIRED_SCOPES, ['read_products'])));

        expect(fn (): array => (new ShopifySource(['shop_domain' => 'test-shop.myshopify.com', 'oauth_access_token' => 'shpat_test_token']))
            ->fetchPartNumberMetafieldChoices())
            ->toThrow(DomainException::class, 'read_products');

        Saloon::assertSentCount(1);
    });

    it('lists the choices when the connection form dropdown opens, without anything typed', function (): void {
        fakeShopifyMetafieldStore();

        expect(collect(openPartNumberMetafields(connectedShopifySource()))->pluck('value')->all())
            ->toBe(['variant:custom.mpn', 'product:custom.part_number', 'variant:mm-google-shopping.mpn']);
    });

    it('shows a missing scope as a notification, not an exception', function (): void {
        fakeShopifyMetafieldStore(['read_merchant_managed_fulfillment_orders']);

        expect(openPartNumberMetafields(connectedShopifySource()))->toBe([]);

        Notification::assertNotified(
            Notification::make()
                ->title('Could not list Shopify metafields')
                ->body('Reconnect Shopify with the read_products scope to list its metafields. You can still type a metafield with the + button.')
                ->danger(),
        );
    });
});

describe('the setting', function (): void {
    it('saves a chosen metafield as owner, namespace and key', function (): void {
        Saloon::fake([]);
        $source = connectedShopifySource();

        Livewire::test(EditDataSource::class, ['record' => $source->id])
            ->fillForm(['settings.part_number_metafield' => 'product:custom.part_number'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($source->fresh()->settings['part_number_metafield'])
            ->toBe(['owner' => 'product', 'namespace' => 'custom', 'key' => 'part_number']);
        Saloon::assertNothingSent();
    });

    it('loads a saved setting with its label and no Shopify request', function (): void {
        Saloon::fake([]);
        $source = connectedShopifySource(['part_number_metafield' => ['owner' => 'variant', 'namespace' => 'custom', 'key' => 'mpn']]);

        Livewire::test(EditDataSource::class, ['record' => $source->id])
            ->assertFormSet(['settings.part_number_metafield' => 'variant:custom.mpn'])
            ->assertSee('custom.mpn (Variant)')
            ->call('save')
            ->assertHasNoFormErrors();

        expect($source->fresh()->settings['part_number_metafield'])
            ->toBe(['owner' => 'variant', 'namespace' => 'custom', 'key' => 'mpn']);
        Saloon::assertNothingSent();
    });

    it('clears a saved setting when saved with no choice', function (): void {
        $source = connectedShopifySource(['part_number_metafield' => ['owner' => 'variant', 'namespace' => 'custom', 'key' => 'mpn']]);

        Livewire::test(EditDataSource::class, ['record' => $source->id])
            ->fillForm(['settings.part_number_metafield' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($source->fresh()->settings)->toHaveKey('part_number_metafield', null);
    });

    it('saves a typed metafield with the owner chosen for it', function (): void {
        $source = connectedShopifySource();

        Livewire::test(EditDataSource::class, ['record' => $source->id])
            ->callFormComponentAction('settings.part_number_metafield', 'createOption', [
                'namespace_key' => 'google.mpn',
                'owner' => ShopifyMetafieldOwner::Product->value,
            ])
            ->assertHasNoFormComponentActionErrors()
            ->assertFormSet(['settings.part_number_metafield' => 'product:google.mpn'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($source->fresh()->settings['part_number_metafield'])
            ->toBe(['owner' => 'product', 'namespace' => 'google', 'key' => 'mpn']);
    });

    it('refuses a typed metafield without a dot', function (): void {
        Livewire::test(EditDataSource::class, ['record' => connectedShopifySource()->id])
            ->callFormComponentAction('settings.part_number_metafield', 'createOption', [
                'namespace_key' => 'mpn',
                'owner' => ShopifyMetafieldOwner::Variant->value,
            ])
            ->assertHasFormComponentActionErrors(['namespace_key' => 'regex']);
    });

    it('refuses a value that is not a metafield reference', function (): void {
        Livewire::test(EditDataSource::class, ['record' => connectedShopifySource()->id])
            ->fillForm(['settings.part_number_metafield' => 'variant:mpn'])
            ->call('save')
            ->assertHasFormErrors(['settings.part_number_metafield']);
    });

    it('is offered only on a saved connection that can reach Shopify', function (): void {
        Livewire::test(CreateDataSource::class)
            ->fillForm(['source_type' => ShopifySource::class])
            ->assertFormFieldHidden('settings.part_number_metafield');

        $withoutCredentials = createShopifyDataSource(
            ['channel_name' => Channel::factory()->create(['active' => true])->id],
            ['client_id' => null, 'client_secret' => null],
        );

        Livewire::test(EditDataSource::class, ['record' => $withoutCredentials->id])
            ->assertFormFieldHidden('settings.part_number_metafield');

        Livewire::test(EditDataSource::class, ['record' => connectedShopifySource()->id])
            ->assertFormFieldVisible('settings.part_number_metafield');
    });
});

it('round-trips a reference through the setting and the option value', function (): void {
    $reference = ShopifyMetafieldReference::fromSetting(['owner' => 'product', 'namespace' => '$app:parts', 'key' => 'mpn']);

    expect($reference?->optionValue())->toBe('product:$app:parts.mpn')
        ->and(ShopifyMetafieldReference::fromOptionValue('product:$app:parts.mpn'))->toEqual($reference)
        ->and(ShopifyMetafieldReference::fromOptionValue('vendor:custom.mpn'))->toBeNull()
        ->and(ShopifyMetafieldReference::fromSetting(['owner' => 'variant', 'namespace' => 'custom']))->toBeNull();
});
