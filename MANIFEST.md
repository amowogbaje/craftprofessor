# CraftProfessor — full session update manifest

Everything below is every file touched across our whole conversation,
packaged as one zip (`craftprofessor-full-update.zip`) with the same
folder structure as your project (`backend/...`, `frontend/...`) so you
can extract it straight over your existing project root.

## ⚠️ Migrations — the actual fix for your error (5 files)

These are the ones missing from your DB. Copy them into
`backend/database/migrations/`, then run `php artisan migrate`:

- `backend/database/migrations/2026_09_05_000001_add_youtube_tracking_to_story_videos.php`
- `backend/database/migrations/2026_09_06_000001_add_voice_to_characters.php` ← this one is what's causing the error in your log
- `backend/database/migrations/2026_09_06_000002_add_dialogue_lines_to_story_image_prompts.php`
- `backend/database/migrations/2026_09_06_000003_add_auto_generate_scene_videos_to_publish_settings.php`
- `backend/database/migrations/2026_09_08_000001_add_pinterest_overrides_to_stories.php`

After copying them in, confirm they're seen before running anything on
production:

```bash
php artisan migrate:status   # check what's Pending
php artisan migrate          # apply them
```

## Backend — everything else (32 files)

**Config**
- `backend/.env.example`
- `backend/config/ai.php`
- `backend/config/services.php`

**Docs**
- `backend/YouTube.md`

**Routes**
- `backend/routes/api.php`
- `backend/routes/console.php`

**Models**
- `backend/app/Models/Character.php`
- `backend/app/Models/PublishSetting.php`
- `backend/app/Models/Story.php`
- `backend/app/Models/StoryImagePrompt.php`
- `backend/app/Models/StoryVideo.php`

**Controllers**
- `backend/app/Http/Controllers/Api/DashboardController.php`
- `backend/app/Http/Controllers/Api/PublishSettingController.php`
- `backend/app/Http/Controllers/Api/StoryController.php`
- `backend/app/Http/Controllers/Api/VideoController.php`

**Services**
- `backend/app/Services/ImageGeneratorService.php`
- `backend/app/Services/NarrationAudioService.php`
- `backend/app/Services/SceneVideoGenerationService.php`
- `backend/app/Services/StoryVideoAssemblyService.php`
- `backend/app/Services/YouTubeShortsExportService.php`
- `backend/app/Services/SocialPlatforms/PinterestBoardSelectionService.php`
- `backend/app/Services/SocialPlatforms/PinterestPlatform.php`
- `backend/app/Services/SocialPlatforms/YouTubePlatform.php`

**AI (dialogue/voice + scene generation)**
- `backend/app/Ai/Agents/ImagePromptAgent.php`
- `backend/app/Ai/Contracts/TtsProviderContract.php`
- `backend/app/Ai/Providers/ElevenLabsTtsProvider.php`
- `backend/app/Ai/Providers/GeminiTtsProvider.php`
- `backend/app/Ai/Providers/OpenAiTtsProvider.php`

**Console commands**
- `backend/app/Console/Commands/AutoGenerateSceneVideos.php`
- `backend/app/Console/Commands/GenerateStoryVideos.php`
- `backend/app/Console/Commands/PostPinterestPins.php`
- `backend/app/Console/Commands/PostYouTubeShorts.php`

## Frontend (5 files)

- `frontend/src/lib/api-content.ts`
- `frontend/src/lib/types.ts`
- `frontend/src/pages/CharactersPage.tsx`
- `frontend/src/pages/SettingsPage.tsx`
- `frontend/src/pages/StoryDetailPage.tsx`

## Deploy order

1. Extract this zip over your project root (or copy files in individually —
   every path above is relative to your project root, matching your
   existing folder layout).
2. Backend: `composer install` (only if any composer deps changed — none
   did this session, so this is likely a no-op), then `php artisan migrate`.
3. Clear caches if you use them in production: `php artisan config:clear`,
   `php artisan route:clear`.
4. Frontend: `npm run build` (or your usual build/deploy step) — none of
   this works from source files alone if you're serving a compiled build.
5. Confirm your cron still calls `php artisan schedule:run` every minute —
   several of these files added new scheduled commands
   (`youtube:post-daily-short`, `story:auto-generate-videos`) that depend
   on that.
