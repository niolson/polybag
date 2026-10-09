<?php

namespace App\Support;

/**
 * The one test of whether FedEx is sent a recipient's email. Without one FedEx
 * cannot contact the recipient to collect duties, and the charges fall back to
 * the shipper (Ship API guide), so the adapter and the customs readiness
 * warning must agree on which emails count.
 */
final class FedexRecipientEmail
{
    /**
     * The longest `contact.emailAddress` FedEx accepts.
     */
    public const int MAX_LENGTH = 80;

    /**
     * The email as sent, or null when there is none or FedEx would not take it.
     */
    public static function usable(?string $email): ?string
    {
        $email = trim((string) $email);

        return $email !== '' && mb_strlen($email) <= self::MAX_LENGTH ? $email : null;
    }

    /**
     * Whether there is an email that is left out only because it is too long.
     */
    public static function isTooLong(?string $email): bool
    {
        return mb_strlen(trim((string) $email)) > self::MAX_LENGTH;
    }
}
