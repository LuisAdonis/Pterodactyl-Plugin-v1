<?php

namespace Pterodactyl\Services\Modpacks;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class ModpackResolverService
{
    /**
     * Resolve modpack metadata and direct download URL.
     * Returns normalized array regardless of provider.
     */
    public function resolve(string $provider, string $projectId, string $versionId): array
    {
        return match ($provider) {
            'modrinth'   => $this->resolveModrinth($projectId, $versionId),
            'curseforge' => $this->resolveCurseForge($projectId, $versionId),
            'ftb'        => $this->resolveFTB($projectId, $versionId),
            default      => throw new \InvalidArgumentException("Unknown provider: $provider"),
        };
    }

    // ─── Modrinth ────────────────────────────────────────────────────────────

    private function resolveModrinth(string $projectId, string $versionId): array
    {
        $cacheKey = "modpack.modrinth.$versionId";

        return Cache::remember($cacheKey, 600, function () use ($projectId, $versionId) {
            $response = Http::withHeaders([
                'User-Agent' => 'PterodactylModpacks/1.0 (contact@yourpanel.com)',
            ])->get("https://api.modrinth.com/v2/version/{$versionId}");

            if (!$response->ok()) {
                throw new \RuntimeException("Modrinth API error: " . $response->status());
            }

            $data = $response->json();

            // Find the server pack file, fallback to first file
            $file = collect($data['files'] ?? [])
                ->first(fn($f) => $f['primary'] ?? false)
                ?? ($data['files'][0] ?? null);

            if (!$file) {
                throw new \RuntimeException("No downloadable files found for this Modrinth version.");
            }

            // Detect loader from game_versions/loaders fields
            $loaders = $data['loaders'] ?? [];
            $loader = $this->normalizeLoader($loaders[0] ?? 'unknown');

            return [
                'download_url'  => $file['url'],
                'file_size'     => $file['size'] ?? null,
                'version'       => $data['version_number'] ?? $versionId,
                'mc_version'    => $data['game_versions'][0] ?? '',
                'loader'        => $loader,
                'loader_version' => 'latest',
                'server_pack'   => true, // Modrinth doesn't always distinguish; let script handle it
                'sha512'        => $file['hashes']['sha512'] ?? null,
            ];
        });
    }

    // ─── CurseForge ──────────────────────────────────────────────────────────

    private function resolveCurseForge(string $projectId, string $versionId): array
    {
        $apiKey = config('modpacks.modpacks.curseforge_api_key');

        if (!$apiKey) {
            throw new \RuntimeException("CurseForge API key not configured.");
        }

        $cacheKey = "modpack.curseforge.$versionId";

        return Cache::remember($cacheKey, 600, function () use ($projectId, $versionId, $apiKey) {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
            ])->get("https://api.curseforge.com/v1/mods/{$projectId}/files/{$versionId}");

            if (!$response->ok()) {
                throw new \RuntimeException("CurseForge API error: " . $response->status());
            }

            $data = $response->json()['data'] ?? [];

            // CurseForge serverPackFileId indicates there's a separate server pack
            $serverPackId = $data['serverPackFileId'] ?? null;
            $downloadUrl = $data['downloadUrl'] ?? null;

            if ($serverPackId && $serverPackId !== $versionId) {
                // Fetch the actual server pack
                $spResponse = Http::withHeaders(['x-api-key' => $apiKey])
                    ->get("https://api.curseforge.com/v1/mods/{$projectId}/files/{$serverPackId}");

                if ($spResponse->ok()) {
                    $spData = $spResponse->json()['data'] ?? [];
                    $downloadUrl = $spData['downloadUrl'] ?? $downloadUrl;
                }
            }

            if (!$downloadUrl) {
                throw new \RuntimeException("CurseForge file has no download URL (may require manual download).");
            }

            // Extract loader from game versions
            $gameVersions = $data['gameVersions'] ?? [];
            $loader = 'auto';
            foreach ($gameVersions as $v) {
                if (str_starts_with(strtolower($v), 'forge')) $loader = 'forge';
                if (str_starts_with(strtolower($v), 'fabric')) $loader = 'fabric';
                if (str_starts_with(strtolower($v), 'neoforge')) $loader = 'neoforge';
            }

            $mcVersion = collect($gameVersions)
                ->first(fn($v) => preg_match('/^\d+\.\d+/', $v));

            return [
                'download_url' => $downloadUrl,
                'project_id'   => $projectId,
                'version_id'   => $versionId,
                'file_size'    => $data['fileLength'] ?? null,
                'version'      => $data['displayName'] ?? $versionId,
                'mc_version'   => $mcVersion ?? '',
                'loader'       => $loader,
                'loader_version' => 'latest',
                'server_pack'    => (bool) $serverPackId,
            ];
        });
    }

    // ─── FTB ─────────────────────────────────────────────────────────────────

    private function resolveFTB(string $projectId, string $versionId): array
    {
        $cacheKey = "modpack.ftb.$projectId.$versionId";

        return Cache::remember($cacheKey, 600, function () use ($projectId, $versionId) {
            $response = Http::get("https://api.feed-the-beast.com/v1/modpacks/public/modpack/{$projectId}/{$versionId}");

            if (!$response->ok()) {
                throw new \RuntimeException("FTB API error: " . $response->status());
            }

            $data = $response->json();

            // FTB provides server files separately
            $serverFiles = collect($data['files'] ?? [])
                ->filter(fn($f) => isset($f['serverOnly']) && $f['serverOnly'])
                ->first();

            $file = $serverFiles ?? collect($data['files'] ?? [])->first();

            if (!$file) {
                throw new \RuntimeException("No files found for this FTB version.");
            }

            $downloadUrl = "https://api.feed-the-beast.com/v1/modpacks/public/modpack/{$projectId}/{$versionId}/file/{$file['id']}";

            $targets = $data['targets'] ?? [];
            $loader = 'forge'; // FTB almost always uses Forge
            $mcVersion = '';

            foreach ($targets as $target) {
                if ($target['type'] === 'game') $mcVersion = $target['version'] ?? '';
                if ($target['type'] === 'modloader') {
                    $loader = $this->normalizeLoader($target['name'] ?? 'forge');
                }
            }

             return [
        'download_url'   => '',
        'file_size'      => null,
        'version'        => $versionId,
        'mc_version'     => '',
        'loader'         => 'forge',
        'loader_version' => 'latest',
        'server_pack'    => true,
        'project_id'     => $projectId,
        'version_id'     => $versionId,
    ];
        });
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function normalizeLoader(string $raw): string
    {
        return match (strtolower($raw)) {
            'forge', 'minecraftforge' => 'forge',
            'fabric', 'fabric-loader' => 'fabric',
            'neoforge'                => 'neoforge',
            'quilt'                   => 'quilt',
            default                   => 'auto',
        };
    }
}
