# Releasing Sunset

Checklist for maintainers tagging a new Sunset release.

## Before tagging

1. **Run the full test suite against all backing services.**

   ```bash
   docker compose up -d            # redis + localstack + rabbitmq
   vendor/bin/phpunit
   ```

   All tests must pass. Skipped Dusk tests are acceptable when ChromeDriver isn't wired in the local environment; the rest must be green.

2. **Rebuild the dashboard bundle.**

   The compiled JS/CSS at `public-dist/app.js` and `public-dist/app.css` ships in the Composer package so consumers don't need Node.js. The build is NOT regenerated automatically — maintainers must rebuild before tagging or consumers get whatever's checked in (potentially stale).

   ```bash
   npm install                     # if dev-deps changed
   npm run build
   git add public-dist/
   git diff --staged public-dist/  # confirm the rebuild is meaningful
   git commit -m "build: rebuild dashboard bundle for v0.X.Y"  # if changed
   ```

   If `npm run build` produces no diff, the bundle is already current — no commit needed.

3. **Verify documentation matches the release.**

   - `README.md` — feature list reflects the new version
   - `UPGRADING.md` — has a migration section for the new version
   - `composer.json` — version constraints (if you pin one) are accurate

4. **Confirm the changelog / release notes.**

   Sunset uses commit history as the changelog. Run:

   ```bash
   git log --oneline <previous-tag>..HEAD
   ```

   to draft release notes. Look for any commit that hasn't been documented in `UPGRADING.md` and either add it or decide it doesn't need consumer-facing notice.

5. **Confirm there are no `Laravel\Horizon\*` references in `src/`** (except for the two prose-comment historical references in `src/Tags.php` and `src/Contracts/Silenced.php`).

   ```bash
   grep -rn "Laravel\\\\Horizon" src/
   ```

## Tagging

```bash
git tag -a v0.X.Y -m "v0.X.Y — <one-line summary>"
git push origin v0.X.Y
```

For release candidates:

```bash
git tag -a v0.X.Y-rc.1 -m "v0.X.Y release candidate 1"
git push origin v0.X.Y-rc.1
```

## After tagging

1. **Soak the release.** Run it against at least one production-shaped consumer app for a week before promoting an RC to a final tag.

2. **Watch for issues.** Common post-release problems with Sunset:
   - Dashboard 500s on first load (usually a missing `php artisan sunset:install`)
   - Stale bundle (consumer installed before re-running `npm run build` on master)
   - Auth gate denying everyone (consumer didn't register `Sunset::auth(...)`)

3. **Update the next branch's `UPGRADING.md` section.** Even if you don't have v0.X.Y+1 work in flight yet, having a stub helps the next release pass faster.
