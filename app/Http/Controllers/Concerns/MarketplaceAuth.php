<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Session;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Cookie-session auth for the standalone marketplace website (store mode).
 * Shared by MarketplaceController + MarketplaceSettingsController.
 */
trait MarketplaceAuth
{
    private function currentUser(Request $request): ?User
    {
        $token = $request->cookie('mp_token') ?: $request->query('t', '');
        $key = $request->cookie('mp_key') ?: $request->query('k', '');
        if (!$token || !$key) return null;
        $session = Session::where('token', $token)->valid()->first();
        if (!$session || !hash_equals((string) $session->session_key, (string) $key)) return null;
        return $session->user;
    }
}