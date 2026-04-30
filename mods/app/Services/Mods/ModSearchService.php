<?php

namespace Pterodactyl\Services\Mods;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ModSearchService
{
    public function search(
        string $provider,
        string $query,
        ?string $mcVersion = null,
        ?string $loader = null,
    ): array {
        return match ($provider) {
            'modrinth'   => $this->searchModrinth($query, $mcVersion, $loader),
            'curseforge' => $this->searchCurseForge($query, $mcVersion, $loader),
            default      => throw new \InvalidArgumentException("Unknown provider: $provider"),
        };
    }

    public function getVersions(string $provider, string $projectId): array
    {
        return match ($provider) {
            'modrinth'   => $this->getModrinthVersions($projectId),
            'curseforge' => $this->getCurseForgeVersions($projectId),
            default      => [],
        };
    }

    private function searchModrinth(string $query, ?string $mcVersion, ?string $loader): array
    {
        $params = [
            'query'  => $query,
            'limit'  => 20,
            'offset' => 0,
        ];

        $facets = [['project_type:mod']];
        if ($mcVersion) $facets[] = ["versions:{$mcVersion}"];
        if ($loader) $facets[] = ["categories:{$loader}"];

        $params['facets'] = json_encode($facets);

        $response = Http::withHeaders([
            'User-Agent' => config('mods.modrinth_user_agent', 'PterodactylMods/1.0'),
        ])->get('https://api.modrinth.com/v2/search', $params);

        if (!$response->ok()) return [];

        return collect($response->json()['hits'] ?? [])
            ->map(fn($h) => [
                'id'          => $h['project_id'],
                'name'        => $h['title'],
                'description' => $h['description'],
                'downloads'   => $h['downloads'] ?? 0,
                'icon_url'    => $h['icon_url'] ?? null,
                'mc_versions' => $h['versions'] ?? [],
                'loaders'     => $h['loaders'] ?? [],
                'provider'    => 'modrinth',
                'slug'        => $h['slug'] ?? $h['project_id'],
            ])
            ->values()
            ->toArray();
    }

    private function getModrinthVersions(string $projectId): array
    {
        $cacheKey = "mods.modrinth.versions.$projectId";

        return Cache::remember($cacheKey, 300, function () use ($projectId) {
            $response = Http::withHeaders([
                'User-Agent' => config('mods.modrinth_user_agent', 'PterodactylMods/1.0'),
            ])->get("https://api.modrinth.com/v2/project/{$projectId}/version", [
                'loaders'     => json_encode(['forge', 'fabric', 'neoforge', 'quilt']),
            ]);

            if (!$response->ok()) return [];

            return collect($response->json())
                ->map(fn($v) => [
                    'id'          => $v['id'],
                    'name'        => $v['name'],
                    'version'     => $v['version_number'],
                    'mc_versions' => $v['game_versions'] ?? [],
                    'loaders'     => $v['loaders'] ?? [],
                    'date'        => $v['date_published'] ?? null,
                    'downloads'   => $v['downloads'] ?? 0,
                    'file_size'   => $v['files'][0]['size'] ?? null,
                    'filename'    => $v['files'][0]['filename'] ?? null,
                ])
                ->values()
                ->toArray();
        });
    }

    private function searchCurseForge(string $query, ?string $gameVersion, ?string $loader): array
    {
        $apiKey = config('mods.curseforge_api_key');
        if (!$apiKey) return ['error' => 'API Key is not configured'];

        $params = [
            'gameId'         => 432,
            'classId'        => 6,
            'searchFilter'   => $query,
            'pageSize'       => 20,
            'sortField'      => 2,
            'sortOrder'      => 1,
        ];

        if ($gameVersion) $params['gameVersion'] = $gameVersion;

        $response = Http::withHeaders(['x-api-key' => $apiKey])
            ->get('https://api.curseforge.com/v1/mods/search', $params);

        if (!$response->ok()) return [];

        return collect($response->json()['data'] ?? [])
            ->map(fn($m) => [
                'id'          => (string) $m['id'],
                'name'        => $m['name'],
                'description' => $m['summary'] ?? '',
                'downloads'   => $m['downloadCount'] ?? 0,
                'icon_url'    => $m['logo']['thumbnailUrl'] ?? null,
                'mc_versions' => collect($m['latestFilesIndexes'] ?? [])
                    ->pluck('gameVersion')->unique()->values()->toArray(),
                'loaders'     => [],
                'provider'    => 'curseforge',
                'slug'        => $m['slug'] ?? (string) $m['id'],
            ])
            ->values()
            ->toArray();
    }

    private function getCurseForgeVersions(string $projectId): array
    {
        $apiKey = config('mods.curseforge_api_key');

        if (!$apiKey) return [];

        $response = Http::withHeaders(['x-api-key' => $apiKey])
            ->get("https://api.curseforge.com/v1/mods/{$projectId}/files", [
                'pageSize' => 50,
            ]);

        if (!$response->ok()) return [];

        return collect($response->json()['data'] ?? [])
            ->map(fn($f) => [
                'id'              => (string) $f['id'],
                'name'            => $f['displayName'],
                'version'         => $f['displayName'],
                'mc_versions'     => $f['gameVersions'] ?? [],
                'loaders'         => [],
                'date'            => $f['fileDate'] ?? null,
                'downloads'       => $f['downloadCount'] ?? 0,
                'file_size'       => $f['fileLength'] ?? null,
                'filename'        => $f['fileName'] ?? null,
            ])
            ->values()
            ->toArray();
    }
}
