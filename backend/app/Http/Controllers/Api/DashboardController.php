<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StoryImagePrompt;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
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
     *
     * Deliberately scoped to `story_image_prompts` (scenes) + `videos` only.
     * Character/environment/prop reference portraits live on their own
     * tables (Character.img_url etc.) and must never be unioned in here —
     * they're reference material for scene generation, not feed content.
     * See CharacterController for where those actually belong (per-story
     * or per-series character views).
     */
    public function feed(Request $request): JsonResponse
    {
        $user = $request->user();

        $type = $request->query('type', 'all');
        $status = $request->query('status', 'all');
        $sort = $request->query('sort', 'newest');
        $search = trim($request->query('search', ''));

        $perPage = min((int) $request->query('per_page', 24), 60);
        $offset = (int) $request->query('cursor', 0);

        /*
        |--------------------------------------------------------------------------
        | Images Query
        |--------------------------------------------------------------------------
        */

        $images = DB::table('story_image_prompts')
            ->selectRaw("
                id,
                'image' as type,
                image_generated_url as url,
                prompt,
                narration,
                scene_number,
                pinterest_pin_id,
                status,
                scheduled_at,
                published_at,
                story_id,
                NULL as source_image_prompt_id,
                (
                    EXISTS(
                        SELECT 1
                        FROM videos
                        WHERE videos.story_image_prompt_id = story_image_prompts.id
                    )
                ) as has_video,
                COALESCE(generated_at, created_at) as sort_at
            ")
            ->where('user_id', $user->id)
            ->whereNotNull('image_generated_url');

        if ($status !== 'all') {
            $images->where('status', $status);
        }

        if ($search !== '') {
            $images->where('prompt', 'like', "%{$search}%");
        }

        /*
        |--------------------------------------------------------------------------
        | Videos Query
        |--------------------------------------------------------------------------
        */

        $videos = DB::table('videos')
            ->selectRaw("
                videos.id,
                'video' as type,
                video_url as url,
                NULL as prompt,
                sip.narration as narration,
                sip.scene_number as scene_number,
                NULL as pinterest_pin_id,
                videos.status,
                videos.scheduled_at,
                videos.published_at,
                NULL as story_id,
                videos.story_image_prompt_id as source_image_prompt_id,
                NULL as has_video,
                COALESCE(videos.generated_at, videos.created_at) as sort_at
            ")
            ->leftJoin('story_image_prompts as sip', 'sip.id', '=', 'videos.story_image_prompt_id')
            ->where('videos.user_id', $user->id)
            ->whereNotNull('videos.video_url');

        if ($status !== 'all') {
            $videos->where('videos.status', $status);
        }

        /*
        |--------------------------------------------------------------------------
        | Merge
        |--------------------------------------------------------------------------
        */

        if ($type === 'image') {
            $query = $images;
        } elseif ($type === 'video') {
            $query = $videos;
        } else {
            $query = $images->unionAll($videos);
        }

        /*
        |--------------------------------------------------------------------------
        | Sorting
        |--------------------------------------------------------------------------
        */

        switch ($sort) {
            case 'oldest':
                $query = DB::query()
                    ->fromSub($query, 'feed')
                    ->orderBy('sort_at');
                break;

            case 'scheduled_at':
                $query = DB::query()
                    ->fromSub($query, 'feed')
                    ->orderByRaw('scheduled_at IS NULL')
                    ->orderBy('scheduled_at');
                break;

            default:
                $query = DB::query()
                    ->fromSub($query, 'feed')
                    ->orderByDesc('sort_at');
                break;
        }

        $rows = $query
            ->offset($offset)
            ->limit($perPage + 1)
            ->get();

        $nextCursor = null;

        if ($rows->count() > $perPage) {
            $rows = $rows->take($perPage);
            $nextCursor = (string) ($offset + $perPage);
        }

        // Raw selectRaw()/UNION rows come back typed however the DB driver
        // feels like (MySQL's EXISTS(...) often arrives as "1"/"0" strings
        // via PDO, not a real JSON boolean) — cast explicitly rather than
        // relying on the frontend's truthy checks to paper over it forever.
        $rows = $rows->values()->map(function ($row) {
            $row->has_video = is_null($row->has_video) ? null : (bool) $row->has_video;
            $row->scene_number = is_null($row->scene_number) ? null : (int) $row->scene_number;
            $row->id = (int) $row->id;
            $row->story_id = is_null($row->story_id) ? null : (int) $row->story_id;
            $row->source_image_prompt_id = is_null($row->source_image_prompt_id) ? null : (int) $row->source_image_prompt_id;

            return $row;
        });

        return response()->json([
            'data' => $rows,
            'next_cursor' => $nextCursor,
        ]);
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
            'narration' => $p->narration, 'scene_number' => $p->scene_number,
            'pinterest_pin_id' => $p->pinterest_pin_id,
            'status' => $p->status, 'scheduled_at' => $p->scheduled_at, 'published_at' => $p->published_at,
            'story_id' => $p->story_id, 'source_image_prompt_id' => null,
            'has_video' => $p->hasCompletedVideo(),
            'sort_at' => $p->generated_at ?? $p->created_at,
        ];
    }

    protected function presentVideo(Video $v): array
    {
        return [
            'type' => 'video', 'id' => $v->id, 'url' => $v->video_url, 'prompt' => null,
            'narration' => $v->imagePrompt?->narration, 'scene_number' => $v->imagePrompt?->scene_number,
            'pinterest_pin_id' => null,
            'status' => $v->status, 'scheduled_at' => $v->scheduled_at, 'published_at' => $v->published_at,
            'story_id' => null, 'source_image_prompt_id' => $v->story_image_prompt_id,
            'has_video' => null,
            'sort_at' => $v->generated_at ?? $v->created_at,
        ];
    }

    public function deleteMedia(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type'        => ['required', 'string', 'in:image,video'],
            'resource_id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $type = $request->input('type');
        $resourceId = $request->input('resource_id');
        $userId = $request->user()->id;

        if ($type === 'image') {
            $record = StoryImagePrompt::where('id', $resourceId)
                ->where('user_id', $userId)
                ->first();

            if (! $record) {
                return response()->json(['message' => 'Image prompt not found.'], 404);
            }

            $record->delete();
        } else {
            $record = Video::where('id', $resourceId)
                ->where('user_id', $userId)
                ->first();

            if (! $record) {
                return response()->json(['message' => 'Video not found.'], 404);
            }

            $record->delete();
        }

        return response()->json([
            'message' => ucfirst($type) . ' deleted successfully.',
        ], 200);
    }
}
