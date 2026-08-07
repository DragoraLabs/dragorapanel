<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use App\Models\User;
use App\Services\ApiAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiKeyController extends Controller
{
    /** List API keys for the authenticated user. */
    public function index(Request $request): JsonResponse
    {
        $user = ApiAuth::user($request);
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'Not authenticated.'], 401);
        }
        $keys = ApiToken::where('user_id', $user->id)->orderByDesc('id')->get()->map(function (ApiToken $t) {
            return $this->format($t);
        });
        return response()->json(['success' => true, 'api_keys' => $keys]);
    }

    /** Create an API key for the authenticated user (or any user if admin targets one). */
    public function store(Request $request): JsonResponse
    {
        $user = ApiAuth::user($request);
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'Not authenticated.'], 401);
        }

        $isAdmin = $user->isAdmin();
        $targetUserId = (int) $request->input('user_id', $user->id);

        // Only admin may create keys for other users or grant admin scope.
        if ($targetUserId !== $user->id && !$isAdmin) {
            return response()->json(['success' => false, 'error' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'name' => 'required|string|max:64',
            'admin' => 'sometimes|boolean',
            'expires_days' => 'sometimes|integer|min:1|max:365',
        ]);

        $adminScope = !empty($data['admin']);
        if ($adminScope && !$isAdmin) {
            return response()->json(['success' => false, 'error' => 'Forbidden.'], 403);
        }

        $target = User::find($targetUserId);
        if (!$target) {
            return response()->json(['success' => false, 'error' => 'User not found.'], 422);
        }
        // Admin-scoped keys can only be issued to admin users.
        if ($adminScope && !$target->isAdmin()) {
            return response()->json(['success' => false, 'error' => 'Admin scope requires an admin user.'], 422);
        }

        $issued = ApiAuth::issue($target, $data['name'], $adminScope, $data['expires_days'] ?? null);

        return response()->json([
            'success' => true,
            'token' => $issued['token'],
            'api_key' => [
                'id' => $issued['id'],
                'name' => $issued['name'],
                'scope' => $adminScope ? 'admin' : 'user',
                'expires_at' => $issued['expires_at'],
            ],
            'note' => 'Store this token now; it will not be shown again.',
        ]);
    }

    /** Revoke an API key. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = ApiAuth::user($request);
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'Not authenticated.'], 401);
        }
        $key = ApiToken::where('user_id', $user->id)->find($id);
        if (!$key && $user->isAdmin() && ApiToken::find($id)) {
            $key = ApiToken::find($id); // admin may revoke any key
        }
        if (!$key) {
            return response()->json(['success' => false, 'error' => 'Not found.'], 404);
        }
        $key->delete();
        return response()->json(['success' => true]);
    }

    /** Admin: list all API keys across all users. */
    public function adminIndex(Request $request): JsonResponse
    {
        $user = ApiAuth::user($request);
        if (!$user || !$user->isAdmin()) {
            return response()->json(['success' => false, 'error' => 'Forbidden.'], 403);
        }
        $keys = ApiToken::with('user:id,email,first_name,last_name')->orderByDesc('id')->get()->map(function (ApiToken $t) {
            $d = $this->format($t);
            $d['user'] = $t->user ? [
                'id' => $t->user->id,
                'email' => $t->user->email,
                'name' => $t->user->first_name . ' ' . $t->user->last_name,
            ] : null;
            return $d;
        });
        return response()->json(['success' => true, 'api_keys' => $keys]);
    }

    private function format(ApiToken $t): array
    {
        $prefix = $t->scope === 'admin' ? ApiAuth::ADMIN_PREFIX : ApiAuth::USER_PREFIX;
        return [
            'id' => $t->id,
            'name' => $t->name,
            'scope' => $t->scope,
            'key' => $prefix . '••••••••••••••••••••••••',
            'expires_at' => $t->expires_at,
            'last_used_at' => $t->last_used_at,
            'created_at' => $t->created_at,
        ];
    }
}