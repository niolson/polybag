<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * On the hosted multi-tenant fleet, every install lives on its own
 * *.polybag.app subdomain, so there is no single "sign in to PolyBag" page.
 * When app.instance_cookie_domain is configured, remember which subdomain
 * this browser last logged into in a cookie scoped to that shared domain, so
 * the instance directory on polybag-connect (same cookie name, its own
 * host-only writer for manually-entered addresses) can offer a shortcut
 * back here.
 *
 * The cookie holds only a hostname, never a session token or credential — it
 * is a UX shortcut, not an authentication mechanism.
 */
class RememberInstanceForDirectory
{
    public function __construct(
        private readonly Request $request,
    ) {}

    public function handle(Login $event): void
    {
        $domain = config('app.instance_cookie_domain');

        if (! is_string($domain) || $domain === '') {
            return;
        }

        Cookie::queue(Cookie::forever(
            name: 'polybag_last_instance',
            value: $this->request->getHost(),
            domain: $domain,
            secure: true,
            httpOnly: true,
            sameSite: 'lax',
        ));
    }
}
