<?php

namespace App\Services;

/**
 * Shrinks an uploaded raster logo to fit a bounding box, so a surface that prints or
 * shows it small does not carry a multi-megabyte original. The stored upload is never
 * touched, so a surface that needs more resolution later asks for a larger box.
 *
 * Anything it cannot safely shrink comes back unchanged: SVG, an image already within
 * the box, bytes GD cannot decode, or an image too large to decode in the memory left.
 */
class LogoDownscaler
{
    /**
     * @return array{bytes: string, mime: string}
     */
    public function fit(string $bytes, string $mime, int $maxWidth, int $maxHeight): array
    {
        $original = ['bytes' => $bytes, 'mime' => $mime];

        if (! extension_loaded('gd')) {
            return $original;
        }

        $size = @getimagesizefromstring($bytes);

        if ($size === false || ($size[0] <= $maxWidth && $size[1] <= $maxHeight)) {
            return $original;
        }

        $source = $this->canDecode((int) $size[0], (int) $size[1]) ? @imagecreatefromstring($bytes) : false;

        if ($source === false) {
            return $original;
        }

        $scale = min($maxWidth / $size[0], $maxHeight / $size[1]);
        $width = max(1, (int) round($size[0] * $scale));
        $height = max(1, (int) round($size[1] * $scale));

        $target = imagecreatetruecolor($width, $height);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, $size[0], $size[1]);

        // JPEG has no alpha, so it stays JPEG; everything else may be transparent and becomes PNG.
        $isJpeg = $mime === 'image/jpeg';

        ob_start();
        $isJpeg ? imagejpeg($target, null, 90) : imagepng($target);
        $resized = (string) ob_get_clean();

        return ['bytes' => $resized, 'mime' => $isJpeg ? 'image/jpeg' : 'image/png'];
    }

    /**
     * GD holds a decoded image at four bytes a pixel. Decoding past the memory limit is
     * a fatal error, not an exception, so check the room first.
     */
    private function canDecode(int $width, int $height): bool
    {
        $limit = $this->memoryLimitBytes();

        if ($limit < 0) {
            return true;
        }

        return $limit - memory_get_usage(true) > $width * $height * 5;
    }

    private function memoryLimitBytes(): int
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return -1;
        }

        $number = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
