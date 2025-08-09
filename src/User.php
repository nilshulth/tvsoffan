<?php

namespace App;

use PDO;

class User
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    public function register(string $email, string $password, string $name): bool
    {
        if ($this->emailExists($email)) {
            return false;
        }

        $passwordHash = password_hash($password, PASSWORD_ARGON2ID);

        $stmt = $this->pdo->prepare(
            "INSERT INTO users (email, password_hash, name) VALUES (?, ?, ?)"
        );

        return $stmt->execute([$email, $passwordHash, $name]);
    }

    public function login(string $email, string $password): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, email, password_hash, name, is_public FROM users WHERE email = ?"
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            unset($user['password_hash']);
            return $user;
        }

        return null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, email, name, is_public, created_at FROM users WHERE id = ?"
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private function emailExists(string $email): bool
    {
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        return $stmt->fetch() !== false;
    }
}