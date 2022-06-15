<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AppsController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\RoutingController;
use App\Http\Controllers\MessagesController;
use App\Http\Controllers\ExtensionsController;
use App\Http\Controllers\SmsWebhookController;


/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::group(['middleware' => 'auth'], function(){
    Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');
    Route::get('/', [App\Http\Controllers\HomeController::class, 'index'])->name('home');
    Route::get('/logout', [App\Http\Controllers\Auth\LoginController::class, 'logout'])->name('logout');
    Route::resource('devices', 'DeviceController');
    Route::post('/domains/switch', [DomainController::class, 'switchDomain'])->name('switchDomain');
    Route::get('/domains/switch/{domain}', [DomainController::class, 'switchDomainFusionPBX'])->name('switchDomainFusionPBX');

    //Users
    Route::get('/users', [UsersController::class, 'index']) ->name('usersList');
    Route::get('/users/create', [UsersController::class, 'createUser']) ->name('usersCreateUser');
    Route::get('/users/edit/{id}', [UsersController::class, 'editUser']) ->name('editUser');
    Route::post('/saveUser', [UsersController::class, 'saveUser']) ->name('saveUser');
    Route::post('/updateUser', [UsersController::class, 'updateUser']) ->name('updateUser');
    Route::post('/deleteUser', [UsersController::class, 'deleteUser']) ->name('deleteUser');

    //Extensions
    Route::get('/extensions/callerid', [ExtensionsController::class, 'callerID']) ->name('callerID');
    Route::post('/extensions/callerid/update/{id}', [ExtensionsController::class, 'updateCallerID']) ->name('updateCallerID');
    // Route::get('/extensions', [ExtensionsController::class, 'index']) ->name('extensionsList');
    Route::resource('extensions', 'ExtensionsController');

    //Apps
    Route::get('/apps', [AppsController::class, 'index']) ->name('appsStatus');
    Route::post('/apps/organization/create', [AppsController::class, 'createOrganization']) ->name('appsCreateOrganization');
    //Route::get('/apps/organization/update', [AppsController::class, 'updateOrganization']) ->name('appsUpdateOrganization');
    Route::post('/apps/connection/create', [AppsController::class, 'createConnection']) ->name('appsCreateConnection');
    Route::get('/apps/connection/update', [AppsController::class, 'updateConnection']) ->name('appsUpdateConnection');

    // SMS for testing
    // Route::get('/sms/ringotelwebhook', [SmsWebhookController::class,"messageFromRingotel"]);

    // Messages
    Route::get('/messages', [MessagesController::class, 'index']) ->name('messagesStatus');
});

// Route::group(['prefix' => '/'], function () {
//     Route::get('', [RoutingController::class, 'index'])->name('root');
//     Route::get('{first}/{second}/{third}', [RoutingController::class, 'thirdLevel'])->name('third');
//     Route::get('{first}/{second}', [RoutingController::class, 'secondLevel'])->name('second');
//     Route::get('/any/{any}', [RoutingController::class, 'root'])->name('any');
// });

Auth::routes();

//Route::get('/dashboard', [App\Http\Controllers\HomeController::class, 'index'])->name('dashboard');
