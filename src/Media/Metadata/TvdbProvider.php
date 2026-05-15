<?php

declare(strict_types=1);

namespace Phlex\Media\Metadata;

/**
 * TVDB API provider for TV series metadata.
 * 
 * @see https://api.thetvdb.com/
 */
class TvdbProvider implements MetadataProviderInterface
{
    private MetadataHttpClient $http;
    private string $language;
    private array $cache = [];

    public function __construct(string $apiKey, string $language = 'eng')
    {
        $this->http = new MetadataHttpClient(
            'https://api.thetvdb.com',
            $apiKey
        );
        $this->language = $language;
    }

    public function search(string $query, array $options = []): array
    {
        $language = $options['language'] ?? $this->language;
        
        $params = [
            'name' => $query,
            'language' => $language,
        ];

        $response = $this->http->get('/search/series', $params);

        if (!$response || !isset($response['data'])) {
            return [];
        }

        return array_map(function ($result) {
            return [
                'id' => (string) ($result['id'] ?? ''),
                'title' => $result['seriesName'] ?? $result['alias'] ?? '',
                'original_title' => $result['seriesName'] ?? '',
                'overview' => $result['overview'] ?? '',
                'poster_path' => $result['poster'] ?? null,
                'banner_path' => $result['banner'] ?? null,
                'first_aired' => $result['firstAired'] ?? '',
                'network' => $result['network'] ?? null,
                'status' => $result['status'] ?? null,
                'rating' => $result['siteRating'] ?? null,
            ];
        }, $response['data']);
    }

    public function getDetails(string $externalId, array $options = []): array
    {
        $language = $options['language'] ?? $this->language;
        
        $params = [
            'language' => $language,
        ];

        // Fetch series details
        $seriesResponse = $this->http->get("/series/{$externalId}", $params);
        
        if (!$seriesResponse || !isset($seriesResponse['data'])) {
            return [];
        }

        $series = $seriesResponse['data'];
        
        // Fetch actors
        $actorsResponse = $this->http->get("/series/{$externalId}/actors", $params);
        $actors = $this->formatActors($actorsResponse['data'] ?? []);
        
        // Fetch episodes
        $episodesResponse = $this->http->get("/series/{$externalId}/episodes", $params);
        $episodes = $this->formatEpisodes($episodesResponse['data'] ?? []);

        return [
            'name' => $series['seriesName'] ?? '',
            'original_name' => $series['seriesName'] ?? '',
            'overview' => $series['overview'] ?? '',
            'year' => isset($series['firstAired']) ? date('Y', strtotime($series['firstAired'])) : null,
            'first_aired' => $series['firstAired'] ?? null,
            'network' => $series['network'] ?? null,
            'genre' => $this->parseGenres($series['genre'] ?? ''),
            'rating' => $series['siteRating'] ?? null,
            'runtime' => $series['runtime'] ?? null,
            'status' => $series['status'] ?? null,
            'imdb_id' => $series['imdbId'] ?? null,
            'tvdb_id' => $series['id'] ?? null,
            'actors' => $actors,
            'episodes' => $episodes,
            'episode_count' => count($episodes),
            'season_count' => $this->countSeasons($episodes),
        ];
    }

    public function getImages(string $externalId): array
    {
        $params = ['keyType' => 'poster'];
        
        // Get posters
        $posterResponse = $this->http->get("/series/{$externalId}/images/query", array_merge($params, ['keyType' => 'poster']));
        $posters = $this->formatImages($posterResponse['data'] ?? [], 'poster');

        // Get banners
        $bannerResponse = $this->http->get("/series/{$externalId}/images/query", array_merge($params, ['keyType' => 'series']));
        $banners = $this->formatImages($bannerResponse['data'] ?? [], 'banner');

        // Get season posters
        $seasonPosterResponse = $this->http->get("/series/{$externalId}/images/query", array_merge($params, ['keyType' => 'season']));
        $seasonPosters = $this->formatImages($seasonPosterResponse['data'] ?? [], 'season_poster');

        // Get season thumbs
        $seasonThumbResponse = $this->http->get("/series/{$externalId}/images/query", array_merge($params, ['keyType' => 'seasonwide']));
        $seasonThumbs = $this->formatImages($seasonThumbResponse['data'] ?? [], 'season_thumb');

        return [
            'posters' => $posters,
            'banners' => $banners,
            'season_posters' => $seasonPosters,
            'season_thumbs' => $seasonThumbs,
        ];
    }

    public function getProviders(): array
    {
        return ['tvdb', 'thetvdb'];
    }

    /**
     * Get episode details by series ID and season/episode number.
     */
    public function getEpisode(string $seriesId, int $season, int $episode, array $options = []): array
    {
        $language = $options['language'] ?? $this->language;
        
        $params = ['language' => $language];
        
        // First get all episodes to find the right one
        $response = $this->http->get("/series/{$seriesId}/episodes/query", array_merge($params, [
            'airedSeason' => $season,
            'airedEpisode' => $episode,
        ]));

        if (!$response || !isset($response['data']) || empty($response['data'])) {
            return [];
        }

        $episodeData = $response['data'][0];
        
        return [
            'id' => (string) ($episodeData['id'] ?? ''),
            'series_id' => $seriesId,
            'name' => $episodeData['episodeName'] ?? '',
            'overview' => $episodeData['overview'] ?? '',
            'season_number' => $episodeData['airedSeason'] ?? $season,
            'episode_number' => $episodeData['airedEpisodeNumber'] ?? $episode,
            'first_aired' => $episodeData['firstAired'] ?? null,
            'runtime' => $episodeData['runtime'] ?? null,
            'rating' => $episodeData['siteRating'] ?? null,
            'thumbnail' => $episodeData['filename'] ?? null,
        ];
    }

    /**
     * Get all episodes for a specific season.
     */
    public function getSeasonEpisodes(string $seriesId, int $season, array $options = []): array
    {
        $language = $options['language'] ?? $this->language;
        
        $response = $this->http->get("/series/{$seriesId}/episodes/query", [
            'language' => $language,
            'airedSeason' => $season,
        ]);

        if (!$response || !isset($response['data'])) {
            return [];
        }

        return $this->formatEpisodes($response['data']);
    }

    private function formatActors(array $actors): array
    {
        return array_map(function ($actor) {
            return [
                'name' => $actor['personName'] ?? $actor['actorName'] ?? '',
                'role' => $actor['role'] ?? '',
                'image_url' => $actor['image'] ?? null,
                'sort_order' => $actor['sortOrder'] ?? 0,
            ];
        }, $actors);
    }

    private function formatEpisodes(array $episodes): array
    {
        return array_map(function ($episode) {
            return [
                'id' => (string) ($episode['id'] ?? ''),
                'name' => $episode['episodeName'] ?? '',
                'overview' => $episode['overview'] ?? '',
                'season_number' => $episode['airedSeason'] ?? 1,
                'episode_number' => $episode['airedEpisodeNumber'] ?? 0,
                'first_aired' => $episode['firstAired'] ?? null,
                'runtime' => $episode['runtime'] ?? null,
                'rating' => $episode['siteRating'] ?? null,
                'thumbnail' => $episode['filename'] ?? null,
            ];
        }, $episodes);
    }

    private function formatImages(array $images, string $type): array
    {
        return array_map(function ($image) use ($type) {
            return [
                'url' => $image['fileName'] ?? '',
                'type' => $type,
                'width' => $image['resolution'] ?? 0,
                'rating' => $image['ratingsInfo']['average'] ?? null,
                'language' => $image['language'] ?? null,
            ];
        }, $images);
    }

    private function parseGenres(string $genres): array
    {
        if (empty($genres)) {
            return [];
        }
        return array_filter(array_map('trim', explode('|', $genres)));
    }

    private function countSeasons(array $episodes): int
    {
        $seasons = [];
        foreach ($episodes as $episode) {
            $season = $episode['season_number'] ?? 1;
            $seasons[$season] = true;
        }
        return count($seasons);
    }
}
