<?php

namespace App\Enums;

/**
 * What this install is for, resolved from APP_ENV. Decides whether carriers use their
 * sandbox and whether Dev Settings exist. An APP_ENV this does not know, such as a
 * typo or `staging`, is Production, so it fails closed.
 */
enum AppMode: string
{
    /** Real postage and real stores. Carrier sandbox is always off. */
    case Production = 'production';

    /** A sales demo or staging install. Carrier sandbox is always on. */
    case Demo = 'demo';

    /** Local development and the test suite. Sandbox is the Dev Settings toggle. */
    case Dev = 'dev';

    public static function current(): self
    {
        return match (app()->environment()) {
            'demo' => self::Demo,
            'local', 'testing' => self::Dev,
            default => self::Production,
        };
    }
}
