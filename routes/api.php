<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ProductController;

Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{id}', [ProductController::class, 'show']);
Route::get('/products/code/{code}', [ProductController::class, 'showByCode']);
//Route::get('/main', [MainPageController::class, 'index']);

//для тестирования записи
Route::post('/products', [ProductController::class, 'store']);


