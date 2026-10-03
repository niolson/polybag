<?php

use App\Http\Integrations\Gotenberg\Requests\HtmlToPdfRequest;
use Saloon\Data\MultipartValue;

function gotenbergFields(HtmlToPdfRequest $request): array
{
    return collect($request->body()->all())
        ->mapWithKeys(fn (MultipartValue $value): array => [$value->name => $value->value])
        ->all();
}

it('lets a document\'s own @page size decide the paper, so pack slips render 4x6', function (): void {
    $fields = gotenbergFields(new HtmlToPdfRequest('<html></html>'));

    expect($fields['preferCssPageSize'])->toBe('true')
        ->and($fields)->not->toHaveKeys(['paperWidth', 'paperHeight']);
});

it('renders in print media with no page margins', function (): void {
    $fields = gotenbergFields(new HtmlToPdfRequest('<html></html>'));

    expect($fields['emulatedMediaType'])->toBe('print')
        ->and([$fields['marginTop'], $fields['marginBottom'], $fields['marginLeft'], $fields['marginRight']])
        ->toBe(['0', '0', '0', '0']);
});
