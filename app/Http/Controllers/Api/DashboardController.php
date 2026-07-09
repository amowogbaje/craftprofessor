<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StoryImagePrompt;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DashboardController extends Controller
{
    /**
     * GET /api/dashboard/feed?type=all|image|video&status=all|draft|scheduled|published
     *                        &sort=newest|oldest|scheduled_at&search=&cursor=&per_page=24
     * Merges images and videos into one feed and cursor-paginates the merged,
     * sorted result (a DB-level cursor won't work across two tables sorted
     * together, so this uses an opaque offset-style cursor over the merged set).
     */
    public function feed(Request $request): JsonResponse
    {
        $user = $request->user();
        $type = $request->query('type', 'all');
        $status = $request->query('status', 'all');
        $sort = $request->query('sort', 'newest');
        $search = trim((string) $request->query('search', ''));
        $perPage = min((int) $request->query('per_page', 24), 60);
        $offset = (int) ($request->query('cursor') ?: 0);

        $items = collect();

        if ($type !== 'video') {
            $q = StoryImagePrompt::where('user_id', $user->id)->whereNotNull('image_generated_url');
            if ($status !== 'all') $q->where('status', $status);
            if ($search !== '') $q->where('prompt', 'like', "%{$search}%");
            $items = $items->merge($q->get()->map(fn ($p) => $this->presentImage($p)));
        }

        if ($type !== 'image') {
            $q = Video::where('user_id', $user->id)->whereNotNull('video_url');
            if ($status !== 'all') $q->where('status', $status);
            $items = $items->merge($q->get()->map(fn ($v) => $this->presentVideo($v)));
        }

        $items = match ($sort) {
            'oldest' => $items->sortBy('sort_at'),
            'scheduled_at' => $items->sortBy(fn ($i) => $i['scheduled_at'] ?? '9999'),
            default => $items->sortByDesc('sort_at'),
        }->values();

        $page = $items->slice($offset, $perPage)->values();
        $nextOffset = $offset + $perPage;
        $nextCursor = $nextOffset < $items->count() ? (string) $nextOffset : null;

        return response()->json(['data' => $page, 'next_cursor' => $nextCursor]);
    }

    public function updateImage(Request $request, StoryImagePrompt $imagePrompt): JsonResponse
    {
        $this->authorizeOwner($request, $imagePrompt->user_id);
        return response()->json($this->presentImage($this->applyStatus($request, $imagePrompt)));
    }

    public function updateVideo(Request $request, Video $video): JsonResponse
    {
        $this->authorizeOwner($request, $video->user_id);
        return response()->json($this->presentVideo($this->applyStatus($request, $video)));
    }

    protected function applyStatus(Request $request, $model)
    {
        Validator::make($request->all(), [
            'status' => ['required', 'in:draft,scheduled,published'],
            'scheduled_at' => ['required_if:status,scheduled', 'nullable', 'date', 'after:now'],
        ])->validate();

        $data = ['status' => $request->input('status')];
        if ($data['status'] === 'scheduled') {
            $data['scheduled_at'] = $request->input('scheduled_at');
            $data['published_at'] = null;
        } elseif ($data['status'] === 'published') {
            $data['published_at'] = now();
        } else {
            $data['scheduled_at'] = null;
            $data['published_at'] = null;
        }

        $model->update($data);
        return $model;
    }

    protected function authorizeOwner(Request $request, ?int $ownerId): void
    {
        abort_if($request->user()->id !== $ownerId, 403, 'Not your content.');
    }

    protected function presentImage(StoryImagePrompt $p): array
    {
        return [
            'type' => 'image', 'id' => $p->id, 'url' => $p->image_generated_url, 'prompt' => $p->prompt,
            'status' => $p->status, 'scheduled_at' => $p->scheduled_at, 'published_at' => $p->published_at,
            'story_id' => $p->story_id, 'has_video' => $p->videoPrompt()->exists(),
            'sort_at' => $p->generated_at ?? $p->created_at,
        ];
    }

    protected function presentVideo(Video $v): array
    {
        return [
            'type' => 'video', 'id' => $v->id, 'url' => $v->video_url,
            'source_image_prompt_id' => $v->story_image_prompt_id, 'status' => $v->status,
            'scheduled_at' => $v->scheduled_at, 'published_at' => $v->published_at,
            'sort_at' => $v->generated_at ?? $v->created_at,
        ];
    }
}
