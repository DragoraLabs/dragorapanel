<?php

namespace App\Http\Controllers;

use App\Models\Session;
use App\Models\Setting;
use App\Models\User;
use App\Services\ApiAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        return match ($request->query('action')) {
            'register' => $this->register($request),
            'login'    => $this->login($request),
            'logout'   => $this->logout($request),
            'me'       => $this->me($request),
            default    => response()->json(['success' => false, 'error' => 'Invalid action.'], 400),
        };
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
        ]);

        $user = User::create([
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
        ]);

        $session = $this->createSession($user->id);

        return response()->json([
            'success' => true,
            'token' => $session['token'],
            'session_key' => $session['session_key'],
            'user' => $this->formatUser($user),
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if ($this->blockedByVpnPolicy($request)) {
            return response()->json(['success' => false, 'error' => 'VPN / proxy access is disabled from this panel.'], 403);
        }

        $user = User::where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid email or password.',
            ], 401);
        }

        if ($user->isBanned()) {
            return response()->json([
                'success' => false,
                'error' => 'Your account has been banned. Contact support.',
            ], 403);
        }

        $session = $this->createSession($user->id);

        return response()->json([
            'success' => true,
            'token' => $session['token'],
            'session_key' => $session['session_key'],
            'user' => $this->formatUser($user),
        ]);
    }

    /**
     * When the "Block VP" admin toggle is on, reject sign-ins coming from
     * known VPN/proxy/datacenter IPs. Uses the free ip-api.com batch endpoint;
     * if the lookup fails we allow the request (fail-open) to avoid lockouts.
     */
    private function blockedByVpnPolicy(Request $request): bool
    {
        if ((string) Setting::get('security:vpn', '0') !== '1') {
            return false;
        }
        $ip = $request->ip();
        if (!$ip || $ip === '127.0.0.1' || $ip === '::1') {
            return false;
        }
        try {
            $r = \Illuminate\Support\Facades\Http::timeout(4)->get('http://ip-api.com/json/' . $ip, [
                'fields' => 'proxy,hosting',
            ]);
            if (!$r->ok()) return false;
            $d = $r->json();
            return !empty($d['proxy']) || !empty($d['hosting']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function me(Request $request): JsonResponse
    {
        $token = $this->getBearerToken($request);
        if (!$token) {
            return response()->json(['success' => false, 'error' => 'Not authenticated.'], 401);
        }

        // API key auth (dlpu_ / dpla_) — no session_key required.
        if (str_starts_with($token, 'dlpu_') || str_starts_with($token, 'dpla_')) {
            $user = ApiAuth::user($request);
            if (!$user) {
                return response()->json(['success' => false, 'error' => 'Invalid or expired token.'], 401);
            }
            return response()->json(['success' => true, 'user' => $this->formatUser($user)]);
        }

        $session = Session::where('token', $token)->valid()->with('user')->first();
        if (!$session) {
            return response()->json(['success' => false, 'error' => 'Invalid or expired token.'], 401);
        }

        // Validate session_key from header
        $headerKey = $request->header('X-Session-Key', '');
        if (!$headerKey || $headerKey !== $session->session_key) {
            return response()->json(['success' => false, 'error' => 'Session validation failed.'], 401);
        }

        return response()->json([
            'success' => true,
            'session_key' => $session->session_key,
            'user' => $this->formatUser($session->user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $this->getBearerToken($request);
        if ($token) {
            Session::where('token', $token)->delete();
        }

        return response()->json(['success' => true]);
    }

    private function createSession(int $userId): array
    {
        $token = bin2hex(random_bytes(32));
        $sessionKey = bin2hex(random_bytes(16));
        Session::create([
            'user_id' => $userId,
            'token' => $token,
            'session_key' => $sessionKey,
            'expires_at' => now()->addDays(30),
        ]);
        return ['token' => $token, 'session_key' => $sessionKey];
    }

    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'role' => $user->role,
        ];
    }

    private function getBearerToken(Request $request): ?string
    {
        $header = $request->header('Authorization', '');
        if (preg_match('/Bearer\s+(.+)$/i', $header, $m)) {
            return $m[1];
        }
        return null;
    }
}
