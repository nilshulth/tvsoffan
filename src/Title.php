<?php

namespace App;

use PDO;

class Title extends BaseModel
{

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




}