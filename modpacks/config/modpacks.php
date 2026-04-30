<?php

// config/pterodactyl.php - add this array inside the existing config or create standalone
// as config/modpacks.php and reference as config('modpacks.*')

return [

    /*
    |--------------------------------------------------------------------------
    | Modpack Integration Settings
    |--------------------------------------------------------------------------
    */

    'modpacks' => [

        // CurseForge API key — get one at https://console.curseforge.com/
        // Set via environment: CURSEFORGE_API_KEY=your_key
        'curseforge_api_key' => env('CURSEFORGE_API_KEY', ''),

        // Max modpack file size in bytes (default 5 GB)
        'max_file_size' => env('MODPACK_MAX_SIZE', 5 * 1024 * 1024 * 1024),

        // Cache TTL in seconds for provider API responses
        'cache_ttl' => env('MODPACK_CACHE_TTL', 600),

        // Path on each node where modpacks are cached (must exist on all nodes)
        // This is inside Wings' storage, not the panel server
        'node_cache_path' => '/var/lib/pterodactyl/modpacks-cache',

        // Allowed providers
        'providers' => ['modrinth', 'curseforge', 'ftb'],

        // Modrinth user agent (required by their API ToS)
        'modrinth_user_agent' => env('MODRINTH_USER_AGENT', 'PterodactylModpacks/1.0 (contact@example.com)'),
    ],

'eggs' => [
    'curseforge' => 16,
    'modrinth'   => 18, // ajusta cuando tengas el egg de modrinth
    'ftb'        => 17,
],

];
