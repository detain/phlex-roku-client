<?php

declare(strict_types=1);

namespace Phlex\Media\Metadata;

/**
 * Local NFO file parser for movies and TV series metadata.
 * 
 * Supports XBMC/Kodi NFO formats:
 * - Movie: movie.nfo (with TMDB/IMDB ID)
 * - TV Series: tvshow.nfo (with TVDB ID)
 * - TV Episode: episode.nfo
 */
class LocalNfoProvider implements MetadataProviderInterface
{
    private string $mediaPath;
    private array $cache = [];

    public function __construct(string $mediaPath = '')
    {
        $this->mediaPath = rtrim($mediaPath, '/');
    }

    public function search(string $query, array $options = []): array
    {
        // Local NFO doesn't support search
        return [];
    }

    public function getDetails(string $externalId, array $options = []): array
    {
        // If externalId is a file path, parse that NFO
        if (str_ends_with($externalId, '.nfo') || is_file($externalId)) {
            return $this->parseNfoFile($externalId);
        }
        
        // If it's a directory, look for NFO files
        if (is_dir($externalId)) {
            return $this->parseDirectory($externalId);
        }
        
        return [];
    }

    public function getImages(string $externalId): array
    {
        // Local NFO typically doesn't contain images, but we can
        // look for related image files in the same directory
        if (is_dir($externalId)) {
            return $this->findLocalImages($externalId);
        }
        
        $dir = dirname($externalId);
        return $this->findLocalImages($dir);
    }

    public function getProviders(): array
    {
        return ['local', 'nfo'];
    }

    /**
     * Parse a movie NFO file.
     */
    public function parseMovieNfo(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [];
        }

        $content = file_get_contents($filePath);
        if (!$content) {
            return [];
        }

        // Try XML format first
        if (str_contains($content, '<?xml')) {
            return $this->parseXmlNfo($content, 'movie');
        }

        // Try simple format (one ID per line)
        return $this->parseSimpleNfo($content);
    }

    /**
     * Parse a TV show NFO file.
     */
    public function parseTvShowNfo(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [];
        }

        $content = file_get_contents($filePath);
        if (!$content) {
            return [];
        }

        // Try XML format first
        if (str_contains($content, '<?xml')) {
            return $this->parseXmlNfo($content, 'tvshow');
        }

        // Try simple format
        return $this->parseSimpleNfo($content);
    }

    /**
     * Parse an episode NFO file.
     */
    public function parseEpisodeNfo(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [];
        }

        $content = file_get_contents($filePath);
        if (!$content) {
            return [];
        }

        if (str_contains($content, '<?xml')) {
            return $this->parseXmlNfo($content, 'episodedetails');
        }

        return $this->parseSimpleNfo($content);
    }

    /**
     * Find and parse all NFO files in a directory.
     */
    public function parseDirectory(string $dirPath): array
    {
        $dirPath = rtrim($dirPath, '/');
        $result = [
            'type' => 'unknown',
            'metadata' => [],
        ];

        // Look for tvshow.nfo (TV series)
        $tvshowPath = $dirPath . '/tvshow.nfo';
        if (file_exists($tvshowPath)) {
            $result['type'] = 'tvshow';
            $result['metadata'] = $this->parseTvShowNfo($tvshowPath);
            return $result;
        }

        // Look for movie.nfo or a single .nfo file with movie content
        $moviePath = $dirPath . '/movie.nfo';
        if (file_exists($moviePath)) {
            $result['type'] = 'movie';
            $result['metadata'] = $this->parseMovieNfo($moviePath);
            return $result;
        }

        // Look for any .nfo file
        $nfoFiles = glob($dirPath . '/*.nfo');
        if (!empty($nfoFiles)) {
            $result['type'] = 'generic';
            $result['metadata'] = $this->parseNfoFile($nfoFiles[0]);
            return $result;
        }

        return $result;
    }

    private function parseNfoFile(string $filePath): array
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        
        if ($extension !== 'nfo') {
            $filePath .= '.nfo';
        }

        if (!file_exists($filePath)) {
            return [];
        }

        // Determine type by filename
        $filename = basename($filePath);
        
        if ($filename === 'tvshow.nfo') {
            return $this->parseTvShowNfo($filePath);
        }
        
        if ($filename === 'movie.nfo') {
            return $this->parseMovieNfo($filePath);
        }

        // Check content to determine type
        $content = file_get_contents($filePath);
        
        if (str_contains($content, 'episodedetails')) {
            return $this->parseEpisodeNfo($filePath);
        }
        
        if (str_contains($content, '<tvshow')) {
            return $this->parseTvShowNfo($filePath);
        }

        // Default to movie format
        return $this->parseMovieNfo($filePath);
    }

    private function parseXmlNfo(string $content, string $type): array
    {
        $xml = @simplexml_load_string($content);
        
        if (!$xml) {
            return $this->parseSimpleNfo($content);
        }

        return match ($type) {
            'movie' => $this->extractMovieFromXml($xml),
            'tvshow' => $this->extractTvShowFromXml($xml),
            'episodedetails' => $this->extractEpisodeFromXml($xml),
            default => [],
        };
    }

    private function extractMovieFromXml(\SimpleXMLElement $xml): array
    {
        $result = [
            'type' => 'movie',
            'name' => (string) ($xml->title ?? ''),
            'original_name' => (string) ($xml->originaltitle ?? ''),
            'overview' => (string) ($xml->plot ?? ''),
            'year' => $this->extractYear((string) ($xml->year ?? '')),
            'premiered' => (string) ($xml->premiered ?? ''),
            'rating' => $this->parseFloat((string) ($xml->rating ?? 0)),
            'votes' => $this->parseInt((string) ($xml->votes ?? 0)),
            'runtime' => $this->parseInt((string) ($xml->runtime ?? 0)),
            'mpaa' => (string) ($xml->mpaa ?? ''),
            'tagline' => (string) ($xml->tagline ?? ''),
            'genres' => $this->extractGenres($xml),
            'studios' => $this->extractStudios($xml),
            'credits' => $this->extractCredits($xml),
            'directors' => $this->extractDirectors($xml),
            'actors' => $this->extractActors($xml),
        ];

        // Extract external IDs
        $result['external_ids'] = [
            'tmdb' => (string) ($xml->tmdbid ?? ''),
            'imdb' => (string) ($xml->imdbid ?? ''),
        ];

        return array_filter($result, fn($v) => $v !== '' && $v !== null && $v !== []);
    }

    private function extractTvShowFromXml(\SimpleXMLElement $xml): array
    {
        $result = [
            'type' => 'tvshow',
            'name' => (string) ($xml->title ?? ''),
            'original_name' => (string) ($xml->originaltitle ?? ''),
            'overview' => (string) ($xml->plot ?? ''),
            'year' => $this->extractYear((string) ($xml->premiered ?? '')),
            'premiered' => (string) ($xml->premiered ?? ''),
            'rating' => $this->parseFloat((string) ($xml->rating ?? 0)),
            'votes' => $this->parseInt((string) ($xml->votes ?? 0)),
            'status' => (string) ($xml->status ?? ''),
            'episode_run_time' => $this->parseInt((string) ($xml->episode_run_time ?? 0)),
            'genres' => $this->extractGenres($xml),
            'studios' => $this->extractStudios($xml),
            'actors' => $this->extractActors($xml),
        ];

        // Extract TVDB ID
        $result['external_ids'] = [
            'tvdb' => (string) ($xml->tvdbid ?? ''),
            'imdb' => (string) ($xml->imdbid ?? ''),
        ];

        return array_filter($result, fn($v) => $v !== '' && $v !== null && $v !== []);
    }

    private function extractEpisodeFromXml(\SimpleXMLElement $xml): array
    {
        $result = [
            'type' => 'episode',
            'name' => (string) ($xml->title ?? ''),
            'overview' => (string) ($xml->plot ?? ''),
            'season_number' => $this->parseInt((string) ($xml->season ?? 0)),
            'episode_number' => $this->parseInt((string) ($xml->episode ?? 0)),
            'aired' => (string) ($xml->aired ?? ''),
            'rating' => $this->parseFloat((string) ($xml->rating ?? 0)),
            'runtime' => $this->parseInt((string) ($xml->runtime ?? 0)),
            'director' => (string) ($xml->director ?? ''),
            'credits' => (string) ($xml->credits ?? ''),
        ];

        return array_filter($result, fn($v) => $v !== '' && $v !== null && $v !== []);
    }

    private function extractGenres(\SimpleXMLElement $xml): array
    {
        $genres = [];
        
        if (isset($xml->genre)) {
            foreach ($xml->genre as $genre) {
                $genreStr = trim((string) $genre);
                if (!empty($genreStr)) {
                    // Genres can be pipe-separated or multiple elements
                    $parts = array_filter(array_map('trim', explode('|', $genreStr)));
                    $genres = array_merge($genres, $parts);
                }
            }
        }

        return array_unique(array_filter($genres));
    }

    private function extractStudios(\SimpleXMLElement $xml): array
    {
        $studios = [];
        
        if (isset($xml->studio)) {
            foreach ($xml->studio as $studio) {
                $studioStr = trim((string) $studio);
                if (!empty($studioStr)) {
                    $studios[] = $studioStr;
                }
            }
        }

        return $studios;
    }

    private function extractCredits(\SimpleXMLElement $xml): array
    {
        $credits = [];
        
        if (isset($xml->credits)) {
            foreach ($xml->credits as $credit) {
                $creditStr = trim((string) $credit);
                if (!empty($creditStr)) {
                    $credits[] = $creditStr;
                }
            }
        }

        return $credits;
    }

    private function extractDirectors(\SimpleXMLElement $xml): array
    {
        $directors = [];
        
        if (isset($xml->director)) {
            foreach ($xml->director as $director) {
                $directorStr = trim((string) $director);
                if (!empty($directorStr)) {
                    $directors[] = $directorStr;
                }
            }
        }

        return $directors;
    }

    private function extractActors(\SimpleXMLElement $xml): array
    {
        $actors = [];
        
        if (isset($xml->actor)) {
            $order = 0;
            foreach ($xml->actor as $actor) {
                $name = trim((string) ($actor->name ?? ''));
                $role = trim((string) ($actor->role ?? ''));
                
                if (!empty($name)) {
                    $actors[] = [
                        'name' => $name,
                        'role' => $role,
                        'order' => $order++,
                    ];
                }
            }
        }

        return $actors;
    }

    private function parseSimpleNfo(string $content): array
    {
        $lines = array_filter(array_map('trim', explode("\n", $content)));
        $result = [
            'type' => 'movie',
            'external_ids' => [],
        ];

        foreach ($lines as $line) {
            // Skip XML tags and empty lines
            if (empty($line) || str_starts_with($line, '<')) {
                continue;
            }

            // Look for ID patterns
            if (preg_match('/(tmdb|themoviedb)[^\d]*(\d+)/i', $line, $matches)) {
                $result['external_ids']['tmdb'] = $matches[2];
            }
            if (preg_match('/(imdb|tt)(\d+)/i', $line, $matches)) {
                $result['external_ids']['imdb'] = 'tt' . $matches[2];
            }
            if (preg_match('/(tvdb|thetvdb)[^\d]*(\d+)/i', $line, $matches)) {
                $result['external_ids']['tvdb'] = $matches[2];
                $result['type'] = 'tvshow';
            }
        }

        return $result;
    }

    private function findLocalImages(string $dirPath): array
    {
        $images = [
            'posters' => [],
            'backdrops' => [],
            'logos' => [],
            'thumbs' => [],
        ];

        // Common poster filenames
        $posterPatterns = ['poster', 'cover', 'folder', 'movie', 'show', 'tvshow'];
        // Common backdrop/fanart filenames
        $backdropPatterns = ['fanart', 'backdrop', 'background', 'art'];

        $files = glob($dirPath . '/*.{jpg,jpeg,png}', GLOB_BRACE);
        
        foreach ($files as $file) {
            $filename = strtolower(basename($file, '.jpg'));
            $filename = strtok($filename, '.'); // Remove any suffix after extension

            foreach ($posterPatterns as $pattern) {
                if (str_contains($filename, $pattern)) {
                    $images['posters'][] = [
                        'url' => $file,
                        'type' => 'local',
                        'filename' => basename($file),
                    ];
                    break;
                }
            }

            foreach ($backdropPatterns as $pattern) {
                if (str_contains($filename, $pattern)) {
                    $images['backdrops'][] = [
                        'url' => $file,
                        'type' => 'local',
                        'filename' => basename($file),
                    ];
                    break;
                }
            }
        }

        return array_filter($images, fn($v) => !empty($v));
    }

    private function extractYear(string $dateStr): ?int
    {
        if (empty($dateStr)) {
            return null;
        }
        
        if (preg_match('/(\d{4})/', $dateStr, $matches)) {
            return (int) $matches[1];
        }
        
        return null;
    }

    private function parseFloat(string $value): ?float
    {
        $value = trim($value);
        if ($value === '' || !is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }

    private function parseInt(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || !is_numeric($value)) {
            return null;
        }
        return (int) $value;
    }
}
