# Deploying DiaFootCare to a VPS

Everything here has been verified locally against the real 208 MB model bundle
and a real device, except where a step is explicitly marked as untested.

---

## 1. The one thing that will bite you

**Do not let PHP serve the model files.**

`php artisan serve` could not deliver the 175 MB backbone even once. It is
single-threaded, and it gave up partway through every attempt — including one
where it answered mid-transfer with an HTML error page, which the app then wrote
into the middle of the model. The result was a file of exactly the right length
that could never pass its checksum, costing a full re-download to discover.

The app now rejects a response that is not shaped like the file it asked for, so
that particular corruption cannot recur. But the underlying point stands: a
200 MB download is a job for a web server, not for PHP. `php-fpm` behind nginx
is better than `artisan serve` and will mostly work, but it still pins a PHP
worker for the entire transfer — with a handful of patients downloading at once
you will exhaust the pool and the whole API stops responding.

**nginx must serve the model files directly.** The config in §4 does this.

---

## 2. What to upload

| What | Where it goes | Notes |
|---|---|---|
| The Laravel repo | `/var/www/diafootcare` | `git clone`, or rsync the working copy |
| The 6 model files | `/var/www/diafootcare/storage/app/models/` | **Not in git** — 208 MB, excluded on purpose |
| `.env` | `/var/www/diafootcare/.env` | Never committed; see §3 |

The model files, with the sizes to check against after transfer:

```
clip_backbone_fp16.tflite         175837532
tissue_head.tflite                 20207004
model1_wound_fp16.tflite           12446944
infection_ischaemia_head.tflite      268452
infection_ischaemia_head_meta.json       691
tissue_head_meta.json                    313
```

Upload them with rsync so an interrupted transfer resumes rather than restarts:

```bash
rsync -avP --partial storage/app/models/ \
  user@your-vps:/var/www/diafootcare/storage/app/models/
```

Then confirm the server sees exactly what you sent — the manifest's checksums
are computed from these files, so a truncated upload becomes a checksum failure
on every patient's phone:

```bash
ssh user@your-vps 'cd /var/www/diafootcare/storage/app/models && sha256sum *'
sha256sum storage/app/models/*        # compare locally
```

---

## 3. Server setup

```bash
sudo apt update
sudo apt install -y nginx php8.3-fpm php8.3-{mbstring,xml,curl,zip,sqlite3,bcmath} \
                    composer git

cd /var/www/diafootcare
composer install --no-dev --optimize-autoloader

cp .env.example .env
php artisan key:generate
```

Edit `.env`:

```ini
APP_ENV=production
APP_DEBUG=false                       # leave this false; see the note below
APP_URL=https://your-domain

DB_CONNECTION=sqlite                  # or mysql for anything beyond a pilot
# For sqlite: touch database/database.sqlite and use its absolute path.

SESSION_DRIVER=database
CACHE_STORE=database
```

`APP_DEBUG=false` matters more than usual here: with it on, a PHP error inside a
model request returns a full HTML stack trace with a 200, which is exactly the
body that corrupted a download.

```bash
php artisan migrate --force
php artisan config:cache && php artisan route:cache

npm ci && npm run build                        # builds the Vue dashboard

sudo chown -R www-data:www-data storage bootstrap/cache
```

### The first login

`DemoSeeder` creates three accounts, all with the password `password`:

| Email | Role |
|---|---|
| `admin@daifootcare.test` | admin |
| `doctor@daifootcare.test` | clinician |
| `patient@daifootcare.test` | patient |

It also creates demo patients and activity, which is useful for checking the
dashboard renders but is **not** something to leave in a real deployment:

```bash
php artisan db:seed --class=DemoSeeder     # staging / demo only
```

On a real server, create one real admin instead and skip the seeder entirely:

```bash
php artisan tinker --execute='
$u = App\Models\User::create([
    "name" => "Your Name",
    "email" => "you@example.com",
    "password" => "put-a-real-password-here",
    "locale" => "en",
]);
$u->forceFill(["role" => App\Models\User::ROLE_ADMIN])->save();
echo "created\n";'
```

`role` is deliberately not mass-assignable, which is why it is set separately —
a registration request cannot promote itself to admin.

---

## 4. nginx

> **Not verified.** There is no nginx on the development machine, so this config
> is written from the documentation rather than tested. The verification
> commands at the end of this section are the ones that matter — run them before
> pointing a phone at the server, and treat a `200` where a `206` is expected as
> a broken deployment, not a detail.
>
> What *was* verified is the client against a threaded, Range-capable server:
> all 208 MB arrived byte-identical on the device. So the app's side of this
> contract is known good; only the nginx wiring below is untested.

```nginx
server {
    listen 443 ssl http2;
    server_name your-domain;

    ssl_certificate     /etc/letsencrypt/live/your-domain/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/your-domain/privkey.pem;

    root /var/www/diafootcare/public;
    index index.php;

    # The model files, served by nginx itself.
    #
    # The path matches what the app already requests, so no client change is
    # needed. Range requests are handled natively, which is what makes pause,
    # resume and recovery from a dropped connection work.
    location ^~ /api/v1/models/file/ {
        alias /var/www/diafootcare/storage/app/models/;

        add_header Accept-Ranges bytes;
        default_type application/octet-stream;

        # A 200 MB download over a weak connection is slow, not stuck.
        send_timeout 1h;

        # sendfile streams from disk without buffering the file in memory.
        sendfile on;
        tcp_nopush on;

        # These files are immutable; a new bundle changes the manifest version.
        add_header Cache-Control "public, max-age=31536000, immutable";

        # No PHP under this path, whatever else is configured.
        location ~ \.php$ { return 404; }
    }

    # The manifest is small and dynamic — PHP handles it.
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }

    client_max_body_size 25M;   # wound photos
}
```

The trailing slash on the `alias` path is required; without it nginx joins the
paths wrongly and every model 404s.

Reload and verify **before** pointing any phone at it:

```bash
sudo nginx -t && sudo systemctl reload nginx

# Must return 206, not 200 — a 200 here means nginx is not handling ranges and
# every resume will silently re-download the whole file.
curl -sI -r 0-99 https://your-domain/api/v1/models/file/tissue_head.tflite \
  | head -3

# Must match the local file exactly.
curl -s https://your-domain/api/v1/models/file/tissue_head.tflite | sha256sum

# Must list six files with a version string.
curl -s https://your-domain/api/v1/models/manifest
```

---

## 5. Pointing the app at it

The base URL is compiled in, so this is a build-time flag:

```bash
flutter build apk --release \
  --dart-define=API_BASE_URL=https://your-domain/api/v1
```

Without the flag the build still targets `http://10.0.2.2:8123/api/v1`, the
emulator's alias for your laptop — it will work in testing and fail on every
real phone.

---

## 6. Does the API need changing?

No. The endpoints the app uses already exist and are deployed by the steps
above:

| Endpoint | Auth | Purpose |
|---|---|---|
| `GET /api/v1/models/manifest` | public | Sizes, checksums, bundle version |
| `GET /api/v1/models/file/{name}` | public | The files, with Range support |
| `POST /api/v1/devices/register` | token | Registers the install and its mode |
| `PATCH /api/v1/devices/{uuid}/mode` | token | Mode changes, download completion |

The model endpoints are deliberately public: they are needed during setup,
before a participant has an account, and the files are not patient data.

**One decision left for you.** They are also unmetered — anyone with the URL can
pull 208 MB. For a pilot that is fine. If it becomes a bill, the cheapest fix is
rate limiting by IP in nginx rather than adding auth, which would mean handing
out a token before sign-in.

---

## 7. Updating the models later

The manifest version is derived from the file checksums, so it changes by
itself when you replace a file. The app compares versions, discards a stale
local bundle and re-downloads.

```bash
rsync -avP --partial new-models/ /var/www/diafootcare/storage/app/models/
php artisan cache:clear     # the manifest is cached on size and mtime
```

Every phone in offline mode will then re-download 200 MB, so this is not a thing
to do casually.

---

## 8. The inference sidecar (online mode)

Patients who choose online mode do not download the models — the server analyses
their photo instead. PHP cannot run TFLite, so this is a separate Python service.
Laravel proxies to it; it is never reached directly.

```bash
sudo apt install -y python3-venv python3-pip
cd /var/www/diafootcare/inference
python3 -m venv .venv && . .venv/bin/activate
pip install -r requirements.txt
```

On a VPS prefer the small runtime over full TensorFlow — `tensorflow` pulls
about 600 MB for an interpreter you could have in 50:

```bash
pip install ai-edge-litert
# then in pipeline.py swap the tf.lite.Interpreter import for
#   from ai_edge_litert.interpreter import Interpreter
```

Run it under systemd so it survives a reboot — `/etc/systemd/system/dfc-inference.service`:

```ini
[Unit]
Description=DiaFootCare inference
After=network.target

[Service]
User=www-data
WorkingDirectory=/var/www/diafootcare/inference
Environment=DFC_MODELS_DIR=/var/www/diafootcare/storage/app/models
# 127.0.0.1 is load-bearing. This service runs the models and authenticates
# nobody; Laravel is its only client and does the authorising. Do not bind 0.0.0.0.
ExecStart=/var/www/diafootcare/inference/.venv/bin/uvicorn server:app           --host 127.0.0.1 --port 8500 --workers 1
Restart=always

[Install]
WantedBy=multi-user.target
```

One worker, deliberately: each holds the models in memory, so a second worker
costs another ~200 MB of RAM for capacity you almost certainly do not need. Add
workers only when the queue is measurably the bottleneck.

```bash
sudo systemctl enable --now dfc-inference
curl -s http://127.0.0.1:8500/health      # {"status":"ok",...}
```

Tell Laravel where it is, in `.env`:

```ini
INFERENCE_URL=http://127.0.0.1:8500
INFERENCE_TIMEOUT=30
```

Then check the whole chain, which is the part worth verifying:

```bash
# Must be 401 — the endpoint is authenticated.
curl -s -o /dev/null -w '%{http_code}
' -X POST https://your-domain/api/v1/analyse   -H 'Accept: application/json' -F 'image=@wound.jpg'

# With a token: an analysis in about a second.
curl -s -X POST https://your-domain/api/v1/analyse   -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'   -F 'image=@wound.jpg' -F 'pixels_per_cm=40'
```

**nginx must not expose port 8500.** Nothing in the config in §4 does, but if you
add a proxy block for it you have published an unauthenticated analysis service.

### Keeping the two modes agreeing

`inference/pipeline.py` is a port of the phone's `ai_service.dart`, and the two
must describe a wound identically. Run the parity suite after any change to
either:

```bash
cd /var/www/diafootcare && python inference/parity_test.py
```

It needs the fixture photographs in `inference/testdata/` — real clinical
images, deliberately not in git. See `inference/README.md`.

---

## 9. What is not built yet


**Removing the models from the APK (F6).** This is what takes the download from
~220 MB to ~20 MB. It is ready technically — the app already prefers downloaded
files and online mode no longer needs local models — but it should not land
while the tissue-label question in `PHASE3_TRACKER.md` is open, since that is
the last thing still in flux on the analysis path.

Until it lands the APK still carries the models, and the download is additive
rather than a replacement.
