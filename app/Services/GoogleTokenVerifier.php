<?php

namespace App\Services;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Verifies a Google-issued ID token locally, without a synchronous call to
 * Google's /tokeninfo endpoint on every sign-in (audit F-High-4). tokeninfo
 * is explicitly documented by Google as a debugging tool, is rate-limited,
 * and puts a third-party HTTP round-trip in the login critical path.
 *
 * Requires: composer require firebase/php-jwt
 */
class GoogleTokenVerifier
{
    private const CERTS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
    private const CACHE_KEY = 'google:jwks';
    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    // Audit VAL-3: firebase/php-jwt ^6.0's Key objects already carry their
    // own algorithm restriction, so this loop is a no-op on that version —
    // but it closes the gap outright on any older 5.x install where a bare
    // key array could be alg-confused. Cheap, explicit, version-independent.
    private const ALLOWED_ALGS = ['RS256'];

    /**
     * @return array<string, mixed> the decoded claims
     * @throws \RuntimeException on any signature, expiry, issuer, or
     *         audience failure — callers should treat any exception as
     *         "reject the token", not attempt to inspect partial claims.
     */
    public function verify(string $idToken, ?string $expectedClientId): array
    {
        if (! $expectedClientId) {
            throw new \RuntimeException('Google client ID is not configured.');
        }

        $keys = JWK::parseKeySet($this->fetchJwks());

        foreach ($keys as $kid => $key) {
            if ($key instanceof Key && ! in_array($key->getAlgorithm(), self::ALLOWED_ALGS, true)) {
                unset($keys[$kid]);
            }
        }

        if (empty($keys)) {
            throw new \RuntimeException('No usable signing keys after algorithm filtering.');
        }

        // firebase/php-jwt validates signature + exp/nbf/iat automatically.
        $decoded = (array) JWT::decode($idToken, $keys);

        if (! in_array($decoded['iss'] ?? null, self::ISSUERS, true)) {
            throw new \RuntimeException('Unexpected token issuer.');
        }

        if (($decoded['aud'] ?? null) !== $expectedClientId) {
            throw new \RuntimeException('Token audience does not match configured client ID.');
        }

        return [
            'email' => $decoded['email'] ?? null,
            'email_verified' => filter_var($decoded['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'name' => $decoded['name'] ?? null,
            'sub' => $decoded['sub'] ?? null,
        ];
    }

    /**
     * Google rotates signing keys periodically; cache the JWKS response
     * respecting its own Cache-Control max-age where possible, falling back
     * to a conservative 1-hour TTL. Avoids a network call on every sign-in
     * while still picking up rotations reasonably quickly.
     */
    private function fetchJwks(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addHour(), function () {
            $response = Http::timeout(5)->get(self::CERTS_URL);

            if ($response->failed()) {
                throw new \RuntimeException('Could not fetch Google signing keys.');
            }

            return $response->json();
        });
    }
}
