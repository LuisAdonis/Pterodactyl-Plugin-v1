<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers;

use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class InstallModRequest extends ClientApiRequest
{
    public function rules(): array
    {
        return [
            'provider'    => ['required', 'string', 'in:modrinth,curseforge'],
            'project_id'  => ['required', 'string', 'max:100'],
            'version_id'  => ['required', 'string', 'max:100'],
            'filename'    => ['required', 'string', 'max:255'],
            'download_url' => ['required', 'string', 'url'],
            'old_filename' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
