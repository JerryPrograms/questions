<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DeveloperOptionController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\ManagerController;
use App\Http\Controllers\QuestionController;
use App\Http\Controllers\QuestionOptionController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::get('/login/verify', [AuthController::class, 'showVerification'])->name('login.verify');
    Route::post('/login/verify', [AuthController::class, 'verify'])->middleware('throttle:6,1')->name('login.verify.submit');
    Route::post('/login/verify/resend', [AuthController::class, 'resend'])->middleware('throttle:3,1')->name('login.verify.resend');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/dashboard', function () {
        return match (auth()->user()->role) {
            'super_admin',
            'admin' => redirect()->route('admin.dashboard'),
            'manager' => redirect()->route('manager.dashboard'),
            default => abort(403),
        };
    })->name('dashboard');

    Route::middleware('can:admin')->group(function (): void {
        Route::get('/admin', [ManagerController::class, 'index'])->name('admin.dashboard');
        Route::get('/admin/logs', [AuditLogController::class, 'index'])->name('admin.audit-logs.index');
        Route::get('/admin/dashboard/data', [ManagerController::class, 'dashboardData'])->name('admin.dashboard.data');
        Route::post('/admin/managers', [ManagerController::class, 'store'])->name('admin.managers.store');
        Route::get('/admin/manager-locations', [ManagerController::class, 'locations'])->name('admin.manager-locations.index');
        Route::get('/admin/manager-locations/data', [ManagerController::class, 'locationAssignmentsData'])->name('admin.manager-locations.data');
        Route::post('/admin/managers/{manager}/locations', [ManagerController::class, 'updateLocations'])->name('admin.managers.locations.update');
        Route::get('/admin/imports', [ImportController::class, 'index'])->name('admin.imports.index');
        Route::get('/admin/imports/data', [ImportController::class, 'indexData'])->name('admin.imports.data');
        Route::get('/admin/imports/create', [ImportController::class, 'create'])->name('admin.imports.create');
        Route::post('/admin/imports', [ImportController::class, 'store'])->name('admin.imports.store');
        Route::get('/admin/imports/{import}/data', [ImportController::class, 'showData'])->whereNumber('import')->name('admin.imports.show-data');
        Route::get('/admin/imports/{import}/export', [ImportController::class, 'export'])->whereNumber('import')->name('admin.imports.export');
        Route::get('/admin/imports/{import}', [ImportController::class, 'show'])->whereNumber('import')->name('admin.imports.show');
        Route::redirect('/admin/uploaded-data', '/admin/imports');
        Route::get('/admin/questions', [QuestionController::class, 'index'])->name('admin.questions.index');
        Route::get('/admin/question-options', [QuestionOptionController::class, 'index'])->name('admin.question-options.index');
        Route::post('/admin/question-options', [QuestionOptionController::class, 'store'])->name('admin.question-options.store');
        Route::post('/admin/question-options/single', [QuestionOptionController::class, 'storeSingle'])->name('admin.question-options.single');
        Route::patch('/admin/question-options/{option}', [QuestionOptionController::class, 'update'])->whereNumber('option')->name('admin.question-options.update');
        Route::delete('/admin/question-options/{option}', [QuestionOptionController::class, 'destroy'])->whereNumber('option')->name('admin.question-options.destroy');
        Route::get('/admin/questions/data', [QuestionController::class, 'data'])->name('admin.questions.data');
        Route::get('/admin/questions/export-answered', [ImportController::class, 'exportAnswered'])->name('admin.questions.export-answered');
        Route::post('/admin/questions/bulk-answer', [QuestionController::class, 'bulkAnswer'])->name('admin.questions.bulk-answer');
        Route::post('/admin/questions/{entry}/account', [QuestionController::class, 'updateAccount'])->whereNumber('entry')->name('admin.questions.account');
        Route::post('/admin/questions/{entry}/paid-to-by', [QuestionController::class, 'updatePaidToBy'])->whereNumber('entry')->name('admin.questions.paid-to-by');
        Route::post('/admin/questions/{entry}/answer', [QuestionController::class, 'answer'])->whereNumber('entry')->name('admin.questions.answer');
        Route::post('/admin/questions/{entry}/detail', [QuestionController::class, 'uploadDetail'])->whereNumber('entry')->name('admin.questions.detail');
        Route::get('/admin/questions/{entry}/detail', [QuestionController::class, 'viewDetail'])->whereNumber('entry')->name('admin.questions.detail.view');
        Route::post('/admin/questions/bulk-archive', [QuestionController::class, 'bulkArchive'])->name('admin.questions.bulk-archive');
        Route::post('/admin/questions/{entry}/archive', [QuestionController::class, 'archive'])->whereNumber('entry')->name('admin.questions.archive');
        Route::post('/admin/questions/{entry}/status', [QuestionController::class, 'updateStatus'])->whereNumber('entry')->name('admin.questions.status');
    });

    Route::middleware('can:super-admin')->group(function (): void {
        Route::get('/admin/admins', [ManagerController::class, 'admins'])->name('admin.admins.index');
        Route::post('/admin/admins', [ManagerController::class, 'storeAdmin'])->name('admin.admins.store');
        Route::patch('/admin/admins/{admin}', [ManagerController::class, 'updateAdmin'])->whereNumber('admin')->name('admin.admins.update');
        Route::delete('/admin/admins/{admin}', [ManagerController::class, 'destroyAdmin'])->whereNumber('admin')->name('admin.admins.destroy');
        Route::get('/admin/developer-options', [DeveloperOptionController::class, 'index'])->name('admin.developer-options.index');
        Route::post('/admin/developer-options/run', [DeveloperOptionController::class, 'run'])->name('admin.developer-options.run');
        Route::put('/admin/developer-options/two-factor', [DeveloperOptionController::class, 'updateTwoFactor'])->name('admin.developer-options.two-factor.update');
    });

    Route::middleware('can:manager')->group(function (): void {
        Route::get('/manager', [ManagerController::class, 'dashboard'])->name('manager.dashboard');
        Route::get('/manager/dashboard/data', [ManagerController::class, 'dashboardData'])->name('manager.dashboard.data');
        Route::get('/manager/questions', [QuestionController::class, 'index'])->name('manager.questions.index');
        Route::get('/manager/questions/data', [QuestionController::class, 'data'])->name('manager.questions.data');
        Route::post('/manager/questions/bulk-answer', [QuestionController::class, 'bulkAnswer'])->name('manager.questions.bulk-answer');
        Route::post('/manager/questions/{entry}/account', [QuestionController::class, 'updateAccount'])->whereNumber('entry')->name('manager.questions.account');
        Route::post('/manager/questions/{entry}/paid-to-by', [QuestionController::class, 'updatePaidToBy'])->whereNumber('entry')->name('manager.questions.paid-to-by');
        Route::post('/manager/questions/{entry}/answer', [QuestionController::class, 'answer'])->whereNumber('entry')->name('manager.questions.answer');
        Route::get('/manager/questions/{entry}/detail', [QuestionController::class, 'viewDetail'])->whereNumber('entry')->name('manager.questions.detail.view');
    });
});
