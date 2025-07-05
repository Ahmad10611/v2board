<?php

use App\Services\ThemeService;
use Illuminate\Http\Request;
use App\Http\Controllers\V1\Guest\PaymentController;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function (Request $request) {
    if (config('v2board.app_url') && config('v2board.safe_mode_enable', 0)) {
        if ($request->server('HTTP_HOST') !== parse_url(config('v2board.app_url'))['host']) {
            abort(403);
        }
    }
    
    $renderParams = Cache::remember('v2board_render_params', 3600, function() {
        return [
            'title' => config('v2board.app_name', 'V2Board'),
            'theme' => config('v2board.frontend_theme', 'default'),
            'version' => config('app.version'),
            'description' => config('v2board.app_description', 'V2Board is best'),
            'logo' => config('v2board.logo')
        ];
    });

    if (!config("theme.{$renderParams['theme']}")) {
        $themeService = new ThemeService($renderParams['theme']);
        $themeService->init();
    }

    $renderParams['theme_config'] = config('theme.' . config('v2board.frontend_theme', 'default'));
    return view('theme::' . config('v2board.frontend_theme', 'default') . '.dashboard', $renderParams);
});

Route::get('/' . config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))), function () {
    return view('admin', [
        'title' => config('v2board.app_name', 'V2Board'),
        'theme_sidebar' => config('v2board.frontend_theme_sidebar', 'light'),
        'theme_header' => config('v2board.frontend_theme_header', 'dark'),
        'theme_color' => config('v2board.frontend_theme_color', 'default'),
        'background_url' => config('v2board.frontend_background_url'),
        'version' => config('app.version'),
        'logo' => config('v2board.logo'),
        'secure_path' => config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key'))))
    ]);
});

if (!empty(config('v2board.subscribe_path'))) {
    Route::get(config('v2board.subscribe_path'), 'V1\\Client\\ClientController@subscribe')->middleware('client');
}

// مسیرهای پرداخت
Route::post('payment/notify/{method}/{uuid}', [PaymentController::class, 'notify'])
    ->name('payment.notify')
    ->middleware('throttle:60,1');

// مسیرهای legacy
Route::post('/api/v1/guest/payment/callback/aghayehpardakht', [PaymentController::class, 'aghayehpardakhtCallback']);
Route::post('/api/v1/guest/payment/callback/zibal', [PaymentController::class, 'zibalCallback']);
Route::post('payment/notify/zibal/{uuid}', [PaymentController::class, 'notify'])->name('payment.notify.zibal');
