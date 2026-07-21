# Updating the live site

The server is `<your-domain>` at `<VPS_IP>`, Ubuntu 24.04 + CloudPanel.
Site root: `/home/<site-user>/htdocs/<your-domain>`.

**You do not re-upload the project.** A git remote points at the server, so a
push sends only what changed — usually a few kilobytes.

## Code changes

From the `daifootcare-web` working copy:

```bash
git add -A && git commit -m "..."
git push origin main          # GitHub
git push vps main             # the server
```

The remote is configured as:

```bash
git remote add vps ssh://dfc/home/<site-user>/htdocs/<your-domain>
export GIT_SSH_COMMAND="ssh -i ~/.ssh/<your-key> -o IdentitiesOnly=yes"
```

`dfc` is an alias in `~/.ssh/config` pointing at the VPS with the deploy key.
The server repo has `receive.denyCurrentBranch = updateInstead`, so a push
updates the working tree in place.

### After a push that touched Vue, CSS or translations

The built dashboard is gitignored, so it has to be rebuilt **on the server**:

```bash
ssh dfc 'cd /home/<site-user>/htdocs/<your-domain> \
  && sudo -u diafootcare npm run build \
  && sudo -u diafootcare php artisan config:cache \
  && sudo -u diafootcare php artisan view:clear'
```

### After a push that touched `inference/pipeline.py`

```bash
ssh dfc 'systemctl restart dfc-inference'
```

It loads the models and the module once at startup and will otherwise keep
serving correct-looking results from the old code.

## A new APK

```bash
cd ../daifootcare_new/daifootcare_new
flutter build apk --release --dart-define=API_BASE_URL=https://<your-domain>/api/v1

scp -i ~/.ssh/<your-key> -o IdentitiesOnly=yes \
  build/app/outputs/flutter-apk/app-release.apk \
  root@<VPS_IP>:/home/<site-user>/htdocs/<your-domain>/public/downloads/diafootcare-latest.apk

ssh dfc 'chown -R <site-user>:<site-user> \
  /home/<site-user>/htdocs/<your-domain>/public/downloads'
```

The universal APK is served, not the per-ABI splits: one file that works on any
phone is worth more than 56 MB saved, given the app then downloads 200 MB of
models anyway.

**`--dart-define` is not optional.** Without it the build targets the emulator
address and fails silently on every real phone. Check with:

```bash
python -c "
import zipfile,re
z=zipfile.ZipFile('build/app/outputs/flutter-apk/app-release.apk')
print({m.decode() for i in z.infolist() if i.filename.endswith('libapp.so')
       for m in re.findall(rb'https?://[a-z0-9.\-]+[:0-9]*/api/v1', z.read(i.filename))})"
```

## New model files

Only when the models themselves change — this makes **every** offline phone
re-download 200 MB, so it is not a casual act.

```bash
scp -i ~/.ssh/<your-key> -o IdentitiesOnly=yes storage/app/models/* \
  root@<VPS_IP>:/home/<site-user>/htdocs/<your-domain>/storage/app/models/

ssh dfc 'cd /home/<site-user>/htdocs/<your-domain> \
  && chown -R <site-user>:<site-user> storage/app/models \
  && sudo -u diafootcare php artisan cache:clear \
  && systemctl restart dfc-inference'
```

Then compare `sha256sum` on both sides. The manifest checksums come from these
files, so a truncated upload becomes a verification failure on every phone.

## Ownership — the thing that keeps biting

CloudPanel runs php-fpm as **`diafootcare`**, not `www-data`. Anything written
as root breaks something later, and the symptoms are misleading:

| Written as root | What you see |
|---|---|
| `storage/`, `bootstrap/cache` | HTTP 500 with *no* log line — the log is unwritable |
| `node_modules/`, `auto-imports.d.ts` | `npm run build` fails with EACCES |
| `public/downloads/` | the APK 404s |

The fix is always the same:

```bash
ssh dfc 'chown -R <site-user>:<site-user> /home/<site-user>/htdocs/<your-domain>'
```

## Verifying after any change

```bash
curl -sI https://<your-domain>/                                     # 200
curl -s  https://<your-domain>/api/v1/models/manifest               # 6 files
curl -sI -r 0-99 https://<your-domain>/api/v1/models/file/tissue_head.tflite
#   ^ MUST be 206. A 200 means ranges broke and every resumed download
#     refetches from zero, silently, while the app looks fine.
curl -sI https://<your-domain>/downloads/diafootcare-latest.apk     # 200
ssh dfc 'systemctl is-active dfc-inference'                            # active
```

A browser cache will happily show you the old page after a deploy. Check the
asset hash in the HTML against `public/build/manifest.json` on the server before
concluding a change did not land.
