<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoginActivity;
use App\Models\SsoSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    private const ALLOWED_ROLES = ['admin', 'super_user'];

    /**
     * Endpoint admin untuk melihat riwayat aktivitas login (maintenance).
     * Query param opsional: ?limit=50 & ?username=admin_simrs & ?status=success
     *
     * Wajib mengirim header Authorization: Bearer <token> milik user
     * dengan role admin/super_user - sebelumnya endpoint ini terbuka
     * tanpa autentikasi sama sekali.
     */
    public function loginActivities(Request $request)
    {
        $authError = $this->authorizeAdmin($request);
        if ($authError) {
            return $authError;
        }

        $limit = min((int) ($request->query('limit') ?: 50), 500);
        $username = $request->query('username');
        $status = $request->query('status');

        $query = LoginActivity::query()
            ->select(['id', 'user_id', 'username', 'status', 'reason', 'ip_address', 'user_agent', 'created_at']);

        if ($username) {
            $query->where('username', $username);
        }

        if ($status) {
            $query->where('status', $status);
        }

        $activities = $query->orderByDesc('created_at')->limit($limit)->get();
        $total = LoginActivity::count();

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $total,
                'limit' => $limit,
                'activities' => $activities,
            ],
        ]);
    }

    /**
     * Cek token Bearer valid & role user termasuk yang diizinkan.
     * Return null kalau lolos, atau JsonResponse error kalau ditolak.
     */
    private function authorizeAdmin(Request $request)
    {
        $authHeader = $request->header('Authorization', '');
        $token = Str::startsWith($authHeader, 'Bearer ')
            ? substr($authHeader, 7)
            : null;

        if (! $token) {
            return response()->json(['success' => false, 'message' => 'Token tidak ditemukan'], 401);
        }

        $session = SsoSession::where('refresh_token', $token)->where('status', 'active')->first();
        if (! $session || ($session->expires_at && now()->greaterThan($session->expires_at))) {
            return response()->json(['success' => false, 'message' => 'Token tidak valid atau session tidak aktif'], 401);
        }

        $user = User::find($session->user_id);
        if (! $user || ! in_array($user->role, self::ALLOWED_ROLES, true)) {
            return response()->json(['success' => false, 'message' => 'Akses ditolak untuk role ini'], 403);
        }

        return null;
    }
}
