<?php

use App\Domains\Contact\ManageTags\Api\Controllers\ContactTagController;
use App\Domains\Contact\ManageTags\Api\Controllers\TagController;
use App\Domains\Settings\ManageUsers\Api\Controllers\UserController;
use App\Domains\Vault\ManageVault\Api\Controllers\VaultController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->name('api.')->group(function () {
    // users
    Route::get('user', [UserController::class, 'user']);
    Route::apiResource('users', UserController::class)->only(['index', 'show']);

    // vaults
    Route::apiResource('vaults', VaultController::class);

    // tags + contact-tag endpoints, scoped under a vault
    Route::prefix('vaults/{vaultId}')->group(function () {
        Route::get('tags',            [TagController::class, 'index'])->name('tags.index');
        Route::post('tags',           [TagController::class, 'store'])->name('tags.store');
        Route::put('tags/{tagId}',    [TagController::class, 'update'])->name('tags.update');
        Route::delete('tags/{tagId}', [TagController::class, 'destroy'])->name('tags.destroy');

        Route::get('contacts',                              [ContactTagController::class, 'index'])->name('contacts.index');
        Route::post('contacts/{contactId}/tags',            [ContactTagController::class, 'store'])->name('contacts.tags.store');
        Route::delete('contacts/{contactId}/tags/{tagId}',  [ContactTagController::class, 'destroy'])->name('contacts.tags.destroy');
    });
});
