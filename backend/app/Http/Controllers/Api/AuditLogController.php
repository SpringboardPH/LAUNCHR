<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\AuditLog;

class AuditLogController extends Controller
{
    /**
     * Display a listing of audit logs.
     */
    public function index(Request $request)
    {
        $query = AuditLog::with('user')->orderBy('created_at', 'desc');

        if ($event = $request->query('event')) {
            $query->where('event', $event);
        }

        if ($action = $request->query('action')) {
            $query->where('event', 'like', "{$action}_%");
        }

        if ($model = $request->query('model')) {
            $query->where('auditable_type', 'like', "%\\{$model}");
        }

        if ($userId = $request->query('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($actor = $request->query('actor')) {
            $query->whereHas('user', function ($q) use ($actor) {
                $q->where('name', 'like', "%{$actor}%");
            });
        }

        if ($dateFrom = $request->query('date_from')) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->query('date_to')) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $logs = $query->paginate(50);

        return response()->json([
            'success' => true,
            'data' => $logs->items(),
            'pagination' => [
                'total' => $logs->total(),
                'per_page' => $logs->perPage(),
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
            ]
        ]);
    }
}
