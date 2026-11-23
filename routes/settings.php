<?php

use Illuminate\Support\Facades\Route;

// Appearance only. Reviewers share the seeded logins, so nothing here may
// change an account: no email or password change, no account deletion.
// One reviewer could otherwise lock the others out.
Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/appearance');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
});
