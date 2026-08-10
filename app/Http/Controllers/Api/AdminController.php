<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoginActivity;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    /**
     * Endpoint admin untuk melihat riwayat aktivitas login (maintenance).
     * Query param opsional: ?limit=50 & ?username=admin_simrs & ?status=success
     */
    public function loginActivities(Request $request)
    {
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
}
