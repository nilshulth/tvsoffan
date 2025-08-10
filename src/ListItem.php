<?php

namespace App;

use PDO;

class ListItem extends BaseModel
{

    public function addToList(int $listId, int $titleId): bool
    {
        $existing = $this->findByListAndTitle($listId, $titleId);
        if ($existing) {
            return true; // Already in list
        }

        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO list_items (list_id, title_id) 
             VALUES (?, ?)"
        );
        
        return $stmt->execute([$listId, $titleId]);
    }


    public function findByListAndTitle(int $listId, int $titleId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM list_items WHERE list_id = ? AND title_id = ?"
        );
        $stmt->execute([$listId, $titleId]);
        return $stmt->fetch() ?: null;
    }

    public function getListItems(int $listId, ?string $state = null, ?int $userId = null): array
    {
        if ($state && $userId) {
            // Join with user_titles to filter by state
            $sql = "SELECT li.*, t.*, ut.state, ut.rating, ut.comment, ut.updated_at as user_updated_at
                    FROM list_items li
                    JOIN titles t ON li.title_id = t.id
                    LEFT JOIN user_titles ut ON t.id = ut.title_id AND ut.user_id = ?
                    WHERE li.list_id = ? AND (ut.state = ? OR ut.state IS NULL)
                    ORDER BY li.created_at DESC";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$userId, $listId, $state]);
        } else {
            // Just get list items with optional user state info
            $sql = "SELECT li.*, t.*";
            if ($userId) {
                $sql .= ", ut.state, ut.rating, ut.comment, ut.updated_at as user_updated_at";
            }
            $sql .= " FROM list_items li
                    JOIN titles t ON li.title_id = t.id";
            if ($userId) {
                $sql .= " LEFT JOIN user_titles ut ON t.id = ut.title_id AND ut.user_id = ?";
            }
            $sql .= " WHERE li.list_id = ?
                    ORDER BY li.created_at DESC";
            
            $params = $userId ? [$userId, $listId] : [$listId];
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
        }
        
        return $stmt->fetchAll();
    }




    public function removeFromList(int $listId, int $titleId): bool
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM list_items WHERE list_id = ? AND title_id = ?"
        );
        return $stmt->execute([$listId, $titleId]);
    }



}