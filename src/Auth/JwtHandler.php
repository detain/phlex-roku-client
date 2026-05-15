<?php

namespace Phlex\Auth;

class JwtHandler
{
    private string $secretKey;
    private string $algorithm;
    private int $ttl;
    private int $refreshTtl;

    public function __construct(
        string $secretKey,
        string $algorithm = 'HS256',
        int $ttl = 3600,
        int $refreshTtl = 604800
    ) {
        $this->secretKey = $secretKey;
        $this->algorithm = $algorithm;
        $this->ttl = $ttl;
        $this->refreshTtl = $refreshTtl;
    }

    public function createAccessToken(string $userId, array $claims = []): string
    {
        $now = time();
        $payload = array_merge($claims, [
            'iss' => 'phlex',
            'sub' => $userId,
            'iat' => $now,
            'exp' => $now + $this->ttl,
            'type' => 'access',
        ]);

        return $this->encode($payload);
    }

    public function createRefreshToken(string $userId): string
    {
        $now = time();
        $payload = [
            'iss' => 'phlex',
            'sub' => $userId,
            'iat' => $now,
            'exp' => $now + $this->refreshTtl,
            'type' => 'refresh',
            'jti' => bin2hex(random_bytes(16)),
        ];

        return $this->encode($payload);
    }

    public function validateToken(string $token): ?array
    {
        try {
            $payload = $this->decode($token);

            // Verify expiration
            if (isset($payload['exp']) && $payload['exp'] < time()) {
                return null;
            }

            // Verify issuer
            if (($payload['iss'] ?? '') !== 'phlex') {
                return null;
            }

            return $payload;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getUserIdFromToken(string $token): ?string
    {
        $payload = $this->validateToken($token);
        return $payload['sub'] ?? null;
    }

    public function isAccessToken(string $token): bool
    {
        $payload = $this->validateToken($token);
        return ($payload['type'] ?? '') === 'access';
    }

    public function isRefreshToken(string $token): bool
    {
        $payload = $this->validateToken($token);
        return ($payload['type'] ?? '') === 'refresh';
    }

    private function encode(array $payload): string
    {
        $header = ['alg' => $this->algorithm, 'typ' => 'JWT'];
        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));

        $signature = hash_hmac(
            'sha256',
            "{$headerEncoded}.{$payloadEncoded}",
            $this->secretKey,
            true
        );
        $signatureEncoded = $this->base64UrlEncode($signature);

        return "{$headerEncoded}.{$payloadEncoded}.{$signatureEncoded}";
    }

    private function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \InvalidArgumentException('Invalid token format');
        }

        [$headerEncoded, $payloadEncoded, $signature] = $parts;

        // Verify signature
        $expectedSignature = $this->base64UrlEncode(
            hash_hmac('sha256', "{$headerEncoded}.{$payloadEncoded}", $this->secretKey, true)
        );

        if (!hash_equals($expectedSignature, $signature)) {
            throw new \InvalidArgumentException('Invalid signature');
        }

        $header = json_decode($this->base64UrlDecode($headerEncoded), true);
        $payload = json_decode($this->base64UrlDecode($payloadEncoded), true);

        return $payload;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}