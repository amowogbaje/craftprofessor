# CraftProfessor

Monorepo combining what used to be two separate repos:

- `frontend/` — formerly `craftprofessorui` (React + Vite + TypeScript)
- `backend/` — formerly `social-media-asst` (Laravel)

Both deploy from this one repo via `.github/workflows/deploy.yml`, which
runs two independent jobs — `deploy-frontend` and `deploy-backend` — each
only triggered when its own folder changed (via `dorny/paths-filter`), so a
backend-only commit doesn't rebuild/redeploy the frontend and vice versa.
You can also force either or both from the Actions tab via
"Run workflow" (`workflow_dispatch`).

## Merging your existing two repos into this one, with history intact

Since each side already has its own git history you care about, don't just
copy files over — use `git subtree` (or `git filter-repo`) from a fresh
repo so both histories are preserved under their new subfolders:

```bash
mkdir craftprofessor && cd craftprofessor
git init

git remote add frontend-origin <url-to-craftprofessorui-repo>
git fetch frontend-origin
git merge --allow-unrelated-histories -m "Merge craftprofessorui as frontend/" frontend-origin/main
mkdir frontend
git mv $(git ls-tree --name-only frontend-origin/main) frontend/
# (repeat mv for any files git couldn't auto-detect, then commit)

git remote add backend-origin <url-to-social-media-asst-repo>
git fetch backend-origin
git merge --allow-unrelated-histories -m "Merge social-media-asst as backend/" backend-origin/main
mkdir backend
git mv $(git ls-tree --name-only backend-origin/main) backend/
git commit -m "Move social-media-asst into backend/"

# Drop in the merged .github/workflows/deploy.yml from this folder,
# remove the old frontend/.github and backend/.github workflow files
# (already done in this delivered copy), and push.
git remote add origin <url-to-new-monorepo>
git push -u origin main
```

If you'd rather not fuss with history, the simplest path is: pick one of
the two existing repos as the new home, `git mv` its own contents into
`frontend/` or `backend/` as appropriate, then copy the other project's
files in under the other folder as a fresh, un-historied add.

## Required GitHub repo configuration

Both jobs currently reuse the **same** `HOST` / `USERNAME` / `PORT`
repo variables and the same `SSH_PRIVATE_KEY` secret that the two original
workflows used — this assumes both `craftprofessor.amowogbaje.com` and
`craftprofessorui.amowogbaje.com` live under the same Namecheap/cPanel
account. If they don't, split these into e.g. `FRONTEND_HOST`/`BACKEND_HOST`
in the workflow and add the corresponding repo variables.

You'll also still need `VITE_API_URL` (repo variable) for the frontend
build step, exactly as before.

## New in this pass

- `backend`: StoryVerse series import (`POST /api/story-series/import-storyverse`,
  `php artisan story:import-storyverse`) — see `docs/storyverse-import-contract.md`.
- `backend`: tracked outbound links + click stats (`GET /r`, `GET /api/link-stats`).
- `frontend`: `/series` (StoryVerse import UI) and `/stats` (click analytics) pages,
  both re-enabled/added in the sidebar nav.

## Story → scenes → video pipeline

Stories no longer generate a fixed batch of 10 image prompts. `ImagePromptAgent`
now decides a variable scene count (4–20) per story based on its actual beats,
and every scene carries a `narration` line (the voiceover script for that
scene) alongside its image prompt.

**New pieces, in pipeline order:**

1. `story:generate-image-prompts` (unchanged trigger) — now produces N scenes
   + narration lines instead of a fixed 10.
2. `story:generate-images` (unchanged) — generates each scene's still image.
3. `story:generate-narration-audio` (new, every 15 min) — turns each scene's
   `narration` into an mp3 via `NarrationAudioService` + `TtsProviderContract`
   (OpenAI by default, ElevenLabs as an alternative — set `TTS_PROVIDER`).
4. `POST /api/story-image-prompts/{id}/video` (unchanged) — optional, animates
   an individual scene's still image into a motion clip via `VideoProviderContract`
   (Veo by default, Agnes AI as an opt-in alternative — set `VIDEO_PROVIDER`).
5. `POST /api/stories/{id}/video` (new) — assembles the whole story into one
   final video via `StoryVideoAssemblyService`: orders scenes, uses each
   scene's motion clip if step 4 ran for it (otherwise a Ken Burns pan over
   the still image), times each segment to match its narration length, and
   muxes everything together with narration audio.

**Image/video provider switching** (Cloudflare/Together/Agnes for images,
Veo/Agnes for video) is a single env var each — `IMAGE_PROVIDER` and
`VIDEO_PROVIDER` — with the existing behavior as the default in both cases.
See `.env.example` for all new variables (`AGNES_*`, `TTS_PROVIDER`,
`OPENAI_TTS_*`, `ELEVENLABS_*`, `COIN_COST_NARRATION_AUDIO`).

⚠️ **Agnes AI is unverified against a live account.** The provider classes
(`AgnesAiImageProvider`, `AgnesAiVideoProvider`) are built from Agnes' public
docs, not exercised against a real key — test in staging before relying on
it, and treat the API key as a low-trust, easily-revocable credential
regardless.

### Deployment requirements this pipeline adds

- **`ffmpeg` and `ffprobe` must be installed on the server** running the
  `videos` queue worker (`apt-get install ffmpeg` covers both). Used by
  `NarrationAudioService` (duration probing) and `StoryVideoAssemblyService`
  (segment building, concatenation, muxing) via `Illuminate\Support\Facades\Process`.
- **New migrations** — run `php artisan migrate`:
  - `add_narration_and_scene_number_to_story_image_prompts`
  - `add_narration_audio_to_story_image_prompts`
  - `create_story_videos_table`
- **New queue work** — `AssembleStoryVideoJob` runs on the `videos` queue
  (same one `GenerateVideoJob` already uses) with a 900s timeout; make sure
  your queue worker's own timeout/`retry_after` isn't shorter than that.
- **Storage** — narration audio and assembled videos are written to the
  `public` disk (`narration-audio/`, `story-videos-full/`), same as existing
  generated images/videos. No new disk config needed if that's already set up.
