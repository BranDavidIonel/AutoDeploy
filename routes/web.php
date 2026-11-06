<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\Projects\BuildController;
use App\Http\Controllers\Projects\EnvironmentController;
use App\Http\Controllers\Projects\HookController;
use App\Http\Controllers\Projects\ReleaseController;
use App\Http\Controllers\Projects\SetupController;
use App\Http\Controllers\Projects\WorkerController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::put('locale', [LocaleController::class, 'update'])->name('locale.update');

// GitHub "push" webhook (no session / CSRF; the token in the URL is the secret).
Route::post('webhook/{token}', WebhookController::class)->middleware('throttle:30,1')->name('webhook');

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:login')->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('account', [AccountController::class, 'edit'])->name('account.edit');
    Route::put('account/profile', [AccountController::class, 'updateProfile'])->name('account.profile');
    Route::put('account/password', [AccountController::class, 'updatePassword'])->name('account.password');

    // Servers
    Route::resource('servers', ServerController::class);
    Route::controller(ServerController::class)->prefix('servers/{server}')->name('servers.')->group(function () {
        Route::post('test', 'test')->name('test');
        Route::post('ssh-key', 'generateKey')->name('ssh-key');
        Route::post('forget-host-key', 'forgetFingerprint')->name('forget-host-key');
    });

    // Projects
    Route::resource('projects', ProjectController::class);

    Route::prefix('projects/{project}')->name('projects.')->scopeBindings()->group(function () {
        Route::post('deploy', [DeploymentController::class, 'store'])->name('deploy');
        Route::post('webhook/regenerate', [ProjectController::class, 'regenerateWebhook'])->name('webhook.regenerate');

        Route::get('releases', [ReleaseController::class, 'index'])->name('releases.index');
        Route::post('releases/sync', [ReleaseController::class, 'sync'])->name('releases.sync');
        Route::post('releases/{deployment}/rollback', [ReleaseController::class, 'rollback'])->name('releases.rollback');

        Route::get('builds', [BuildController::class, 'index'])->name('builds.index');
        Route::post('builds', [BuildController::class, 'store'])->name('builds.store');
        Route::get('builds/{build}/download', [BuildController::class, 'download'])->name('builds.download');
        Route::delete('builds/{build}', [BuildController::class, 'destroy'])->name('builds.destroy');

        Route::get('deploy-script', [HookController::class, 'index'])->name('hooks.index');
        Route::post('hooks', [HookController::class, 'store'])->name('hooks.store');
        Route::post('hooks/reorder', [HookController::class, 'reorder'])->name('hooks.reorder');
        Route::put('hooks/{hook}', [HookController::class, 'update'])->name('hooks.update');
        Route::delete('hooks/{hook}', [HookController::class, 'destroy'])->name('hooks.destroy');

        Route::get('workers', [WorkerController::class, 'index'])->name('workers.index');
        Route::post('workers/quick-setup', [WorkerController::class, 'quickSetup'])->name('workers.quick-setup');
        Route::post('workers/scheduler', [WorkerController::class, 'scheduler'])->name('workers.scheduler');
        Route::post('workers', [WorkerController::class, 'store'])->name('workers.store');
        Route::put('workers/{worker}', [WorkerController::class, 'update'])->name('workers.update');
        Route::delete('workers/{worker}', [WorkerController::class, 'destroy'])->name('workers.destroy');
        Route::get('workers/{worker}/logs', [WorkerController::class, 'logs'])->name('workers.logs');
        Route::post('workers/{worker}/{action}', [WorkerController::class, 'action'])
            ->whereIn('action', ['install', 'start', 'stop', 'restart', 'status'])
            ->name('workers.action');

        Route::get('environment', [EnvironmentController::class, 'edit'])->name('environment.edit');
        Route::put('environment', [EnvironmentController::class, 'update'])->name('environment.update');

        Route::get('setup', [SetupController::class, 'show'])->name('setup.show');
        Route::post('setup', [SetupController::class, 'run'])->name('setup.run');
    });

    // Deployments
    Route::get('deployments/{deployment}', [DeploymentController::class, 'show'])->name('deployments.show');
    Route::post('deployments/{deployment}/cancel', [DeploymentController::class, 'cancel'])->name('deployments.cancel');
    Route::post('deployments/{deployment}/redeploy', [DeploymentController::class, 'redeploy'])->name('deployments.redeploy');
});
