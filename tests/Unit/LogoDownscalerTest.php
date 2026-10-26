<?php

use App\Services\LogoDownscaler;

function logoBytes(int $width, int $height, string $format = 'png'): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 13, 148, 136));

    ob_start();
    $format === 'jpeg' ? imagejpeg($image) : imagepng($image);

    return (string) ob_get_clean();
}

it('shrinks a large logo to fit the box, keeping its proportions', function (): void {
    $result = (new LogoDownscaler)->fit(logoBytes(2400, 400), 'image/png', 600, 200);

    [$width, $height] = getimagesizefromstring($result['bytes']);

    expect($result['mime'])->toBe('image/png')
        ->and($width)->toBe(600)
        ->and($height)->toBe(100);
});

it('fits a tall logo by its height', function (): void {
    $result = (new LogoDownscaler)->fit(logoBytes(400, 1600), 'image/png', 600, 200);

    expect(getimagesizefromstring($result['bytes']))->toMatchArray([0 => 50, 1 => 200]);
});

it('keeps a JPEG a JPEG', function (): void {
    $result = (new LogoDownscaler)->fit(logoBytes(1800, 600, 'jpeg'), 'image/jpeg', 600, 200);

    expect($result['mime'])->toBe('image/jpeg')
        ->and(getimagesizefromstring($result['bytes'])[0])->toBe(600);
});

it('keeps transparency', function (): void {
    $image = imagecreatetruecolor(1200, 400);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();

    $result = (new LogoDownscaler)->fit($bytes, 'image/png', 600, 200);
    $resized = imagecreatefromstring($result['bytes']);

    expect(imagecolorsforindex($resized, imagecolorat($resized, 5, 5))['alpha'])->toBe(127);
});

it('leaves a logo already within the box unchanged', function (): void {
    $bytes = logoBytes(300, 100);

    expect((new LogoDownscaler)->fit($bytes, 'image/png', 600, 200))->toBe(['bytes' => $bytes, 'mime' => 'image/png']);
});

it('leaves SVG and undecodable bytes unchanged', function (): void {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="4000" height="1000"></svg>';
    $downscaler = new LogoDownscaler;

    expect($downscaler->fit($svg, 'image/svg+xml', 600, 200))->toBe(['bytes' => $svg, 'mime' => 'image/svg+xml'])
        ->and($downscaler->fit('not-an-image', 'image/png', 600, 200))->toBe(['bytes' => 'not-an-image', 'mime' => 'image/png']);
});

it('leaves an image too large to decode in the memory left unchanged', function (): void {
    $bytes = logoBytes(2400, 400);
    $limit = ini_get('memory_limit');
    ini_set('memory_limit', (string) (memory_get_usage(true) + 1048576));

    try {
        $result = (new LogoDownscaler)->fit($bytes, 'image/png', 600, 200);
    } finally {
        ini_set('memory_limit', $limit);
    }

    expect($result['bytes'])->toBe($bytes);
});
