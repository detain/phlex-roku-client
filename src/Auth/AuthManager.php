<?php

namespace Phlex\Auth;

use Phlex\Common\Logger\AuditLogger;
use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\StructuredLogger;

class AuthManager
{
    private UserRepository $userRepository;
    private JwtHandler $jwtHandler;
    private AuditLogger $auditLogger;
    private StructuredLogger $logger;

    public function __construct(
        UserRepository $userRepository,
        JwtHandler $jwtHandler,
        AuditLogger $auditLogger,
        ?StructuredLogger $logger = null
    ) {
        $this->userRepository = $userRepository;
        $this->jwtHandler = $jwtHandler;
        $this->auditLogger = $auditLogger;
        $this->logger = $logger ?? $this->createDefaultLogger();
    }

    private function createDefaultLogger(): StructuredLogger
    {
        $tempDir = sys_get_temp_dir() . '/phlex_auth_' . uniqid();
        mkdir($tempDir, 0755, true);

        $config = [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => $tempDir . '/auth.log',
                    'level' => 'debug',
                ],
            ],
            'processors' => [
                'context' => true,
                'request_id' => false,
                'user_id' => false,
            ],
        ];

        return new StructuredLogger(LogChannels::AUTH, $config);
    }

    public function register(string $username, string $email, string $password): array
    {
        // Validate
        if (strlen($username) < 3 || strlen($username) > 50) {
            throw new \InvalidArgumentException('Username must be 3-50 characters');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email format');
        }

        if (strlen($password) < 8) {
            throw new \InvalidArgumentException('Password must be at least 8 characters');
        }

        // Check uniqueness
        if ($this->userRepository->usernameExists($username)) {
            throw new \InvalidArgumentException('Username already taken');
        }

        if ($this->userRepository->emailExists($email)) {
            throw new \InvalidArgumentException('Email already registered');
        }

        // Create user
        $userId = $this->userRepository->create([
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'display_name' => $username,
        ]);

        $this->logger->info('User registered', ['user_id' => $userId, 'username' => $username]);

        return $this->createAuthResponse($userId);
    }

    public function login(string $username, string $password, string $deviceId): array
    {
        $user = $this->userRepository->findByUsername($username);

        if (!$user || !$this->userRepository->verifyPassword($user['id'], $password)) {
            $this->auditLogger->logFailedAuth('invalid_credentials', [
                'username' => $username,
                'device_id' => $deviceId,
            ]);
            throw new \InvalidArgumentException('Invalid username or password');
        }

        // Update last login
        $this->userRepository->updateLastLogin($user['id']);

        $this->auditLogger->logLogin($user['id'], $deviceId, true);

        $this->logger->info('User logged in', ['user_id' => $user['id'], 'device_id' => $deviceId]);

        return $this->createAuthResponse($user['id']);
    }

    public function refreshToken(string $refreshToken): array
    {
        if (!$this->jwtHandler->isRefreshToken($refreshToken)) {
            throw new \InvalidArgumentException('Invalid refresh token');
        }

        $payload = $this->jwtHandler->validateToken($refreshToken);
        if (!$payload) {
            throw new \InvalidArgumentException('Expired refresh token');
        }

        $userId = $payload['sub'];

        return $this->createAuthResponse($userId);
    }

    public function validateAccessToken(string $token): ?array
    {
        if (!$this->jwtHandler->isAccessToken($token)) {
            return null;
        }

        $payload = $this->jwtHandler->validateToken($token);
        if (!$payload) {
            return null;
        }

        return [
            'user_id' => $payload['sub'],
            'expires_at' => $payload['exp'],
        ];
    }

    public function getUser(string $userId): ?array
    {
        $user = $this->userRepository->findById($userId);
        if (!$user) {
            return null;
        }

        unset($user['password_hash']);
        return $user;
    }

    private function createAuthResponse(string $userId): array
    {
        $accessToken = $this->jwtHandler->createAccessToken($userId);
        $refreshToken = $this->jwtHandler->createRefreshToken($userId);
        $user = $this->getUser($userId);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'user' => $user,
        ];
    }
}