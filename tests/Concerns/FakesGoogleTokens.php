<?php

namespace Tests\Concerns;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

trait FakesGoogleTokens
{
    private static ?string $googlePrivateKey = null;
    private static array $googlePublicKeyJwk = [];
    private const GOOGLE_TEST_KID = 'test-key-1';

    protected function setUpGoogleTokenFaking(): void
    {
        // GoogleTokenVerifier caches the JWKS response for an hour — must
        // clear it between tests or one test's mocked keys leak into the
        // next and cause spurious verification failures/passes.
        Cache::forget('google:jwks');

        if (self::$googlePrivateKey === null) {
            $res = openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);
            openssl_pkey_export($res, self::$googlePrivateKey);
            $details = openssl_pkey_get_details($res);

            self::$googlePublicKeyJwk = [
                'kty' => 'RSA',
                'kid' => self::GOOGLE_TEST_KID,
                'use' => 'sig',
                'alg' => 'RS256',
                'n' => $this->googleBase64url($details['rsa']['n']),
                'e' => $this->googleBase64url($details['rsa']['e']),
            ];
        }

        Http::fake([
            'www.googleapis.com/oauth2/v3/certs' => Http::response([
                'keys' => [self::$googlePublicKeyJwk],
            ], 200),
        ]);
    }

    private function googleBase64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Builds a genuinely-signed, verifiable test ID token. Override
     * $audience/$expiresInSeconds to deliberately produce a token that
     * SHOULD fail verification (wrong audience, expired), rather than
     * relying on a mocked HTTP response the real verifier never calls.
     */
    protected function fakeGoogleIdToken(
        string $email,
        string $name,
        bool $verified = true,
        ?string $audience = null,
        int $expiresInSeconds = 3600,
    ): string {
        $payload = [
            'iss' => 'accounts.google.com',
            'aud' => $audience ?? config('services.google.client_id'),
            'email' => $email,
            'email_verified' => $verified,
            'name' => $name,
            'sub' => 'test-sub-' . md5($email),
            'iat' => time(),
            'exp' => time() + $expiresInSeconds,
        ];

        return JWT::encode($payload, self::$googlePrivateKey, 'RS256', self::GOOGLE_TEST_KID);
    }
}