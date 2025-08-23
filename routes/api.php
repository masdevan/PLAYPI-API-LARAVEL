<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\VideoUploadController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/videos', [VideoUploadController::class, 'VideoUpload']);
Route::get('/videos', [VideoUploadController::class, 'index']);
Route::get('/videos/{id}', [VideoUploadController::class, 'show']);

Route::get('/videos/{id}/stream/{resolution}', [VideoUploadController::class, 'streamByResolution']);
Route::get('/videos/{id}/download/{resolution}', [VideoUploadController::class, 'downloadByResolution']);
