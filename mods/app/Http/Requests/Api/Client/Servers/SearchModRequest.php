<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers;

use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class SearchModRequest extends ClientApiRequest
{
    public function rules(): array
    {
        return [
            'provider'   => ['required', 'string', 'in:modrinth,curseforge'],
            'query'      => ['required', 'string', 'min:2', 'max:100'],
            'mc_version' => ['sometimes', 'nullable', 'string', 'max:20'],
            'loader'     => ['sometimes', 'nullable', 'string', 'in:forge,fabric,neoforge,quilt'],
        ];
    }
}
