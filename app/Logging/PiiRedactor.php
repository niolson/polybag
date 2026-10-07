<?php

namespace App\Logging;

/**
 * Shared PII redaction used by both the Sentry event scrubber and the
 * carrier API log channels. Any key that looks like a recipient field is
 * replaced with a fixed marker before the data leaves the application.
 */
class PiiRedactor
{
    private const REDACTED = '[REDACTED]';

    private const PII_KEY_PATTERN = '/first_?name|last_?name|person_?name|full_?name|^name$|company|email|phone|street|address|city|postal|zip_?code|recipient|contact|tax_?id|^tins$|^encodedLabel$|^graphicImage$/i';

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::PII_KEY_PATTERN, $key)) {
                $data[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }
}
