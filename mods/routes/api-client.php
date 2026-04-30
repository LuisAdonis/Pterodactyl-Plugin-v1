<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\Http\Controllers\Api\Client;
use Pterodactyl\Http\Middleware\Activity\ServerSubject;
use Pterodactyl\Http\Middleware\Api\Client\Server\ResourceBelongsToServer;
use Pterodactyl\Http\Middleware\Api\Client\Server\AuthenticateServerAccess;

Route::group([
    'prefix' => '/servers/{server}',
    'middleware' => [
        ServerSubject::class,
        AuthenticateServerAccess::class,
        ResourceBelongsToServer::class,
    ],
], function () {
    Route::group(['prefix' => '/mods'], function () {
        Route::get('/search', [Client\Servers\ModController::class, 'search']);
        Route::get('/{provider}/{projectId}/versions', [Client\Servers\ModController::class, 'versions']);
        Route::get('/installed', [Client\Servers\ModController::class, 'installed']);
        Route::get('/status', [Client\Servers\ModController::class, 'status']);
        Route::post('/install', [Client\Servers\ModController::class, 'install']);
        Route::delete('/{filename}', [Client\Servers\ModController::class, 'delete']);
    });
});
