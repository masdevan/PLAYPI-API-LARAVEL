<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\VideoUploadController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Video Upload
Route::post('/videos', [VideoUploadController::class, 'VideoUpload']);
Route::get('/videos', [VideoUploadController::class, 'index']);
Route::get('/videos/{id}', [VideoUploadController::class, 'show']);
Route::get('/download/{filename}', [VideoUploadController::class, 'downloadByFilename']);
Route::get('/stream/{filename}', [VideoUploadController::class, 'streamByFilename']);
Route::get('/thumbnail/{filename}', [VideoUploadController::class, 'thumbnailByFilename']);
