<?php

namespace App;

use PDO;

class Title
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    public function createFromTmdb(array $tmdbData): int
    {
        $mediaType = $tmdbData['media_type'] ?? 'movie';
        $tmdbId = $tmdbData['id'];
        $title = $tmdbData['title'] ?? $tmdbData['name'] ?? '';
        $originalTitle = $tmdbData['original_title'] ?? $tmdbData['original_name'] ?? '';
        $releaseDate = $tmdbData['release_date'] ?? $tmdbData['first_air_date'] ?? null;
        $posterPath = $tmdbData['poster_path'] ?? null;
        $overview = $tmdbData['overview'] ?? '';

        $existingTitle = $this->findByTmdbId($tmdbId, $mediaType);
        if ($existingTitle) {
            return $existingTitle['id'];
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO titles (tmdb_id, media_type, title, original_title, release_date, poster_path, overview) 
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        
        $stmt->execute([
            $tmdbId,
            $mediaType,
            $title,
            $originalTitle,
            $releaseDate,
            $posterPath,
            $overview
        ]);

        return $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM titles WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findByTmdbId(int $tmdbId, string $mediaType): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM titles WHERE tmdb_id = ? AND media_type = ?"
        );
        $stmt->execute([$tmdbId, $mediaType]);
        return $stmt->fetch() ?: null;
    }

    public function update(int $id, array $data): bool
    {
        $allowedFields = ['title', 'original_title', 'release_date', 'poster_path', 'overview'];
        $updates = [];
        $values = [];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "{$field} = ?";
                $values[] = $data[$field];
            }
        }

        if (empty($updates)) {
            return true;
        }

        $updates[] = 'updated_at = CURRENT_TIMESTAMP';
        $values[] = $id;

        $sql = "UPDATE titles SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        
        return $stmt->execute($values);
    }

    public function search(string $query, int $limit = 20): array
    {
        $searchTerm = "%{$query}%";
        $stmt = $this->pdo->prepare(
            "SELECT * FROM titles 
             WHERE title LIKE ? OR original_title LIKE ?
             ORDER BY 
                CASE WHEN title LIKE ? THEN 1 ELSE 2 END,
                title
             LIMIT ?"
        );
        
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $limit]);
        return $stmt->fetchAll();
    }

    public function getPopular(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT t.*, COUNT(li.id) as usage_count
             FROM titles t
             LEFT JOIN list_items li ON t.id = li.title_id
             GROUP BY t.id
             ORDER BY usage_count DESC, t.created_at DESC
             LIMIT ?"
        );
        
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM titles WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function getImageUrl(?string $posterPath, string $size = 'w500'): ?string
    {
        if (empty($posterPath)) {
            return null;
        }
        return "https://image.tmdb.org/t/p/{$size}{$posterPath}";
    }
}