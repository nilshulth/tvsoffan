<?php

namespace App;

use PDO;

class UserTitle
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    public function setState(int $userId, int $titleId, string $state, ?int $rating = null, string $comment = ''): bool
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO user_titles (user_id, title_id, state, rating, comment) 
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE 
             state = VALUES(state), 
             rating = VALUES(rating), 
             comment = VALUES(comment),
             updated_at = CURRENT_TIMESTAMP"
        );
        
        return $stmt->execute([$userId, $titleId, $state, $rating, $comment]);
    }

    public function getUserTitleState(int $userId, int $titleId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM user_titles WHERE user_id = ? AND title_id = ?"
        );
        $stmt->execute([$userId, $titleId]);
        return $stmt->fetch() ?: null;
    }

    public function getUserTitlesByState(int $userId, string $state, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT ut.*, t.*, ut.created_at as user_created_at, ut.updated_at as user_updated_at
             FROM user_titles ut
             JOIN titles t ON ut.title_id = t.id
             WHERE ut.user_id = ? AND ut.state = ?
             ORDER BY ut.updated_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute([$userId, $state, $limit, $offset]);
        return $stmt->fetchAll();
    }

    public function getUserStats(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT state, COUNT(*) as count, AVG(rating) as avg_rating
             FROM user_titles
             WHERE user_id = ?
             GROUP BY state"
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

    public function removeUserTitle(int $userId, int $titleId): bool
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM user_titles WHERE user_id = ? AND title_id = ?"
        );
        return $stmt->execute([$userId, $titleId]);
    }

    public function getUserRecentActivity(int $userId, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT ut.*, t.*
             FROM user_titles ut
             JOIN titles t ON ut.title_id = t.id
             WHERE ut.user_id = ?
             ORDER BY ut.updated_at DESC
             LIMIT ?"
        );
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    }

    // Helper method to get watched titles (replaces the need for watched lists)
    public function getUserWatchedTitles(int $userId, int $limit = 50, int $offset = 0): array
    {
        return $this->getUserTitlesByState($userId, 'watched', $limit, $offset);
    }
}