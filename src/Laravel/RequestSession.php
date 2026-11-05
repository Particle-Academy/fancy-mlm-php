<?php

declare(strict_types=1);

namespace FancyMlm\Laravel;

use Illuminate\Http\Request;

/**
 * The session identifier the Laravel helpers hash.
 *
 * NOT the Laravel session id: `Auth::login()` migrates that to a new value, so
 * a visitor who scans, then registers (and is logged in), would arrive at
 * attribution with a different id from the one that scanned. Instead a random
 * 128-bit token is kept IN the session, which survives the migration. It is
 * never stored by this package; only its keyed hash is.
 *
 * Needs a started session. On a stateless route, pass your own opaque visitor
 * id to `Mlm::recordScan()` / `Mlm::attributeSignup()` instead.
 */
final class RequestSession
{
    public const KEY = 'mlm.referral_session';

    public static function id(Request $request): string
    {
        $session = $request->session();
        $id = $session->get(self::KEY);

        if (! is_string($id) || strlen($id) !== 32 || ! ctype_xdigit($id)) {
            $id = bin2hex(random_bytes(16));
            $session->put(self::KEY, $id);
        }

        return $id;
    }
}
