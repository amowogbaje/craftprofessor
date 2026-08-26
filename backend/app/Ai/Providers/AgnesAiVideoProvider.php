<?php

namespace App\Ai\Providers;

use App\Ai\Contracts\VideoProviderContract;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Agnes AI video provider — image-to-video via Agnes Video V2.0, an
 * asynchronous task API: POST to create a task, then GET to poll it until
 * a status of "completed" or "failed" comes back.
 *
 * VERIFIED against Agnes' own request/response examples (mirrored in
 * https://github.com/kksjend1/Agnes-skill/blob/main/agnes-ai-api-documentation.md,
 * itself sourced from https://agnes-ai.com/doc/agnes-video-v2.0). Two
 * things worth flagging explicitly, since they're easy to get wrong and
 * caused real failures before this was corrected:
 *
 *  1. `mode` and `image` (for a single-image animate) are TOP-LEVEL
 *     request fields. `extra_body.image`/`extra_body.mode` are a
 *     *different* thing — only for the multi-image/keyframes workflow.
 *     Nesting a single-image request under extra_body doesn't get
 *     rejected outright (Agnes' top-level `mode` validation never sees
 *     it), but the job dies downstream and leaves a dead task id, which
 *     then 404s ("task_not_exist") on poll — a confusing failure mode
 *     once removed from the actual cause.
 *  2. The completed video's URL comes back in a field literally named
 *     `remixed_from_video_id` — Agnes' own docs note this is a naming
 *     quirk, not a copy-paste error on our end. Checked first below, with
 *     `video_url`/`url` as fallbacks in case that ever gets corrected
 *     server-side.
 *
 * Still worth confirming against your live dashboard docs if anything
 * about Agnes' API changes — this is a third-party mirror, not the
 * primary source, even though it lines up with the real errors we saw.
 */
class AgnesAiVideoProvider implements VideoProviderContract
{
    protected const POLL_INTERVAL_SECONDS = 8;
    protected const MAX_POLL_ATTEMPTS = 45; // ~6 minutes, matches VeoVideoProvider

    public function __construct(
        protected ?string $apiKey,
        protected string $baseUrl = 'https://apihub.agnes-ai.com/v1',
        protected string $model = 'agnes-video-v2.0',
        // Portrait by default (this app's StoryVideoAssemblyService targets
        // 1080x1920 short-form video) — Agnes' own default is landscape
        // (768x1152), so this deliberately overrides that.
        protected int $width = 768,
        protected int $height = 1152,
        // num_frames must be <= 441 and satisfy 8n+1 (81/121/161/241/441).
        // 121 @ 24fps = ~5s per scene, close to StoryVideoAssemblyService's
        // own DEFAULT_SCENE_SECONDS fallback.
        protected int $numFrames = 121,
        protected int $frameRate = 24,
    ) {}

    public function name(): string
    {
        return 'agnes';
    }

    public function generate(string $motionPrompt, string $sourceImageUrl): string
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('AGNES_API_KEY is not configured.');
        }

        $taskId = $this->submit($motionPrompt, $sourceImageUrl);
        $videoUrl = $this->poll($taskId);

        return Http::timeout(60)->get($videoUrl)->throw()->body();
    }

    protected function submit(string $motionPrompt, string $sourceImageUrl): string
    {
        $response = Http::withToken($this->apiKey)
            ->timeout(60)
            ->post("{$this->baseUrl}/videos", [
                'model' => $this->model,
                'prompt' => $motionPrompt,
                // Top-level, per Agnes' image-to-video example — NOT
                // extra_body. See class doc-comment.
                'image' => $sourceImageUrl,
                'mode' => 'ti2vid',
                'width' => $this->width,
                'height' => $this->height,
                'num_frames' => $this->numFrames,
                'frame_rate' => $this->frameRate,
            ]);

        if ($response->failed()) {
            Log::error('AgnesAiVideoProvider: submit failed', ['body' => $response->body()]);
            throw new RuntimeException('Agnes AI video submit failed: ' . $response->body());
        }

        // Agnes' create-task response includes id, task_id, and video_id
        // (all equivalent) — video_id is what the recommended poll
        // endpoint expects.
        $id = $response->json('video_id') ?? $response->json('id') ?? $response->json('task_id');

        if (!$id) {
            throw new RuntimeException('Agnes AI did not return a video/task id: ' . $response->body());
        }

        return $id;
    }

    protected function poll(string $id): string
    {
        // Recommended endpoint per Agnes' docs — note this lives at the
        // API root, not under /v1 like every other Agnes endpoint. The
        // older /v1/videos/{task_id} form is still supported but Agnes'
        // own docs steer new integrations toward this one.
        $root = preg_replace('#/v1/?$#', '', $this->baseUrl);
        $pollUrl = "{$root}/agnesapi?video_id={$id}";

        for ($i = 0; $i < self::MAX_POLL_ATTEMPTS; $i++) {
            sleep(self::POLL_INTERVAL_SECONDS);

            $response = Http::withToken($this->apiKey)
                ->timeout(30)
                ->get($pollUrl);

            if ($response->failed()) {
                throw new RuntimeException('Agnes AI video poll failed: ' . $response->body());
            }

            $status = $response->json('status');

            if ($status === 'failed' || $status === 'error') {
                throw new RuntimeException('Agnes AI video task failed: ' . $response->body());
            }

            if ($status === 'completed' || $status === 'succeeded') {
                // remixed_from_video_id is genuinely where the finished
                // video URL lives (confirmed in Agnes' own docs, despite
                // the name) — the other two are defensive fallbacks only.
                $url = $response->json('remixed_from_video_id')
                    ?? $response->json('video_url')
                    ?? $response->json('url');

                if (!$url) {
                    throw new RuntimeException('Agnes AI task completed with no video URL: ' . $response->body());
                }

                return $url;
            }

            // Anything else (queued, in_progress, ...) — keep polling.
        }

        throw new RuntimeException('Agnes AI video task timed out waiting for completion.');
    }
}
