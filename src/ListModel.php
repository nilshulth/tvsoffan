<?php

namespace App;

use PDO;

class ListModel
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    public function create(string $name, int $createdBy, string $description = '', string $visibility = 'private', bool $isDefault = false, bool $isWatchedList = false): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO lists (name, description, visibility, is_default, is_watched_list, created_by) 
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        
        $stmt->execute([$name, $description, $visibility, $isDefault ? 1 : 0, $isWatchedList ? 1 : 0, $createdBy]);
        $listId = $this->pdo->lastInsertId();

        $this->addOwner($listId, $createdBy);

        return $listId;
    }

    public function getUserLists(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT l.*, 
                    (SELECT COUNT(*) FROM list_items li WHERE li.list_id = l.id) as item_count
             FROM lists l
             JOIN list_owners lo ON l.id = lo.list_id
             WHERE lo.user_id = ?
             ORDER BY l.is_watched_list DESC, l.is_default DESC, l.created_at DESC"
        );
        
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function findById(int $listId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT l.*, 
                    u.name as created_by_name,
                    (SELECT COUNT(*) FROM list_items li WHERE li.list_id = l.id) as item_count
             FROM lists l
             JOIN users u ON l.created_by = u.id
             WHERE l.id = ?"
        );
        
        $stmt->execute([$listId]);
        $list = $stmt->fetch();

        if ($list) {
            $list['owners'] = $this->getListOwners($listId);
        }

        return $list ?: null;
    }

    public function getUserWatchedList(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT l.* FROM lists l
             JOIN list_owners lo ON l.id = lo.list_id
             WHERE lo.user_id = ? AND l.is_watched_list = TRUE
             LIMIT 1"
        );
        
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    public function getUserDefaultList(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT l.* FROM lists l
             JOIN list_owners lo ON l.id = lo.list_id
             WHERE lo.user_id = ? AND l.is_default = TRUE AND l.is_watched_list = FALSE
             LIMIT 1"
        );
        
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    public function isOwner(int $listId, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM list_owners WHERE list_id = ? AND user_id = ?"
        );
        $stmt->execute([$listId, $userId]);
        return $stmt->fetch() !== false;
    }

    public function canUserAccess(int $listId, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT l.visibility FROM lists l
             LEFT JOIN list_owners lo ON l.id = lo.list_id AND lo.user_id = ?
             WHERE l.id = ? AND (l.visibility = 'public' OR lo.user_id IS NOT NULL)"
        );
        $stmt->execute([$userId, $listId]);
        return $stmt->fetch() !== false;
    }

    public function addOwner(int $listId, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO list_owners (list_id, user_id) VALUES (?, ?)"
        );
        return $stmt->execute([$listId, $userId]);
    }

    public function removeOwner(int $listId, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM list_owners WHERE list_id = ? AND user_id = ?"
        );
        return $stmt->execute([$listId, $userId]);
    }

    public function getListOwners(int $listId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT u.id, u.name, u.email FROM users u
             JOIN list_owners lo ON u.id = lo.user_id
             WHERE lo.list_id = ?
             ORDER BY u.name"
        );
        $stmt->execute([$listId]);
        return $stmt->fetchAll();
    }

    public function updateVisibility(int $listId, string $visibility): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE lists SET visibility = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?"
        );
        return $stmt->execute([$visibility, $listId]);
    }

    public function update(int $listId, string $name, string $description = ''): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE lists SET name = ?, description = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?"
        );
        return $stmt->execute([$name, $description, $listId]);
    }

    public function delete(int $listId): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM lists WHERE id = ?");
        return $stmt->execute([$listId]);
    }
}