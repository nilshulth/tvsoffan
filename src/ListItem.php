<?php

namespace App;

use PDO;

class ListItem
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    public function addToList(int $listId, int $titleId, int $addedBy, string $state = 'want', ?int $rating = null, string $comment = ''): bool
    {
        $existing = $this->findByListAndTitle($listId, $titleId);
        if ($existing) {
            return $this->update($existing['id'], $state, $rating, $comment);
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO list_items (list_id, title_id, state, rating, comment, added_by) 
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        
        return $stmt->execute([$listId, $titleId, $state, $rating, $comment, $addedBy]);
    }

    public function update(int $listItemId, string $state, ?int $rating = null, string $comment = ''): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE list_items 
             SET state = ?, rating = ?, comment = ?, updated_at = CURRENT_TIMESTAMP 
             WHERE id = ?"
        );
        
        return $stmt->execute([$state, $rating, $comment, $listItemId]);
    }

    public function findByListAndTitle(int $listId, int $titleId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM list_items WHERE list_id = ? AND title_id = ?"
        );
        $stmt->execute([$listId, $titleId]);
        return $stmt->fetch() ?: null;
    }

    public function getListItems(int $listId, ?string $state = null): array
    {
        $sql = "SELECT li.*, t.*, u.name as added_by_name
                FROM list_items li
                JOIN titles t ON li.title_id = t.id
                JOIN users u ON li.added_by = u.id
                WHERE li.list_id = ?";
        
        $params = [$listId];
        
        if ($state) {
            $sql .= " AND li.state = ?";
            $params[] = $state;
        }
        
        $sql .= " ORDER BY li.created_at DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getUserTitleInList(int $userId, int $titleId, int $listId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT li.* FROM list_items li
             JOIN list_owners lo ON li.list_id = lo.list_id
             WHERE lo.user_id = ? AND li.title_id = ? AND li.list_id = ?"
        );
        $stmt->execute([$userId, $titleId, $listId]);
        return $stmt->fetch() ?: null;
    }

    public function getUserWatchedTitles(int $userId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT li.*, t.*, l.name as list_name
             FROM list_items li
             JOIN titles t ON li.title_id = t.id
             JOIN lists l ON li.list_id = l.id
             JOIN list_owners lo ON l.id = lo.list_id
             WHERE lo.user_id = ? AND li.state = 'watched'
             ORDER BY li.updated_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute([$userId, $limit, $offset]);
        return $stmt->fetchAll();
    }

    public function markAsWatched(int $titleId, int $userId, ?int $rating = null, string $comment = ''): bool
    {
        $listModel = new ListModel();
        $watchedList = $listModel->getUserWatchedList($userId);
        
        if (!$watchedList) {
            throw new \RuntimeException("User doesn't have a watched list");
        }

        return $this->addToList($watchedList['id'], $titleId, $userId, 'watched', $rating, $comment);
    }

    public function removeFromList(int $listId, int $titleId): bool
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM list_items WHERE list_id = ? AND title_id = ?"
        );
        return $stmt->execute([$listId, $titleId]);
    }

    public function delete(int $listItemId): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM list_items WHERE id = ?");
        return $stmt->execute([$listItemId]);
    }

    public function getStateCounts(int $listId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT state, COUNT(*) as count 
             FROM list_items 
             WHERE list_id = ? 
             GROUP BY state"
        );
        $stmt->execute([$listId]);
        
        $counts = ['want' => 0, 'watching' => 0, 'watched' => 0, 'stopped' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $counts[$row['state']] = (int)$row['count'];
        }
        
        return $counts;
    }

    public function getUserStats(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT li.state, COUNT(*) as count, AVG(li.rating) as avg_rating
             FROM list_items li
             JOIN list_owners lo ON li.list_id = lo.list_id
             WHERE lo.user_id = ?
             GROUP BY li.state"
        );
        $stmt->execute([$userId]);
        
        $stats = [
            'want' => ['count' => 0, 'avg_rating' => null],
            'watching' => ['count' => 0, 'avg_rating' => null],
            'watched' => ['count' => 0, 'avg_rating' => null],
            'stopped' => ['count' => 0, 'avg_rating' => null]
        ];
        
        foreach ($stmt->fetchAll() as $row) {
            $stats[$row['state']] = [
                'count' => (int)$row['count'],
                'avg_rating' => $row['avg_rating'] ? round((float)$row['avg_rating'], 1) : null
            ];
        }
        
        return $stats;
    }
}