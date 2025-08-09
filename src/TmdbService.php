<?php

namespace App;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class TmdbService
{
    private Client $client;
    private string $apiKey;
    private const BASE_URL = 'https://api.themoviedb.org/3/';

    public function __construct()
    {
        $this->apiKey = $_ENV['TMDB_API_KEY'];
        $this->client = new Client([
            'base_uri' => self::BASE_URL,
            'timeout' => 10,
        ]);
    }

    public function searchMovies(string $query, int $page = 1): array
    {
        try {
            $response = $this->client->get('search/movie', [
                'query' => [
                    'api_key' => $this->apiKey,
                    'query' => $query,
                    'page' => $page,
                    'language' => 'sv-SE',
                ]
            ]);

            $data = json_decode($response->getBody(), true);
            return $data ?: [];
        } catch (GuzzleException $e) {
            error_log("TMDB API error: " . $e->getMessage());
            return [];
        }
    }

    public function searchTvShows(string $query, int $page = 1): array
    {
        try {
            $response = $this->client->get('search/tv', [
                'query' => [
                    'api_key' => $this->apiKey,
                    'query' => $query,
                    'page' => $page,
                    'language' => 'sv-SE',
                ]
            ]);

            $data = json_decode($response->getBody(), true);
            return $data ?: [];
        } catch (GuzzleException $e) {
            error_log("TMDB API error: " . $e->getMessage());
            return [];
        }
    }

    public function searchMulti(string $query, int $page = 1): array
    {
        try {
            $response = $this->client->get('search/multi', [
                'query' => [
                    'api_key' => $this->apiKey,
                    'query' => $query,
                    'page' => $page,
                    'language' => 'sv-SE',
                ]
            ]);

            $data = json_decode($response->getBody(), true);
            return $data ?: [];
        } catch (GuzzleException $e) {
            error_log("TMDB API error: " . $e->getMessage());
            return [];
        }
    }

    public function getMovieDetails(int $movieId): ?array
    {
        try {
            $response = $this->client->get("movie/{$movieId}", [
                'query' => [
                    'api_key' => $this->apiKey,
                    'language' => 'sv-SE',
                ]
            ]);

            $data = json_decode($response->getBody(), true);
            return $data ?: null;
        } catch (GuzzleException $e) {
            error_log("TMDB API error: " . $e->getMessage());
            return null;
        }
    }

    public function getTvShowDetails(int $tvId): ?array
    {
        try {
            $response = $this->client->get("tv/{$tvId}", [
                'query' => [
                    'api_key' => $this->apiKey,
                    'language' => 'sv-SE',
                ]
            ]);

            $data = json_decode($response->getBody(), true);
            return $data ?: null;
        } catch (GuzzleException $e) {
            error_log("TMDB API error: " . $e->getMessage());
            return null;
        }
    }

    public function getImageUrl(string $posterPath, string $size = 'w500'): string
    {
        return "https://image.tmdb.org/t/p/{$size}{$posterPath}";
    }
}