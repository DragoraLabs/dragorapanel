<?php

namespace App\Services;

use App\Models\ApiToken;
use App\Models\Session;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ApiAuth
{
    public const USER_PREFIX = 'dlpu_';
    public const ADMIN_PREFIX = 'dpla_';

    /**
     * Resolve the authenticated user from a session (Bearer + X-Session-Key)
     * OR from a Dragora API key (Authorization: Bearer dlpu_... / dpla_...).
     */
    public static function user(Request $request): ?User
    {
        $token = trim($request->bearerToken() ?? (string) $request->query('token', ''));

        if ($token === '') {
            return null;
        }

        // API key auth
        if (str_starts_with($token, self::USER_PREFIX) || str_starts_with($token, self::ADMIN_PREFIX)) {
            return self::userFromApiToken($token);
        }

        // Session auth
        $session = Session::where('token', $token)->valid()->first();
        if (!$session) {
            return null;
        }
        $sessionKey = $request->header('X-Session-Key', '');
        if (!$sessionKey) {
            $sessionKey = (string) $request->query('session_key', '');
        }
        if ($sessionKey === '' || !hash_equals((string) $session->session_key, $sessionKey)) {
            return null;
        }
        return $session->user;
    }

    private static function userFromApiToken(string $token): ?User
    {
        $hash = hash('sha256', $token);
        $apiToken = ApiToken::where('token_hash', $hash)->with('user')->first();
        if (!$apiToken || $apiToken->isExpired()) {
            return null;
        }
        $user = $apiToken->user;
        if (!$user) {
            return null;
        }
        // Enforce admin scope: a dpla_ key is only valid for an admin user.
        if (str_starts_with($token, self::ADMIN_PREFIX) && !$user->isAdmin()) {
            return null;
        }
        // Refresh last-used (best effort, throttled to avoid writing on every hit).
        if (!$apiToken->last_used_at || $apiToken->last_used_at->diffInSeconds(now()) > 60) {
            $apiToken->forceFill(['last_used_at' => now()])->save();
        }
        return $user;
    }

    /** Issue a new API key. Returns the plaintext once; only the hash is stored. */
    public static function issue(User $user, string $name, bool $adminScope, ?int $expiresDays = null): array
    {
        $prefix = $adminScope ? self::ADMIN_PREFIX : self::USER_PREFIX;
        $secret = Str::random(40);
        $plain = $prefix . $secret;

        $expiresAt = $expiresDays ? now()->addDays($expiresDays) : null;

        $model = ApiToken::create([
            'user_id' => $user->id,
            'name' => $name,
            'scope' => $adminScope ? 'admin' : 'user',
            'token_hash' => hash('sha256', $plain),
            'last_used_at' => null,
            'expires_at' => $expiresAt,
        ]);

        return ['id' => $model->id, 'token' => $plain, 'name' => $name, 'expires_at' => $expiresAt];
    }

    public static function adminCheck(Request $request): ?array
    {
        $user = self::user($request);
        if (!$user) {
            return ['success' => false, 'error' => 'Not authenticated.'];
        }
        if (!$user->isAdmin()) {
            return ['success' => false, 'error' => 'Forbidden.'];
        }
        return null;
    }

    public static function mask(string $token): string
    {
        if (strlen($token) <= 8) {
            return $token;
        }
        $prefix = str_starts_with($token, self::ADMIN_PREFIX) ? self::ADMIN_PREFIX : self::USER_PREFIX;
        $tail = substr($token, strlen($prefix), 4);
        return $prefix . $tail . '…' . substr($token, -3);
    }
}