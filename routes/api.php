<?php

use App\Http\Controllers\RepositoryAssistants\ChatCompletionsController;
use App\Http\Controllers\RepositoryAssistants\ConversationsController;
use App\Http\Controllers\RepositoryAssistants\ModelsController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/models', ModelsController::class);

    Route::post('/chat/completions', ChatCompletionsController::class);

    Route::get('/conversations', ConversationsController::class);
});
