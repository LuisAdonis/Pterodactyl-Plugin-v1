<?php

namespace Pterodactyl\Services\Mods;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;

class ModInstallService
{
    public function __construct(
        private readonly DaemonFileRepository $daemonFile,
    ) {}

    public function install(
        Server $server,
        string $provider,
        string $projectId,
        string $versionId,
        string $filename,
        string $downloadUrl,
        ?string $oldFilename = null,
    ): array {
        try {
            // Borrar mod viejo si existe
            if ($oldFilename) {
                try {
                    $this->daemonFile->setServer($server)->deleteFiles('/mods', [$oldFilename]);
                    Log::info("Old mod deleted", [
                        'server'   => $server->uuid,
                        'filename' => $oldFilename,
                    ]);
                } catch (\Exception $e) {
                    Log::warning("Failed to delete old mod: " . $e->getMessage());
                }
            }

            if ($provider === 'curseforge') {
                $apiKey = config('mods.curseforge_api_key', '');

                $response = Http::withHeaders(['x-api-key' => $apiKey])
                    ->get("https://api.curseforge.com/v1/mods/{$projectId}/files/{$versionId}");

                if (!$response->ok()) {
                    Log::error("CurseForge file metadata failed", [
                        'status' => $response->status(),
                        'body'   => $response->body(),
                    ]);
                    return ['status' => 'error', 'message' => 'CurseForge API error: ' . $response->status()];
                }

                $fileData = $response->json('data');
                $realFilename = $fileData['fileName'] ?? $filename;
                $fileId = $fileData['id'] ?? $versionId;

                $fileIdStr = (string) $fileId;
                $partA = substr($fileIdStr, 0, -3);
                $partB = substr($fileIdStr, -3);
                $cdnUrl = "https://mediafilez.forgecdn.net/files/{$partA}/{$partB}/{$realFilename}";

                Log::info("CurseForge CDN resolved", [
                    'cdn_url'   => $cdnUrl,
                    'file_name' => $realFilename,
                ]);

                // Descargar el archivo desde el panel (no Wings)
                $httpClient = new Client(['timeout' => 120, 'stream' => true]);
                $dlResponse = $httpClient->get($cdnUrl);
                $content = $dlResponse->getBody()->getContents();

                // Subir contenido directamente a Wings
                $this->daemonFile->setServer($server)->putContent('/mods/' . $realFilename, $content);

                Log::info("CurseForge mod installed via panel download", [
                    'server'   => $server->uuid,
                    'filename' => $realFilename,
                    'size'     => strlen($content),
                ]);
            } else {
                // Modrinth: descargar desde panel y subir
                $httpClient = new Client(['timeout' => 120, 'stream' => true]);
                $dlResponse = $httpClient->get($downloadUrl);
                $content = $dlResponse->getBody()->getContents();

                $this->daemonFile->setServer($server)->putContent('/mods/' . $filename, $content);

                Log::info("Mod installed via panel download", [
                    'server'   => $server->uuid,
                    'filename' => $filename,
                    'size'     => strlen($content),
                ]);
            }

            return ['status' => 'success'];
        } catch (\Exception $e) {
            Log::error("Mod install failed", [
                'server' => $server->uuid,
                'error'  => $e->getMessage(),
            ]);

            return [
                'status'  => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    public function getInstalledMods(Server $server): array
    {
        try {
            $files = $this->daemonFile->setServer($server)->getDirectory('/mods');

            $installedMods = [];
            foreach ($files as $file) {
                $name = $file['name'] ?? '';
                if (str_ends_with(strtolower($name), '.jar') ||
                    str_ends_with(strtolower($name), '.jar.disabled')) {
                    $installedMods[] = [
                        'name'        => $name,
                        'size'        => $file['size'] ?? null,
                        'provider_id' => $this->extractProviderId($name),
                    ];
                }
            }

            return $installedMods;
        } catch (\Exception $e) {
            Log::warning("Could not list mods: " . $e->getMessage());
            return [];
        }
    }

    public function isModInstalled(Server $server, string $provider, string $projectId): bool
    {
        $installed = $this->getInstalledMods($server);

        foreach ($installed as $mod) {
            if ($mod['provider_id'] !== null && $mod['provider_id'] === $projectId) {
                return true;
            }
        }

        return false;
    }

    public function getStatus(Server $server): array
    {
        return ['state' => 'ready', 'installing' => false];
    }

    public function deleteMod(Server $server, string $filename): bool
    {
        try {
            $this->daemonFile->setServer($server)->deleteFiles('/mods', [$filename]);
            Log::info("Mod deleted", [
                'server' => $server->uuid,
                'file'   => $filename,
            ]);
            return true;
        } catch (\Exception $e) {
            Log::warning("Failed to delete mod: " . $e->getMessage());
            return false;
        }
    }

    private function extractProviderId(string $filename): ?string
    {
        if (preg_match('/\[curseforge-(\d+)\]/i', $filename, $matches)) {
            return $matches[1];
        }

        if (preg_match('/\[modrinth-(\d+)\]/i', $filename, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
