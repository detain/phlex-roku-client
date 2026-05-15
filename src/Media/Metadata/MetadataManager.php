<?php

declare(strict_types=1);

namespace Phlex\Media\Metadata;

use Workerman\MySQL\Connection;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\LogChannels;
use Phlex\Media\Library\ItemRepository;

class MetadataManager
{
    private Connection $db;
    private ItemRepository $itemRepository;
    
    /** @var array<string, array<string, MetadataProviderInterface>> Provider type => [name => provider] */
    private array $providersByType = [];
    
    /** @var array<string, array<int, string>> Media type => Provider types in priority order */
    private array $providerPriority = [
        'movie' => ['tmdb', 'local'],
        'series' => ['tvdb', 'fanart', 'local'],
        'episode' => ['tvdb', 'local'],
        'artist' => ['musicbrainz', 'local'],
        'album' => ['musicbrainz', 'local'],
    ];
    
    /** @var array<string, MetadataProviderInterface> Flat provider lookup by name */
    private array $providers = [];
    
    private \Phlex\Common\Logger\StructuredLogger $logger;

    public function __construct(Connection $db, ItemRepository $itemRepository)
    {
        $this->db = $db;
        $this->itemRepository = $itemRepository;
        $this->logger = LoggerFactory::get(LogChannels::MEDIA);
    }

    /**
     * Register a metadata provider.
     * 
     * @param string $name Provider name (e.g., 'tmdb', 'tvdb', 'fanart', 'local')
     * @param MetadataProviderInterface $provider The provider instance
     * @param array<string> $supportedTypes Media types this provider supports
     */
    public function registerProvider(
        string $name, 
        MetadataProviderInterface $provider,
        array $supportedTypes = []
    ): void {
        $this->providers[$name] = $provider;
        
        foreach ($supportedTypes as $type) {
            if (!isset($this->providersByType[$type])) {
                $this->providersByType[$type] = [];
            }
            $this->providersByType[$type][$name] = $provider;
        }
        
        $this->logger->info('Registered metadata provider', [
            'name' => $name, 
            'supported_types' => $supportedTypes
        ]);
    }

    /**
     * Set provider priority for a media type.
     * 
     * @param string $mediaType e.g., 'movie', 'series'
     * @param array<string> $priority Ordered list of provider names (highest first)
     */
    public function setProviderPriority(string $mediaType, array $priority): void
    {
        $this->providerPriority[$mediaType] = $priority;
        $this->logger->info('Updated provider priority', [
            'media_type' => $mediaType,
            'priority' => $priority
        ]);
    }

    /**
     * Get providers for a specific media type in priority order.
     * 
     * @return MetadataProviderInterface[]
     */
    public function getProvidersForType(string $mediaType): array
    {
        $priority = $this->providerPriority[$mediaType] ?? ['local'];
        $result = [];
        
        foreach ($priority as $providerName) {
            if (isset($this->providers[$providerName])) {
                $result[] = $this->providers[$providerName];
            }
        }
        
        return $result;
    }

    /**
     * Refresh metadata for a single item, trying providers in priority order.
     */
    public function refreshItemMetadata(string $itemId, bool $force = false): bool
    {
        $item = $this->itemRepository->findById($itemId);
        if (!$item) {
            $this->logger->warning('Cannot refresh metadata - item not found', ['item_id' => $itemId]);
            return false;
        }

        $mediaType = $item['type'];
        $providers = $this->getProvidersForType($mediaType);
        
        if (empty($providers)) {
            $this->logger->debug('No providers for item type', ['type' => $mediaType]);
            return false;
        }

        $metadata = $this->parseMetadataJson($item['metadata_json'] ?? '{}');
        $searchQuery = $metadata['name'] ?? $item['name'];
        $year = $metadata['year'] ?? null;
        
        // Try each provider in priority order
        foreach ($providers as $provider) {
            $providerName = $this->getProviderName($provider);
            
            $this->logger->debug('Attempting metadata refresh', [
                'item_id' => $itemId,
                'provider' => $providerName,
            ]);
            
            $result = $this->tryProvider(
                $provider, 
                $providerName,
                $itemId, 
                $item, 
                $searchQuery, 
                $year,
                $force
            );
            
            if ($result) {
                return true;
            }
            
            $this->logger->info('Provider failed, trying next', [
                'item_id' => $itemId,
                'provider' => $providerName,
            ]);
        }
        
        $this->logger->info('No provider succeeded for item', [
            'item_id' => $itemId,
            'media_type' => $mediaType,
        ]);
        
        return false;
    }

    /**
     * Try a specific provider to refresh metadata.
     */
    private function tryProvider(
        MetadataProviderInterface $provider,
        string $providerName,
        string $itemId,
        array $item,
        string $searchQuery,
        ?string $year,
        bool $force
    ): bool {
        $metadata = $this->parseMetadataJson($item['metadata_json'] ?? '{}');
        
        // Check if we already have recent metadata from this provider
        if (!$force && $this->hasRecentMetadata($metadata, $providerName)) {
            $this->logger->debug('Skipping - recent metadata exists', [
                'item_id' => $itemId,
                'provider' => $providerName,
            ]);
            return true;
        }
        
        // Search for match
        $results = $provider->search($searchQuery, ['year' => $year]);
        if (empty($results)) {
            $this->logger->debug('No search results', [
                'item' => $searchQuery,
                'provider' => $providerName,
            ]);
            return false;
        }

        // Get best match (first result)
        $match = $results[0];
        $externalId = $match['id'];

        // Fetch full details
        $details = $provider->getDetails($externalId);
        if (empty($details)) {
            $this->logger->debug('No details from provider', [
                'external_id' => $externalId,
                'provider' => $providerName,
            ]);
            return false;
        }

        // Fetch images
        $images = $provider->getImages($externalId);

        // Build external IDs tracking
        $externalIds = $metadata['external_ids'] ?? [];
        $externalIds[$providerName] = $externalId;
        
        // If we have IDs from other providers, preserve them
        if (isset($metadata['external_ids']) && is_array($metadata['external_ids'])) {
            foreach ($metadata['external_ids'] as $key => $value) {
                if ($key !== $providerName && !isset($externalIds[$key])) {
                    $externalIds[$key] = $value;
                }
            }
        }

        // Update item with metadata
        $this->itemRepository->update($itemId, [
            'name' => $details['name'] ?? $item['name'],
            'metadata_json' => json_encode(array_merge($metadata, [
                'external_ids' => $externalIds,
                'details' => array_merge($metadata['details'] ?? [], [
                    $providerName => $details,
                ]),
                'images' => array_merge($metadata['images'] ?? [], [
                    $providerName => $images,
                ]),
                'metadata_refreshed_at' => date('c'),
                'metadata_provider' => $providerName,
            ])),
        ]);

        $this->logger->info('Metadata refreshed', [
            'item_id' => $itemId, 
            'external_id' => $externalId,
            'provider' => $providerName,
        ]);
        
        return true;
    }

    /**
     * Check if metadata was recently refreshed from a specific provider.
     */
    private function hasRecentMetadata(array $metadata, string $providerName): bool
    {
        // Check if we have details from this provider
        if (!isset($metadata['details'][$providerName])) {
            return false;
        }
        
        // Check refresh timestamp (within last 24 hours)
        if (!isset($metadata['metadata_refreshed_at'])) {
            return false;
        }
        
        $refreshedAt = strtotime($metadata['metadata_refreshed_at']);
        if ($refreshedAt === false) {
            return false;
        }
        
        return (time() - $refreshedAt) < 86400; // 24 hours
    }

    /**
     * Refresh metadata for entire library.
     */
    public function refreshLibraryMetadata(string $libraryId, callable $progressCallback = null): int
    {
        $items = $this->db->query(
            "SELECT id, name, metadata_json FROM media_items WHERE library_id = ?",
            [$libraryId]
        );

        $refreshed = 0;
        $total = count($items);

        foreach ($items as $index => $item) {
            if ($this->refreshItemMetadata($item['id'])) {
                $refreshed++;
            }

            if ($progressCallback) {
                $progressCallback($index + 1, $total);
            }
        }

        return $refreshed;
    }

    /**
     * Get provider name from provider instance.
     */
    private function getProviderName(MetadataProviderInterface $provider): string
    {
        foreach ($this->providers as $name => $p) {
            if ($p === $provider) {
                return $name;
            }
        }
        return 'unknown';
    }

    /**
     * Get all registered provider names.
     */
    public function getRegisteredProviders(): array
    {
        return array_keys($this->providers);
    }

    /**
     * Check if a specific provider is registered.
     */
    public function hasProvider(string $name): bool
    {
        return isset($this->providers[$name]);
    }

    /**
     * Get provider by name.
     */
    public function getProvider(string $name): ?MetadataProviderInterface
    {
        return $this->providers[$name] ?? null;
    }

    private function parseMetadataJson(?string $json): array
    {
        if (empty($json)) {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }
}
