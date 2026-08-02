# AI model suggestions — image & video generation

Researched August 2026. This space moves fast (some models below didn't exist
three months ago); treat this as a snapshot to re-check every quarter, not a
permanent decision.

Your pipeline already supports swappable providers (`CloudflareWorkersAiProvider`,
`TogetherAiImageProvider` for images; Vertex AI / Veo for video), so "switching
models" below mostly means adding a new thin provider class, not restructuring
anything.

---

## Image generation

### Recommendation: tiered, not single-model

The market has genuinely split into "a few models, each clearly best at one
thing" rather than one overall winner — so the highest-leverage change isn't
picking one new model, it's routing by *what kind of pin you're generating*.

| Use case | Model | Why |
|---|---|---|
| **Default / general story-scene pins** | **Flux 2 Pro** | Best all-round default: strong quality, fast, competitively priced, versatile across styles — the model most guides currently tell you to reach for first. |
| **Hero/premium images** (cover art, key story beats you want to look as good as possible) | **Imagen 4 Ultra** (Google) | Currently the quality ceiling for photorealism — slower and pricier, worth it only for images that matter most. |
| **Text-heavy pins** (quote cards, title cards, anything with your caption overlay baked into the image itself rather than composited after) | **Ideogram v3** | Still the clear leader specifically for in-image text rendering — genuinely no real alternative here as of this writing. |
| **Stylized / illustrated / "artistic" pins** (as opposed to photoreal) | **Nano Banana 2** (Google Gemini image line) | Optimizes for creative/distinctive output rather than photorealistic accuracy — a good fit if you want pins that look illustrated rather than like stock photos. |
| **High-volume / cheap draft generation** (e.g. generating several candidate images per prompt before picking one) | **Z-Image Turbo** | ~$0.01/image, ~1s generation — cheap enough to generate multiple drafts per prompt and keep the best, if you want to add a "generate 3, auto-pick 1" step later. |

⚠️ **One deprecation to act on now, not later**: Google is retiring the entire
**Imagen line on August 17, 2026** and pushing existing users to the Nano
Banana models instead. If `ImageGeneratorService` or any provider config
currently targets Imagen 4 directly, that needs to move to Nano Banana 2 (or
Imagen 4 Ultra only if you deliberately want the "hero image" tier above,
assuming it survives the retirement under a new name — verify before relying
on it past that date).

### Fitting this into your existing architecture
`ImageGeneratorService` already supports swappable provider backends. The
tiered table above maps naturally onto that: add one provider class per row
(most of these — Flux, Imagen/Nano Banana, Ideogram — are available through
your existing Together AI account or via a unified gateway like Atlas Cloud/fal.ai,
so this may be a config change rather than a new HTTP integration in some
cases) and pick the provider inside `ImagePromptAgent`/`ImageGeneratorAgent`
based on the prompt's category (already something Gemini is deciding via
structured output — the same call can also emit which tier a prompt needs).

---

## Video generation

### Recommendation: Veo 3.1 as default, Kling 3.0 as the cost lever

Your video pipeline already integrates **Google Veo via Vertex AI** — good
news, because **Veo 3.1** is consistently ranked as the safest
production/enterprise pick in 2026 comparisons: strong cinematic
prompt-following, native 48kHz synchronized dialogue (not just sound effects
— genuinely differentiated vs. most competitors), and tiered pricing
(Lite/Fast/Quality) from about **$0.05–$0.50/second** depending on tier. No
migration needed — just confirm you're pinned to 3.1, not an older Veo
version.

For cost-sensitive high-volume posting, evaluate **Kling 3.0** (Kuaishou) as
a second provider: native 4K/60fps, 15-second clips, multilingual lip-sync,
and consistently the "best value" pick across multiple 2026 comparisons at
roughly **$0.10/second** — meaningfully cheaper than Veo's higher tiers.
Access is via fal.ai or similar aggregators rather than a first-party API in
most Western integrations, so factor that into setup effort.

⚠️ **Don't build on Sora.** OpenAI discontinued the Sora web/app experience
on April 26, 2026, and the **Sora API shuts down September 24, 2026**. If
anything in the codebase references Sora (nothing appears to currently),
treat it as end-of-life, not a viable option to add.

### Runner-up worth knowing about
**Runway Gen-4.5** — was the #1-ranked model at launch in late 2025, has
since been overtaken on raw benchmark scores by newer entrants (ByteDance's
Seedance 2.0, Alibaba's HappyHorse-1.0) but retains the strongest *editing*
workflow (motion brushes, scene/character consistency tools) if you ever want
finer creative control over generated clips rather than pure prompt→video.
Credit-based pricing rather than per-second, which can be easier to budget
for than the per-second models above.

### Fitting this into your existing architecture
Same pattern as images: this is a provider-selection decision inside the
existing video generation pipeline, not a rebuild. Given Veo is already
wired up via Vertex AI, the lowest-effort next step is just confirming the
3.1 pin; adding Kling as a second provider is the natural "cost tier" lever
if/when Pinterest/social posting volume grows enough that per-second video
cost starts to matter.

---

## Summary table

| | Default | Premium/Quality | Cheap/Volume | Avoid |
|---|---|---|---|---|
| **Images** | Flux 2 Pro | Imagen 4 Ultra → migrating to Nano Banana 2 (Aug 17 2026 cutover) | Z-Image Turbo | — |
| **Video** | Veo 3.1 (already integrated) | Veo 3.1 Quality tier | Kling 3.0 | Sora 2 (EOL Sep 2026) |

*Sources: cross-referenced against multiple independent 2026 model comparison
guides (Atlas Cloud, Pinggy, Tech Insider, DIY AI, AIViewer, and others) —
pricing and rankings move quickly, so re-verify current numbers on each
provider's own pricing page before committing budget.*
