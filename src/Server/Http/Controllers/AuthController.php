<?php

namespace Phlex\Server\Http\Controllers;

use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;
use Phlex\Auth\AuthManager;

class AuthController
{
    private AuthManager $authManager;

    public function __construct(AuthManager $authManager)
    {
        $this->authManager = $authManager;
    }

    public function register(Request $request, array $params): Response
    {
        $data = $request->body;

        if (empty($data['username']) || empty($data['email']) || empty($data['password'])) {
            return (new Response())->status(400)->json([
                'error' => 'Missing required fields: username, email, password',
            ]);
        }

        try {
            $result = $this->authManager->register(
                $data['username'],
                $data['email'],
                $data['password']
            );
            return (new Response())->status(201)->json($result);
        } catch (\InvalidArgumentException $e) {
            return (new Response())->status(400)->json(['error' => $e->getMessage()]);
        }
    }

    public function login(Request $request, array $params): Response
    {
        $data = $request->body;

        if (empty($data['username']) || empty($data['password'])) {
            return (new Response())->status(400)->json([
                'error' => 'Missing required fields: username, password',
            ]);
        }

        $deviceId = $request->getHeader('X-Device-Id') ?? 'unknown';

        try {
            $result = $this->authManager->login(
                $data['username'],
                $data['password'],
                $deviceId
            );
            return (new Response())->json($result);
        } catch (\InvalidArgumentException $e) {
            return (new Response())->status(401)->json(['error' => $e->getMessage()]);
        }
    }

    public function refresh(Request $request, array $params): Response
    {
        $data = $request->body;

        if (empty($data['refresh_token'])) {
            return (new Response())->status(400)->json([
                'error' => 'refresh_token is required',
            ]);
        }

        try {
            $result = $this->authManager->refreshToken($data['refresh_token']);
            return (new Response())->json($result);
        } catch (\InvalidArgumentException $e) {
            return (new Response())->status(401)->json(['error' => $e->getMessage()]);
        }
    }

    public function me(Request $request, array $params): Response
    {
        $userId = $request->userId ?? null;
        if (!$userId) {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        $user = $this->authManager->getUser($userId);
        if (!$user) {
            return (new Response())->status(404)->json(['error' => 'User not found']);
        }

        return (new Response())->json(['user' => $user]);
    }
}