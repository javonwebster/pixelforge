<?php

use App\Http\Controllers\PixelforgeWebhookController;
use App\Http\Middleware\VerifyPixelforgeSignature;
use Illuminate\Support\Facades\Route;

/*
| Machine-to-machine routes. Loaded outside the `web` group, so there's no
| session, cookie or CSRF middleware: Pixelforge can't send a CSRF token,
| and the signature check is the authentication.
*/

Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)
    ->middleware(VerifyPixelforgeSignature::class)
    ->name('webhooks.pixelforge');
