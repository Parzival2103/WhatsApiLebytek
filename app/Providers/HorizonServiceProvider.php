<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        $mail = config('horizon.notifications.mail');

        if (is_string($mail) && $mail !== '') {
            Horizon::routeMailNotificationsTo($mail);
        }

        $slackUrl = config('horizon.notifications.slack.webhook');
        $slackChannel = config('horizon.notifications.slack.channel');

        if (is_string($slackUrl) && $slackUrl !== '' && is_string($slackChannel) && $slackChannel !== '') {
            Horizon::routeSlackNotificationsTo($slackUrl, $slackChannel);
        }
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            if ($user === null) {
                return false;
            }

            $allowed = config('nucleo.horizon_allowed_emails', []);

            if ($allowed === []) {
                return $user->isPlatformAdmin();
            }

            return in_array($user->email, $allowed, true);
        });
    }
}
