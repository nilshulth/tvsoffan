<?php

namespace App;

use PDO;

class UserTitle extends BaseModel
{

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




}