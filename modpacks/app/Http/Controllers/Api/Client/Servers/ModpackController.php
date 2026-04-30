<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\InstallModpackRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\SearchModpackRequest;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Modpacks\ModpackInstallService;
use Pterodactyl\Services\Modpacks\ModpackSearchService;

class ModpackController extends ClientApiController
{
    public function __construct(
        private readonly ModpackInstallService $installService,
        private readonly ModpackSearchService $searchService,
    ) {
        parent::__construct();
    }

    /**
     * Search modpacks from external provider.
     */
    public function search(SearchModpackRequest $request, Server $server): JsonResponse
    {
        $results = $this->searchService->search(
            provider: $request->input('provider'),
            query: $request->input('query'),
            mcVersion: $request->input('mc_version'),
            loader: $request->input('loader'),
        );

        return new JsonResponse(['data' => $results]);
    }

    /**
     * Get versions for a specific modpack.
     */
    public function versions(Request $request, Server $server, string $provider, string $projectId): JsonResponse
    {
        $versions = $this->searchService->getVersions(
            provider: $provider,
            projectId: $projectId,
        );

        return new JsonResponse(['data' => $versions]);
    }

    /**
     * Install a modpack on an existing server.
     * Stops server → updates startup env vars → triggers reinstall via Wings.
     */
    public function install(InstallModpackRequest $request, Server $server): JsonResponse
    {
        $this->installService->install(
            server: $server,
            provider: $request->input('provider'),
            projectId: $request->input('project_id'),
            versionId: $request->input('version_id'),
            wipeServer: $request->boolean('wipe_server', true),
        );

        return new JsonResponse(['status' => 'installing']);
    }

    /**
     * Get current installation status.
     */
    public function status(Request $request, Server $server): JsonResponse
    {
        $status = $this->installService->getStatus($server);

        return new JsonResponse(['data' => $status]);
    }
}
