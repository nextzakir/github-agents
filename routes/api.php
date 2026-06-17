<?php

use App\Http\Controllers\RepositoryAssistants\ChatCompletionsController;
use App\Http\Controllers\RepositoryAssistants\ConversationsController;
use App\Http\Controllers\RepositoryAssistants\ModelsController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/{repository}/models', ModelsController::class)
        ->where('repository', '[^/]+(?:/[^/]+)?');

    Route::post('/{repository}/chat/completions', ChatCompletionsController::class)
        ->where('repository', '[^/]+(?:/[^/]+)?');

    Route::get('/{repository}/conversations', ConversationsController::class)
        ->where('repository', '[^/]+(?:/[^/]+)?');
});
