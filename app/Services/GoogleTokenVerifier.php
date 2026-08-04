<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
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
