<?php

namespace VeljkoRailsware\LaravelMailboxMailtrap;

use BeyondCode\Mailbox\Drivers\DriverInterface;
use Illuminate\Support\Facades\Route;
use VeljkoRailsware\LaravelMailboxMailtrap\Http\Controllers\MailtrapController;

class MailtrapDriver implements DriverInterface
{
    public function register()
    {
        Route::prefix(config('mailbox.path'))->group(function () {
            Route::post('/'.ltrim((string) config('mailbox-mailtrap.route', 'mailtrap'), '/'), MailtrapController::class)
                ->name('mailbox.mailtrap');
        });
    }
}
