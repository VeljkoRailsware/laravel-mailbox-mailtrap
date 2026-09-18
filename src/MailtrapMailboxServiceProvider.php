<?php

namespace VeljkoRailsware\LaravelMailboxMailtrap;

use BeyondCode\Mailbox\MailboxManager;
use Illuminate\Support\ServiceProvider;

class MailtrapMailboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mailbox-mailtrap.php', 'mailbox-mailtrap');

        // laravel-mailbox resolves its driver in boot() via Mailbox::mailbox()->register().
        // Registering the creator when the manager is resolved guarantees we are in place
        // before that call, whatever the provider boot order.
        $this->app->resolving(MailboxManager::class, function (MailboxManager $manager) {
            $manager->extend('mailtrap', fn () => new MailtrapDriver());
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/mailbox-mailtrap.php' => config_path('mailbox-mailtrap.php'),
        ], 'config');
    }
}
