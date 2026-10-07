<?php

use App\Support\LabelText;

it('makes a house number ASCII whatever script its digits are in', function (string $text, string $expected): void {
    expect(LabelText::ascii($text))->toBe($expected);
})->with([
    'Persian' => ['خیابان آزادی ۲۷', 'khyaban azady 27'],
    'Devanagari' => ['Main Road २७', 'Main Road 27'],
    'Arabic-Indic' => ['شارع ٢٧', 'sharaa 27'],
    'fullwidth' => ['１２３ Main St', '123 Main St'],
    'ASCII' => ['27B Baker St', '27B Baker St'],
]);

it('transliterates letters as Str::ascii() does', function (): void {
    expect(LabelText::ascii('Zoë Łukasiewicz-Groß, Ольга'))->toBe('Zoe Lukasiewicz-Gross, Olga');
});

it('changes only the digits when asked for ASCII digits alone', function (): void {
    expect(LabelText::asciiDigits('Ольга ۲۷ Zoë'))->toBe('Ольга 27 Zoë');
});
