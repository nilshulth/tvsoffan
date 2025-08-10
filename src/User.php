<?php

namespace App;

use PDO;

class User extends BaseModel
{

    public function register(string $email, string $password, string $name): bool
    {
        if ($this->emailExists($email)) {
            return false;
        }

        try {
            $this->pdo->beginTransaction();

            $passwordHash = password_hash($password, PASSWORD_ARGON2ID);

            $stmt = $this->pdo->prepare(
                "INSERT INTO users (email, password_hash, name) VALUES (?, ?, ?)"
            );
            $stmt->execute([$email, $passwordHash, $name]);
            
            $userId = $this->pdo->lastInsertId();

            $this->createDefaultLists($userId);

            $this->pdo->commit();
            return true;

        } catch (\Exception $e) {
            $this->pdo->rollback();
            error_log("User registration failed: " . $e->getMessage());
            return false;
        }
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

    private function createDefaultLists(int $userId): void
    {
        $listModel = new ListModel();
        
        // Create watched list (automatic)
        $watchedListId = $listModel->create("Sett", $userId, "Automatisk lista över allt du har sett", 'public', false);
        error_log("Created watched list with ID: " . $watchedListId);
        
        // Create default list (user's main list)
        $defaultListId = $listModel->create("Min lista", $userId, "Din personliga lista", 'private', true);
        error_log("Created default list with ID: " . $defaultListId);
    }
}