# Deploying onto the CloudPanel VPS

The target is a VPS running **Ubuntu 24.04 with CloudPanel**.
That changes `DEPLOYMENT.md` in one important way, and makes the browser
genuinely useful for part of the work.

## What CloudPanel already owns

CloudPanel installs and manages **nginx, MySQL and PHP-FPM**. Do not install a
second copy of any of them. `apt install mysql-server` beside CloudPanel's MySQL
is the specific mistake that breaks a working panel.

`deploy/bootstrap.sh` detects CloudPanel and skips those packages. It installs
only what the panel does not provide: Python for the inference sidecar, Node for
the dashboard build, and composer.

Where CloudPanel puts things:

| | |
|---|---|
| Site files | `/home/<site-user>/htdocs/<domain>` |
| nginx vhost | `/etc/nginx/sites-enabled/<domain>.conf` |
| Vhost editing | The panel's **Vhost Editor** — it validates and reverts a broken config |
| Databases | Panel UI, **Databases → Add Database** |

## The split of work

Browser and shell each do what they are actually good at.

### In CloudPanel's UI — a browser can do all of this

Reachable at `https://<server-ip>:8443`. This is **not** the provider hosting panel,
which is a billing panel and correctly off limits.

1. **Add a site.** Sites → Add Site → PHP site, PHP **8.3**. Note the site user
   it creates; the document root follows from it.
2. **Add a database.** Databases → Add Database. Name it `diafootcare`. Keep the
   generated password somewhere real — a password manager, not a chat message.
3. **Point DNS** at `<VPS_IP>`, then **issue SSL** — Sites → your site →
   SSL/TLS → Let's Encrypt. This must succeed before the app is any use, because
   the phone pins an `https://` base URL at build time.
4. Later: paste the model-serving block into the **Vhost Editor** (below).

### Over SSH — needs a terminal

```bash
ssh <user>@<VPS_IP>

# Clone into the site's document root. Use the path CloudPanel created.
cd /home/<site-user>/htdocs/<domain>
git clone https://github.com/Ibraheemshehada/daifootcare-web.git .

# Put the database name, user and password from step 2 into .env first,
# then run the bootstrap.
sudo bash deploy/bootstrap.sh
```

Then upload the models — 208 MB, not in git:

```bash
# From the development machine. sftp, because Windows has no rsync and
# `reput` resumes where `put` would restart the 175 MB backbone.
sftp <user>@<VPS_IP>
  cd /home/<site-user>/htdocs/<domain>/storage/app/models
  lcd storage/app/models
  reput clip_backbone_fp16.tflite
  reput tissue_head.tflite
  reput model1_wound_fp16.tflite
  reput infection_ischaemia_head.tflite
  reput infection_ischaemia_head_meta.json
  reput tissue_head_meta.json
  bye
```

Then prove the upload is intact — this is not optional. A truncated 175 MB file
becomes a checksum failure on every patient's phone, and you would debug it from
the wrong end:

```bash
ssh <user>@<VPS_IP> 'cd /home/<site-user>/htdocs/<domain>/storage/app/models && sha256sum *'
sha256sum storage/app/models/*     # compare, locally
```

## The nginx block

Paste into **Vhost Editor**, inside the existing `server { }`, above the
`location / ` block. The panel checks the syntax and reverts if it is wrong,
which is a better safety net than editing the file by hand.

```nginx
# Model files, served by nginx itself. PHP must never see these: a 175 MB
# transfer pins a php-fpm worker for its whole duration, and php artisan serve
# once answered mid-transfer with an HTML error page that a phone wrote into
# the middle of a model file.
location ^~ /api/v1/models/file/ {
    alias /home/<site-user>/htdocs/<domain>/storage/app/models/;

    add_header Accept-Ranges bytes;
    default_type application/octet-stream;

    send_timeout 1h;          # a 200 MB download is slow, not stuck
    sendfile on;
    tcp_nopush on;

    add_header Cache-Control "public, max-age=31536000, immutable";

    location ~ \.php$ { return 404; }
}
```

The trailing slash on the `alias` path is required. Without it nginx joins the
paths wrongly and every model 404s.

## Verifying — a browser can do most of this

Once the site is live, in order:

```bash
# 1. Must list six files and a version string.
curl -s https://<domain>/api/v1/models/manifest

# 2. Must be 206, NOT 200. This is the one that matters.
curl -sI -r 0-99 https://<domain>/api/v1/models/file/tissue_head.tflite | head -3

# 3. Must match the local file.
curl -s https://<domain>/api/v1/models/file/tissue_head.tflite | sha256sum

# 4. Must be 401 — the endpoint is authenticated.
curl -s -o /dev/null -w '%{http_code}\n' -X POST https://<domain>/api/v1/analyse
```

**A `200` in step 2 means the deployment is broken**, even though nothing looks
wrong. Ranges are not working, so every resumed download silently refetches from
zero and the app burns a patient's data allowance while appearing fine.

The manifest URL (1) can be opened in a browser tab. Steps 2–4 need a tool that
shows response headers and status codes.

## Last

Only after the domain answers, rebuild the app — `API_BASE_URL` is compiled in:

```bash
flutter build apk --release \
  --dart-define=API_BASE_URL=https://<domain>/api/v1
```

Do not hand anyone an installer before the server is up. It will be built
against the wrong address and fail silently on every phone.

## Two things not to do

- **Do not run `DemoSeeder` here.** It creates three accounts with the password
  `password`, including a clinician login. It now refuses when
  `APP_ENV=production`; do not work around that. Create one real admin —
  `DEPLOYMENT.md` §3 has the command.
- **Do not put credentials in a chat, a shared document, or a file emailed to
  anyone.** The database password, `APP_KEY` and SSH credentials belong in a
  password manager.
