<?php

use App\Http\Controllers\ApiAuthController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\QuestionOptionController;
use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/token', [ApiAuthController::class, 'store'])->middleware('throttle:5,1');

    Route::middleware([AuthenticateApiToken::class, 'can:admin'])->group(function (): void {
        Route::delete('/auth/token', [ApiAuthController::class, 'destroy']);
        Route::get('/accounting-entries', [ImportController::class, 'apiAllData']);
        Route::get('/imports', [ImportController::class, 'apiIndex']);
        Route::post('/imports', [ImportController::class, 'apiStore']);
        Route::get('/imports/{import}', [ImportController::class, 'apiShow'])->whereNumber('import');
        Route::get('/imports/{import}/entries', [ImportController::class, 'apiEntries'])->whereNumber('import');
        Route::patch('/imports/{import}', [ImportController::class, 'apiUpdate'])->whereNumber('import');
        Route::delete('/imports/{import}', [ImportController::class, 'apiDestroy'])->whereNumber('import');

        Route::post('/question-options/import', [QuestionOptionController::class, 'apiBulkStore']);
        Route::get('/question-options', [QuestionOptionController::class, 'apiIndex']);
        Route::post('/question-options', [QuestionOptionController::class, 'storeSingle']);
        Route::get('/question-options/{option}', [QuestionOptionController::class, 'apiShow'])->whereNumber('option');
        Route::patch('/question-options/{option}', [QuestionOptionController::class, 'update'])->whereNumber('option');
        Route::delete('/question-options/{option}', [QuestionOptionController::class, 'destroy'])->whereNumber('option');
    });
});
