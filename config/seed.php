<?php

/*
| Accounts created by `php artisan db:seed`. Registration is closed, so
| these are the only way in. Set them in the server's .env, never the repo.
*/

return [
    'admin' => [
        'email' => env('SEED_ADMIN_EMAIL'),
        'password' => env('SEED_ADMIN_PASSWORD'),
    ],
    'demo' => [
        'email' => env('SEED_DEMO_EMAIL'),
        'password' => env('SEED_DEMO_PASSWORD'),
    ],
];
