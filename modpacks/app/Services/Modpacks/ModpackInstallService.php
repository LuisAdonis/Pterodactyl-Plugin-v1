<?php

namespace Pterodactyl\Services\Modpacks;

use Exception;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Repositories\Wings\DaemonPowerRepository;
use Pterodactyl\Services\Servers\StartupModificationService;

class ModpackInstallService
{
    // Egg UUID that supports modpack installation via env vars.
    // Replace this with your actual modpack egg UUID after importing it.
    public const MODPACK_EGG_UUID = 'modpack-universal-egg-uuid';

    public function __construct(
        private readonly ModpackResolverService $resolver,
        private readonly DaemonServerRepository $daemonServer,
        private readonly DaemonPowerRepository $daemonPower,
        private readonly StartupModificationService $startupModification,
    ) {}

    /**
     * Full install flow:
     * 1. Resolve download URL from provider API
     * 2. Stop server if running
     * 3. Update startup environment variables
     * 4. Trigger Wings reinstall
     */
    public function install(
        Server $server,
        string $provider,
        string $projectId,
        string $versionId,
        bool $wipeServer = true,
    ): void {
        // Step 1: Resolve the actual download URL and metadata
        $modpack = $this->resolver->resolve($provider, $projectId, $versionId);

        // Step 2: Validate the modpack has server files
        $this->validateServerPack($modpack);

        // Step 3: Stop the server if it's currently running
        $this->stopServerIfRunning($server);
        $this->switchEggForProvider($server, $provider);


        // Step 4: Update startup environment variables so install script knows what to download
    $this->updateStartupVariables($server, $modpack, $provider, $projectId, $versionId, $wipeServer);

        // Step 5: Trigger Wings reinstall — this executes the egg install script
        $this->triggerReinstall($server);

        Log::info("Modpack install triggered", [
            'server' => $server->uuid,
            'provider' => $provider,
            'project' => $projectId,
            'version' => $versionId,
            'url' => $modpack['download_url'],
        ]);
    }

    /**
     * Query Wings for current server status to determine install state.
     */
  public function getStatus(Server $server): array
{
    try {
        $details = $this->daemonServer->setServer($server)->getDetails();
        $state = $details['state'] ?? 'unknown';

        return [
            'state' => $state,
            'installing' => in_array($state, ['installing', 'install_failed']),
        ];
    } catch (Exception $e) {
        return ['state' => 'unknown', 'installing' => false];
    }
}

    private function validateServerPack(array $modpack): void

    {
          Log::info("CF Versions - Body: " ,$modpack);
        if (empty($modpack['download_url'])) {
            throw new \RuntimeException('Modpack does not have a valid download URL.');
        }

        $maxBytes = 5 * 1024 * 1024 * 1024; // 5 GB
        if (isset($modpack['file_size']) && $modpack['file_size'] > $maxBytes) {
            throw new \RuntimeException('Modpack file exceeds 5 GB limit.');
        }
    }
private function stopServerIfRunning(Server $server): void
{
    try {
        $details = $this->daemonServer->setServer($server)->getDetails();
        $state = $details['state'] ?? 'offline';

        Log::info("Estado del servidor antes de instalar: $state");

        if (!in_array($state, ['offline'])) {
            $this->daemonPower->setServer($server)->send('kill');
            sleep(5);

            $details = $this->daemonServer->setServer($server)->getDetails();
            Log::info("Estado después de kill: " . ($details['state'] ?? 'unknown'));
        }
    } catch (DaemonConnectionException $e) {
        Log::warning("Could not stop server: " . $e->getMessage());
    }
}
   
private function switchEggForProvider(Server $server, string $provider): void
{
    $eggMap = config('modpacks.eggs', []);
    $eggId = $eggMap[$provider] ?? null;

    if (!$eggId) {
        Log::warning("No egg configurado para provider: $provider");
        return;
    }

    if ($server->egg_id !== $eggId) {
        Log::info("Cambiando egg de {$server->egg_id} a $eggId para provider $provider");
        $server->update(['egg_id' => $eggId]);
        $server->refresh();
    }
}

private function updateStartupVariables(
    Server $server,
    array $modpack,
    string $provider,
    string $projectId,
    string $versionId,
    bool $wipeServer,
): void {
  $environment = match($provider) {
    'curseforge' => [
        'PROJECT_ID' => (string) $projectId,
        'VERSION_ID' => (string) $versionId,
        'API_KEY'    => (string) config('modpacks.modpacks.curseforge_api_key'),
    ],
    'ftb' => [
        'FTB_MODPACK_ID'         => (string) $projectId,
        'FTB_MODPACK_VERSION_ID' => (string) $versionId,
        'FTB_VERSION_STRING'     => '',
        'FTB_SEARCH_TERM'        => '',
    ],
    default => [
        'MODPACK_URL'     => (string) ($modpack['download_url'] ?? ''),
        'MODPACK_TYPE'    => (string) $provider,
        'MC_VERSION'      => (string) ($modpack['mc_version'] ?? ''),
        'LOADER'          => (string) ($modpack['loader'] ?? 'auto'),
        'LOADER_VERSION'  => (string) ($modpack['loader_version'] ?? 'latest'),
        'WIPE_SERVER'     => $wipeServer ? '1' : '0',
    ],
};

    Log::info("Actualizando variables", $environment);

    foreach ($environment as $key => $value) {
        $eggVariable = \Pterodactyl\Models\EggVariable::where('egg_id', $server->egg_id)
            ->where('env_variable', $key)
            ->first();

        if (!$eggVariable) {
            Log::warning("EggVariable no encontrada: $key para egg_id: {$server->egg_id}");
            continue;
        }

        \Pterodactyl\Models\ServerVariable::updateOrCreate(
            ['server_id' => $server->id, 'variable_id' => $eggVariable->id],
            ['variable_value' => $value]
        );

        Log::info("Variable guardada: $key = $value");
    }
}
   
    private function triggerReinstall(Server $server): void
{
    $server->update(['status' => Server::STATUS_INSTALLING]);

    $this->daemonServer->setServer($server)->reinstall();
}
}
