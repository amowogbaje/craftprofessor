<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /** GET /api/notifications?unread=1 */
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->notifications()->latest();

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        return response()->json([
            'data' => $query->limit(50)->get(),
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /** POST /api/notifications/{id}/read */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return response()->json(['message' => 'Marked as read.']);
    }

    /** POST /api/notifications/read-all */
    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['message' => 'All notifications marked as read.']);
    }
}
