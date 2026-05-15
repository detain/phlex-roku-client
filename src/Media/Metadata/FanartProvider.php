<?php

declare(strict_types=1);

namespace Phlex\Media\Metadata;

/**
 * Fanart.tv API provider for artwork (banners, thumbnails, season thumbs).
 * 
 * @see https://fanart.tv/api/
 */
class FanartProvider implements MetadataProviderInterface
{
    private MetadataHttpClient $http;
    private string $apiKey;
    private array $cache = [];

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
        $this->http = new MetadataHttpClient(
            'https://webservice.fanart.tv/v3',
            $apiKey
        );
    }

    public function search(string $query, array $options = []): array
    {
        // Fanart.tv doesn't support search directly, return empty
        // Use TVDB or TMDB to get the IMDB/TVDB ID first
        return [];
    }

    public function getDetails(string $externalId, array $options = []): array
    {
        // Fanart.tv uses external IDs - try to fetch by type
        $idType = $options['id_type'] ?? 'tvdb'; // 'tvdb', 'imdb', 'tmdb', 'musicbrainz'
        
        $response = $this->fetchArtwork($idType, $externalId);
        
        if (!$response) {
            return [];
        }

        return $this->formatDetails($response);
    }

    public function getImages(string $externalId): array
    {
        $idType = 'tvdb'; // Default to TVDB
        $response = $this->fetchArtwork($idType, $externalId);
        
        if (!$response) {
            return [];
        }

        return $this->formatImages($response);
    }

    public function getProviders(): array
    {
        return ['fanart', 'fanarttv'];
    }

    /**
     * Get movie artwork from Fanart.tv.
     */
    public function getMovieImages(string $imdbId): array
    {
        $response = $this->fetchArtwork('imdb', $imdbId);
        
        if (!$response) {
            return [];
        }

        return $this->formatImages($response);
    }

    /**
     * Get TV show artwork from Fanart.tv.
     */
    public function getTvShowImages(string $tvdbId): array
    {
        $response = $this->fetchArtwork('tvdb', $tvdbId);
        
        if (!$response) {
            return [];
        }

        return $this->formatImages($response);
    }

    /**
     * Get music artwork from Fanart.tv.
     */
    public function getMusicImages(string $musicbrainzId): array
    {
        $response = $this->fetchArtwork('musicbrainz', $musicbrainzId);
        
        if (!$response) {
            return [];
        }

        return $this->formatImages($response);
    }

    private function fetchArtwork(string $idType, string $id): ?array
    {
        $cacheKey = "fanart_{$idType}_{$id}";
        
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $endpoint = match ($idType) {
            'tvdb' => "/tv/{$id}",
            'imdb' => "/movies/{$id}",
            'tmdb' => "/movies/fanart{$id}", // TMDB requires different approach
            'musicbrainz' => "/music/{$id}",
            default => null,
        };

        if (!$endpoint) {
            return null;
        }

        // Fanart.tv requires X-Client-Key header
        $response = $this->http->get($endpoint);

        if (!$response) {
            return null;
        }

        $this->cache[$cacheKey] = $response;
        return $response;
    }

    private function formatDetails(array $data): array
    {
        $images = $this->formatImages($data);
        
        return [
            'name' => $data['name'] ?? '',
            'has_all_images' => !empty($images),
            'image_counts' => [
                'hd_logos' => count($data['hddata']['hdmovielogo'] ?? []),
                'logos' => count($data['hddata']['movielogo'] ?? []),
                'posters' => count($data['movieposters']['movieposter'] ?? []),
                'backdrops' => count($data['moviebackgrounds']['moviebackground'] ?? []),
                'banners' => count($data['moviebanners']['moviebanner'] ?? []),
                'thumbs' => count($data['moviethumbs']['moviethumb'] ?? []),
                'logos' => count($data['movielogos']['movielogo'] ?? []),
                'tshots' => count($data['moviescreencaps']['moviescreencap'] ?? []),
            ],
        ];
    }

    private function formatImages(array $data): array
    {
        $images = [];

        // HD logos (for TV shows and movies)
        foreach ($data['hddata']['hdmovielogo'] ?? [] as $logo) {
            $images['hd_logos'][] = $this->formatImage($logo, 'hd_logo');
        }
        foreach ($data['hddata']['hdtvlogo'] ?? [] as $logo) {
            $images['hd_tv_logos'][] = $this->formatImage($logo, 'hd_tv_logo');
        }

        // Standard logos
        foreach ($data['hddata']['movielogo'] ?? [] as $logo) {
            $images['logos'][] = $this->formatImage($logo, 'logo');
        }
        foreach ($data['hddata']['tvlogo'] ?? [] as $logo) {
            $images['tv_logos'][] = $this->formatImage($logo, 'tv_logo');
        }

        // Posters
        foreach ($data['movieposters']['movieposter'] ?? [] as $poster) {
            $images['posters'][] = $this->formatImage($poster, 'poster');
        }
        foreach ($data['tvposters']['tvposter'] ?? [] as $poster) {
            $images['tv_posters'][] = $this->formatImage($poster, 'poster');
        }
        foreach ($data['seasonposters']['seasonposter'] ?? [] as $poster) {
            $images['season_posters'][] = $this->formatImage($poster, 'season_poster');
        }

        // Backdrops
        foreach ($data['moviebackgrounds']['moviebackground'] ?? [] as $bg) {
            $images['backdrops'][] = $this->formatImage($bg, 'backdrop');
        }
        foreach ($data['showbackgrounds']['showbackground'] ?? [] as $bg) {
            $images['show_backdrops'][] = $this->formatImage($bg, 'backdrop');
        }
        foreach ($data['seasonbackgrounds']['seasonbackground'] ?? [] as $bg) {
            $images['season_backdrops'][] = $this->formatImage($bg, 'season_backdrop');
        }

        // Banners
        foreach ($data['moviebanners']['moviebanner'] ?? [] as $banner) {
            $images['banners'][] = $this->formatImage($banner, 'banner');
        }
        foreach ($data['tvthumbs']['tvthumb'] ?? [] as $thumb) {
            $images['thumbs'][] = $this->formatImage($thumb, 'thumb');
        }

        // Season thumbs (wide banners)
        foreach ($data['seasonthumbs']['seasonthumb'] ?? [] as $thumb) {
            $images['season_thumbs'][] = $this->formatImage($thumb, 'season_thumb');
        }
        foreach ($data['tvthumb']['tvthumb'] ?? [] as $thumb) {
            $images['tv_thumbs'][] = $this->formatImage($thumb, 'tv_thumb');
        }

        // Clear arts (transparent PNG overlays)
        foreach ($data['hddata']['clearart'] ?? [] as $art) {
            $images['clear_arts'][] = $this->formatImage($art, 'clear_art');
        }
        foreach ($data['hddata']['tvcloud'] ?? [] as $art) {
            $images['tv_clouds'][] = $this->formatImage($art, 'tv_cloud');
        }

        // Thumbnails
        foreach ($data['moviethumbs']['moviethumb'] ?? [] as $thumb) {
            $images['movie_thumbs'][] = $this->formatImage($thumb, 'movie_thumb');
        }

        return $images;
    }

    private function formatImage(array $image, string $type): array
    {
        return [
            'url' => $image['url'] ?? '',
            'type' => $type,
            'width' => $image['width'] ?? 0,
            'height' => $image['height'] ?? 0,
            'language' => $image['lang'] ?? null,
            'rating' => $image['rating'] ?? null,
            'likes' => $image['likes'] ?? 0,
        ];
    }
}
