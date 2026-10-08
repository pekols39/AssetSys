<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Session;
use App\Models\Setting;

class SessionTimeoutMiddleware
{
    public static function handle(): void
    {
        if (!Auth::check()) {
            return;
        }

        $settingModel = new Setting();

        $timeoutMinutes = $settingModel->getInt(
            'session_timeout',
            30
        );

        $timeoutMinutes = max(1, $timeoutMinutes);

        $timeoutSeconds = $timeoutMinutes * 60;

        $lastActivity = Session::get(
            'LAST_ACTIVITY'
        );

        if (
            !is_int($lastActivity)
            && !is_numeric($lastActivity)
        ) {
            Session::set(
                'LAST_ACTIVITY',
                time()
            );

            return;
        }

        $lastActivity = (int) $lastActivity;

        if (
            $lastActivity <= 0
            || (time() - $lastActivity) >= $timeoutSeconds
        ) {
            Auth::logout();

            Session::start();

            Session::set(
                'session_expired',
                true
            );

            return;
        }

        Session::set(
            'LAST_ACTIVITY',
            time()
        );
    }
}