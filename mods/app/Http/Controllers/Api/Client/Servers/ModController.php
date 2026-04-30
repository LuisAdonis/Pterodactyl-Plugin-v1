<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\InstallModRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\SearchModRequest;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Mods\ModInstallService;
use Pterodactyl\Services\Mods\ModSearchService;

class ModController extends ClientApiController
{
    public function __construct(
        private readonly ModInstallService $installService,
        private readonly ModSearchService $searchService,
    ) {
        parent::__construct();
    }

    public function search(SearchModRequest $request, Server $server): JsonResponse
    {
        $results = $this->searchService->search(
            provider: $request->input('provider'),
            query: $request->input('query'),
            mcVersion: $request->input('mc_version'),
            loader: $request->input('loader'),
        );

        $installedMap = $this->getInstalledMap($server);

        $results = array_map(function ($mod) use ($installedMap) {
            $mod['installed'] = isset($installedMap[$mod['id']]);
            if ($mod['installed']) {
                $mod['installed_filename'] = $installedMap[$mod['id']];
            }
            return $mod;
        }, $results);

        return new JsonResponse(['data' => $results]);
    }

    public function versions(Request $request, Server $server, string $provider, string $projectId): JsonResponse
    {
        $versions = $this->searchService->getVersions(
            provider: $provider,
            projectId: $projectId,
        );

        return new JsonResponse(['data' => $versions]);
    }

    public function install(InstallModRequest $request, Server $server): JsonResponse
    {
        $this->installService->install(
            server: $server,
            provider: $request->input('provider'),
            projectId: $request->input('project_id'),
            versionId: $request->input('version_id'),
            filename: $request->input('filename'),
            downloadUrl: $request->input('download_url'),
            oldFilename: $request->input('old_filename'),
        );

        return new JsonResponse(['status' => 'installing']);
    }

    public function installed(Server $server): JsonResponse
    {
        $mods = $this->installService->getInstalledMods($server);

        return new JsonResponse(['data' => $mods]);
    }

    public function status(Request $request, Server $server): JsonResponse
    {
        $status = $this->installService->getStatus($server);

        return new JsonResponse(['data' => $status]);
    }

    public function delete(Request $request, Server $server, string $filename): JsonResponse
    {
        $deleted = $this->installService->deleteMod($server, $filename);

        return new JsonResponse(['data' => ['deleted' => $deleted]]);
    }

    private function getInstalledMap(Server $server): array
    {
        $installed = $this->installService->getInstalledMods($server);
        $map = [];
        foreach ($installed as $mod) {
            if ($mod['provider_id'] !== null) {
                $map[$mod['provider_id']] = $mod['name'];
            }
        }
        return $map;
    }
}
