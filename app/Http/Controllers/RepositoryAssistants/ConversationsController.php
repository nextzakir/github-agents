<?php

namespace App\Http\Controllers\RepositoryAssistants;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConversationsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = User::firstOrCreate(
            ['email' => 'api@system.local'],
            ['name' => 'API Client', 'password' => bcrypt(str()->random(32))]
        );

        $perPage = min(max((int) ($request->query('per_page', 20)), 1), 100);
        $page = max((int) ($request->query('page', 1)), 1);

        $query = DB::table('agent_conversations')
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at');

        $total = $query->count();
        $lastPage = max((int) ceil($total / $perPage), 1);

        $conversations = $query
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get(['id', 'title', 'updated_at'])
            ->map(fn ($row) => [
                'id' => $row->id,
                'title' => $row->title,
                'updated_at' => $row->updated_at,
            ]);

        return response()->json([
            'data' => $conversations,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }
}
