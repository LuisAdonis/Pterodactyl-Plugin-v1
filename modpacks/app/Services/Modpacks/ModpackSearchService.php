<?php

namespace Pterodactyl\Services\Modpacks;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;


class ModpackSearchService
{
    /**
     * Search modpacks across a provider.
     */
    public function search(
        string $provider,
        string $query,
        ?string $mcVersion = null,
        ?string $loader = null,
    ): array {
        return match ($provider) {
            'modrinth'   => $this->searchModrinth($query, $mcVersion, $loader),
            'curseforge' => $this->searchCurseForge($query, $mcVersion, $loader),
            'ftb'        => $this->searchFTB($query),
            default      => throw new \InvalidArgumentException("Unknown provider: $provider"),
        };
    }

    /**
     * Get available versions for a specific project.
     */
    public function getVersions(string $provider, string $projectId): array
    {
        return match ($provider) {
            'modrinth'   => $this->getModrinthVersions($projectId),
            'curseforge' => $this->getCurseForgeVersions($projectId),
            'ftb'        => $this->getFTBVersions($projectId),
            default      => [],
        };
    }

    // ─── Modrinth ────────────────────────────────────────────────────────────

    private function searchModrinth(string $query, ?string $mcVersion, ?string $loader): array
    {
        $params = [
            'query'  => $query,
            'limit'  => 20,
            'offset' => 0,
        ];

        $facets = [['project_type:modpack']];
        if ($mcVersion) $facets[] = ["versions:{$mcVersion}"];
        if ($loader) $facets[] = ["categories:{$loader}"];

        $params['facets'] = json_encode($facets);

        $response = Http::withHeaders([
            'User-Agent' => 'PterodactylModpacks/1.0',
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
        $cacheKey = "modpack.modrinth.versions.$projectId";

        return Cache::remember($cacheKey, 300, function () use ($projectId) {
            $response = Http::withHeaders([
                'User-Agent' => 'PterodactylModpacks/1.0',
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
                ])
                ->values()
                ->toArray();
        });
    }

    // ─── CurseForge ──────────────────────────────────────────────────────────

    private function searchCurseForge(string $query, ?string $gameVersion, ?string $loader): array
    {
        $apiKey = config('modpacks.modpacks.curseforge_api_key');
        if (!$apiKey) return ['error' => 'API Key is not configured : ' . $apiKey];

        $params = [
            'gameId'         => 432, // Minecraft
            'classId'        => 4471, // Modpacks
            'searchFilter'   => $query,
            'pageSize'       => 20,
            'sortField'      => 2, // Popularity
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
        $apiKey = config('modpacks.modpacks.curseforge_api_key');

     Log::info("CF Versions - projectId: $projectId");
    Log::info("CF Versions - apiKey presente: " . (!empty($apiKey) ? 'SI' : 'NO'));
    


    if (!$apiKey) return [];

    $response = Http::withHeaders(['x-api-key' => $apiKey])
        ->get("https://api.curseforge.com/v1/mods/{$projectId}/files", [
            'pageSize' => 50,
        ]);
    Log::info("CF Versions - Status: " . $response->status());
    Log::info("CF Versions - Body: " . substr($response->body(), 0, 500));

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
            'has_server_pack' => isset($f['serverPackFileId']),
        ])
        ->values()
        ->toArray();
}

    // ─── FTB ─────────────────────────────────────────────────────────────────

    private function searchFTB(string $query): array
    {
        $response = Http::get('https://api.feed-the-beast.com/v1/modpacks/public/modpack/search/8', [
            'term' => $query,
        ]);

        if (!$response->ok()) return [];

        $ids = $response->json()['packs'] ?? [];

        return collect($ids)->take(10)->map(function ($id) {
        $detail = Http::get("https://api.feed-the-beast.com/v1/modpacks/public/modpack/{$id}");
            if (!$detail->ok()) return null;
            $d = $detail->json();
            return [
                'id'          => (string) $id,
                'name'        => $d['name'] ?? "Pack {$id}",
                'description' => $d['description'] ?? '',
                'downloads'   => $d['installs'] ?? 0,
                'icon_url'    => collect($d['art'] ?? [])->where('type', 'square')->first()['url'] ?? null,
                'mc_versions' => [],
                'loaders'     => ['forge'],
                'provider'    => 'ftb',
                'slug'        => (string) $id,
            ];
        })->filter()->values()->toArray();
    }

    private function getFTBVersions(string $projectId): array
    {
        $response = Http::get("https://api.feed-the-beast.com/v1/modpacks/public/modpack/{$projectId}");
        if (!$response->ok()) return [];

        return collect($response->json()['versions'] ?? [])
            ->map(fn($v) => [
                'id'          => (string) $v['id'],
                'name'        => $v['name'] ?? "Version {$v['id']}",
                'version'     => $v['name'] ?? (string) $v['id'],
                'mc_versions' => [],
                'loaders'     => ['forge'],
                'date'        => $v['updated'] ?? null,
                'downloads'   => $v['installs'] ?? 0,
                'file_size'   => null,
                'has_server_pack' => true,
            ])
            ->values()
            ->toArray();
    }
}
