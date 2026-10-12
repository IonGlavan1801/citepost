<?php

use App\Http\Controllers\LabController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('lab'))->name('home');
Route::get('/api/health', fn () => response()->json(['status' => 'ok', 'app' => 'citepost']));
Route::prefix('api')->middleware('throttle:lab-api')->group(function () {
    Route::get('/state', [LabController::class, 'state']);
    Route::post('/queries', [LabController::class, 'query'])->middleware('throttle:lab-query');
    Route::get('/queries/{id}', [LabController::class, 'historical']);
    Route::post('/queries/{id}/feedback', [LabController::class, 'feedback']);
    Route::post('/documents', [LabController::class, 'upload'])->middleware('throttle:lab-upload');
    Route::delete('/documents/{id}', [LabController::class, 'delete']);
    Route::post('/documents/{id}/reindex', [LabController::class, 'reindex'])->middleware('throttle:lab-reindex');
    Route::get('/sources/{version}', [LabController::class, 'source']);
});
