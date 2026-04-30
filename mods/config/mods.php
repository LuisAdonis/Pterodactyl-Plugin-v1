<?php

return [

    'curseforge_api_key' => env('CURSEFORGE_API_KEY', ''),

    'max_file_size' => env('MOD_MAX_SIZE', 500 * 1024 * 1024),

    'cache_ttl' => env('MOD_CACHE_TTL', 600),

    'providers' => ['modrinth', 'curseforge'],

    'modrinth_user_agent' => env('MODRINTH_USER_AGENT', 'PterodactylMods/1.0 (contact@example.com)'),

];
