<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers;

use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class InstallModpackRequest extends ClientApiRequest
{
    public function rules(): array
    {
        return [
            'provider'   => ['required', 'string', 'in:modrinth,curseforge,ftb'],
            'project_id' => ['required', 'string', 'max:100'],
            'version_id' => ['required', 'string', 'max:100'],
            'wipe_server' => ['sometimes', 'boolean'],
        ];
    }
}
