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
