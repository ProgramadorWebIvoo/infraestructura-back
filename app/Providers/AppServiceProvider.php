<?php

namespace App\Providers;

use App\Models\Project;
use App\Notifications\Channels\ExpoChannel;
use App\Observers\ProjectObserver;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot()
    {
        // Register Expo notification channel
        Notification::extend('expo', function ($app) {
            return $app->make(ExpoChannel::class);
        });

        // Observe project status changes for push notifications
        Project::observe(ProjectObserver::class);
    }
}
