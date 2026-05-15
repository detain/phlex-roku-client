<?php

namespace Phlex\Auth;

use Workerman\MySQL\Connection;

class UserRepository
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function findById(string $id): ?array
    {
        $result = $this->db->query("SELECT * FROM users WHERE id = ?", [$id]);
        return $result[0] ?? null;
    }

    public function findByUsername(string $username): ?array
    {
        $result = $this->db->query(
            "SELECT * FROM users WHERE username = ?",
            [$username]
        );
        return $result[0] ?? null;
    }

    public function findByEmail(string $email): ?array
    {
        $result = $this->db->query(
            "SELECT * FROM users WHERE email = ?",
            [$email]
        );
        return $result[0] ?? null;
    }

    public function create(array $data): string
    {
        $id = $this->generateUuid();
        $passwordHash = password_hash($data['password'], PASSWORD_ARGON2ID);

        $this->db->query(
            "INSERT INTO users (id, username, email, password_hash, display_name) VALUES (?, ?, ?, ?, ?)",
            [
                $id,
                $data['username'],
                $data['email'],
                $passwordHash,
                $data['display_name'] ?? $data['username'],
            ]
        );

        // Create default settings
        $this->db->query(
            "INSERT INTO user_settings (user_id) VALUES (?)",
            [$id]
        );

        return $id;
    }

    public function update(string $id, array $data): void
    {
        $sets = [];
        $values = [];

        if (isset($data['display_name'])) {
            $sets[] = 'display_name = ?';
            $values[] = $data['display_name'];
        }

        if (isset($data['email'])) {
            $sets[] = 'email = ?';
            $values[] = $data['email'];
        }

        if (isset($data['password'])) {
            $sets[] = 'password_hash = ?';
            $values[] = password_hash($data['password'], PASSWORD_ARGON2ID);
        }

        if (empty($sets)) {
            return;
        }

        $values[] = $id;
        $this->db->query(
            "UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?",
            $values
        );
    }

    public function updateLastLogin(string $id): void
    {
        $this->db->query("UPDATE users SET last_login = NOW() WHERE id = ?", [$id]);
    }

    public function verifyPassword(string $id, string $password): bool
    {
        $user = $this->findById($id);
        if (!$user) {
            return false;
        }

        return password_verify($password, $user['password_hash']);
    }

    public function emailExists(string $email): bool
    {
        $result = $this->db->query(
            "SELECT 1 FROM users WHERE email = ?",
            [$email]
        );
        return !empty($result);
    }

    public function usernameExists(string $username): bool
    {
        $result = $this->db->query(
            "SELECT 1 FROM users WHERE username = ?",
            [$username]
        );
        return !empty($result);
    }

    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}