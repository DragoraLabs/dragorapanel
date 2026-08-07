<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ApiToken;
use App\Models\Notification;
use App\Models\Session;
use App\Models\User;
use App\Services\ApiAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserController extends Controller
{
    private function getUser(Request $request): ?User
    {
        return ApiAuth::user($request);
    }

    // ── Profile ──

    public function profile(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['success' => false, 'error' => 'Not authenticated.'], 401);
        return response()->json(['success' => true, 'user' => $user]);
    }

    public function profileUpdate(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['success' => false, 'error' => 'Not authenticated.'], 401);

        $data = $request->validate([
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'language' => 'nullable|string|max:10',
            'timezone' => 'nullable|string|max:50',
        ]);
        $user->update(array_filter($data));
        return response()->json(['success' => true, 'user' => $user]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['success' => false, 'error' => 'Not authenticated.'], 401);

        $data = $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:6',
        ]);
        if (!Hash::check($data['current_password'], $user->password)) {
            return response()->json(['success' => false, 'error' => 'Current password is incorrect.'], 400);
        }
        $user->update(['password' => Hash::make($data['new_password'])]);
        return response()->json(['success' => true]);
    }

    // ── API Tokens ──

    public function tokensIndex(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['success' => false, 'error' => 'Not authenticated.'], 401);
        return response()->json(['success' => true, 'tokens' => $user->apiTokens()->orderBy('id', 'desc')->get()]);
    }

    public function tokensStore(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['success' => false, 'error' => 'Not authenticated.'], 401);

        $data = $request->validate(['name' => 'required|string', 'expires_at' => 'nullable|date']);
        $raw = Str::random(48);
        $token = ApiToken::create([
            'user_id' => $user->id,
            'name' => $data['name'],
            'token_hash' => Hash::make($raw),
            'expires_at' => $data['expires_at'] ?? null,
        ]);
        return response()->json(['success' => true, 'token' => $token, 'raw_token' => $raw]);
    }

    public function tokensDestroy(Request $request, ApiToken $apiToken): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['success' => false, 'error' => 'Not authenticated.'], 401);
        if ($apiToken->user_id !== $user->id && !$user->isAdmin()) return response()->json(['success' => false, 'error' => 'Forbidden.'], 403);
        $apiToken->delete();
        return response()->json(['success' => true]);
    }

    // ── Notifications ──

    public function notificationsIndex(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['success' => false, 'error' => 'Not authenticated.'], 401);
        return response()->json(['success' => true, 'notifications' => $user->notifications()->orderBy('id', 'desc')->limit(50)->get()]);
    }

    public function notificationsRead(Request $request, Notification $notification): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user || $notification->user_id !== $user->id) return response()->json(['success' => false, 'error' => 'Not found.'], 404);
        $notification->update(['read_at' => now()]);
        return response()->json(['success' => true]);
    }

    public function notificationsReadAll(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['success' => false, 'error' => 'Not authenticated.'], 401);
        $user->notifications()->whereNull('read_at')->update(['read_at' => now()]);
        return response()->json(['success' => true]);
    }

    // ── Activity ──

    public function myActivity(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['success' => false, 'error' => 'Not authenticated.'], 401);
        return response()->json(['success' => true, 'logs' => ActivityLog::where('user_id', $user->id)->with('server:id,name')->latest()->limit(50)->get()]);
    }
}
