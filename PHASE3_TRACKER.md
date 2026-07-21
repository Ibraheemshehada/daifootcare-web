# Phase 3 — web side

Tracks the server work for offline model delivery and online analysis. The
mobile half is in `PHASE3_TRACKER.md` in the app repo; the two are meant to be
read together, because most of what follows is one contract split across them.

---

## START HERE — DEPLOYED AND LIVE

**https://<your-domain> is live**, with a valid Let's Encrypt certificate,
on a the VPS provider KVM 2 running Ubuntu 24.04 + CloudPanel at <VPS_IP>.

Verified end to end on the live server, not locally:

| | |
|---|---|
| `https://<your-domain>` | 200, valid cert |
| Manifest | 6 files, 199 MB, version `59ddd0882a04` |
| **Range request** | **206 Partial Content** — the one that mattered |
| Split-and-rejoined download | byte-identical, so resume works |
| Path traversal `../.env` | 404 |
| All six model files | sha256 identical to local |
| Analysis of a real wound photo | `Necrosis / High Risk / 6.15 x 4.27 cm` in 1115 ms — matching the local parity fixture exactly |
| Unauthenticated `/analyse` | 401 |
| `devices/register`, `wound-scans/sync`, `auth/guest` | 200 / 200 / 201 |
| Sidecar | active, 461 MB resident under LiteRT |
| Test data | removed — 0 users in the database |

The **release APK is built** against `https://<your-domain>/api/v1`, verified
by scanning the binary. arm64 is 30.5 MB.

### Five things CloudPanel broke, all now fixed in `deploy/bootstrap.sh`

Recorded because a redeploy would hit every one of them again:

1. **CloudPanel owns nginx, MySQL and PHP-FPM.** The original script would have
   `apt install mysql-server` beside CloudPanel's own — the way a working panel
   gets broken. It now detects CloudPanel and installs only what is missing.
2. **The vhost root pointed at the repo, not `public/`.** Laravel served a 403,
   and `.env` would have been reachable over HTTP.
3. **`chown www-data` gave a silent 500.** CloudPanel runs php-fpm as the *site*
   user, so www-data could not even write the log that would have explained it.
4. **Let's Encrypt failed with 404** after the root moved to `public/`: CloudPanel
   writes the ACME challenge to the site root, which nginx no longer served. A
   symlink keeps both paths true.
5. **The sidecar died with `status=200/CHDIR`** — `User=www-data` against a
   directory owned by the site user.

Plus one of mine: `pipeline.py` imported `tensorflow` while the script installs
`ai-edge-litert`. It now accepts either.

### Landing page and APK distribution

`https://<your-domain>` now offers the app directly:

- **Android** — a universal APK at `/downloads/diafootcare-latest.apk`, served
  by nginx, verified byte-identical to the build. The size is on the button and
  the extra ~200 MB for offline analysis is in the note, so both are known
  before tapping rather than after.
- **iOS** — an inert button reading "not yet", rather than hidden. Someone
  holding an iPhone is better served by a clear answer than by silence.
- The favicon set is generated from the app's own icon.

`deploy/UPDATING.md` documents the incremental update path: `git push vps main`
sends only changed files, then rebuild the dashboard on the server.

### Server hardening and DNS — done

- **Password SSH is disabled**, verified both directions: key auth works on a
  fresh connection, password auth is refused. Note it had to be changed in *two*
  files — `/etc/ssh/sshd_config.d/50-cloud-init.conf` also set it, and a drop-in
  wins, so editing only `sshd_config` looks successful and changes nothing.
  Backups are on the server as `*.bak.<timestamp>`.
- **Consequences**: the VPS provider's browser terminal no longer works, and
  `~/.ssh/<your-key>` is now the only way in. Back that key up.
- **The root password was changed** and the generated credentials file has been
  removed from the server.
- **DNS is clean.** The two Cloudflare A records that returned 409 were stale
  parking entries and cleared on their own; all four sources — both
  authoritative nameservers, Google and Cloudflare — now return only
  <VPS_IP>. Twelve consecutive requests to the site: twelve 200s.

### Still to do

Nothing blocking. Remaining backlog, none of which stops a pilot:

- **Background download** — the model transfer stops when the app leaves the
  foreground, because Android reclaims the Flutter engine under memory pressure.
  Resume means nothing is lost but time. An Android foreground service is the
  recommended fix; see the app repo's tracker.
- **Mandatory consent** — accepting is still required to use the app during the
  study period. An ethics board will ask about this.
- **Laravel test coverage is thin** (2 tests). The endpoints are verified by hand
  and by the app, but there is no regression net.
- **The dashboard shows a tissue summary, not the per-class probabilities.**
  `tissue_json` stores them and the app renders them; presentation only.
- **`_backfillMissingUuids` passes a null `whereArgs`** in the app — sqflite warns
  it will throw in a future version.

### If you are deploying

`DEPLOYMENT.md` is the document. Two things in it are load-bearing:

1. **nginx must serve the model files, not PHP** (§1, §4). `php artisan serve`
   could not deliver the 175 MB backbone once. The nginx block is written but
   **untested** — run the curl checks at the end of §4 before pointing a phone at
   it, and treat a `200` where a `206` is expected as a broken deployment.
2. **The inference sidecar runs on loopback** (§8) under systemd, one worker. It
   authenticates nobody; Laravel does the authorising. Never expose port 8500.

### Two things that will waste your afternoon if you forget

- **Restart the sidecar after editing `pipeline.py`.** It loads models and the
  module once at startup and will keep serving correct-looking results from the
  old code. It returned Callus where the parity suite said Necrosis during
  testing, purely from a stale process.
- **The parity fixtures are not in git.** They are photographs of real patients.
  `inference/testdata/` is gitignored; put them there before running
  `python inference/parity_test.py`.

### State of the world

| | |
|---|---|
| Parity suite | 4 checks pass |
| Laravel tests | pass |
| Vite build | clean |
| Endpoints verified | manifest, ranged file, analyse, sync |

### What is open here

1. **Model endpoints are unmetered** — anyone with the URL can pull 208 MB.
   Fine for a pilot; rate-limit by IP in nginx if it becomes a bill, rather than
   adding auth that would mean issuing a token before sign-in.
2. **The dashboard shows the tissue summary but not the per-class breakdown.**
   The app renders it and `tissue_json` already stores it, so this is a
   presentation task whenever a clinician wants the probabilities.
3. **`200003 necrosis` diverges 0.166 against a 0.107 threshold margin.** It
   cannot change today's headline because necrosis leads the severity order, but
   on another wound a class could cross. The suite prints the headroom every run.
4. **A phone that finishes a download now switches itself to offline mode**, and
   reports `mode=offline` on its next `devices/register` or mode update. Expect
   the dashboard's online/offline split to drift toward offline over a cohort's
   first weeks; that is the app following the files, not a bug.
5. **Wound photographs are not stored**, and `/analyse` deliberately does not
   keep the image it is given. Upload was built and reverted — see the app repo's
   tracker. If it comes back, **retention and honouring a withdrawal without
   hand-written SQL need solving first**.

### The database is MySQL now

Converted from SQLite and verified against a live MySQL/MariaDB server, not
reasoned about. `.env.example` and `DEPLOYMENT.md` §3 both target MySQL, and the
database creation step is written out with the `utf8mb4` charset spelled out.

What was checked, with a real server and real mobile-shaped payloads:

| | |
|---|---|
| All migrations, from scratch | run clean |
| Laravel test suite | passes |
| `devices/register`, `wound-scans/sync`, `sync/*`, `auth/guest` | all answer correctly |
| `tissue_json` round trip | intact, and the derived accessors still resolve |
| Arabic **and 4-byte emoji** | survive intact under utf8mb4 |
| Idempotent re-sync | 3 replays of one scan still yields 1 row |
| Datetimes | `2026-03-15T07:45:30Z` in, same instant out, app timezone UTC |
| Over-long field | 422 from validation, not a 500 from strict mode |

**The one real behavioural change: foreign keys are now enforced.** SQLite
ignored them by default; MySQL rejects a `wound_scan` whose `patient_id` does
not exist, and a `user` delete now genuinely cascades to patients, devices and
scans — which it silently did not before.

**This needs no fix, and specifically does not need a device fingerprint.**
Checked rather than assumed:

- `patient_id` is never taken from the request body. It is resolved from the
  authenticated user (`$user->patient()`), which is what stops one device filing
  scans against another person's chart.
- A **guest gets a real patient record**. `POST /auth/guest` returns
  `patient_id`, and a guest sync of a wound scan returns 200 — verified against
  MySQL with foreign keys live.
- So there is no orphan to accommodate. The enforcement is catching a class of
  bug that cannot currently occur, which is what you want from a constraint.

**A MAC address would not help and cannot be used.** Android 6+ returns
`02:00:00:00:00:00` to apps, and Play policy forbids non-resettable hardware IDs
for tracking. The app already documents this in `DeviceService` and uses an
app-scoped UUID v4 instead, which is Google's own recommendation and disappears
on uninstall — which is what a participant would expect. `device_uuid` already
does every job a MAC address was being considered for.

Two notes that are not MySQL's doing but surfaced while testing it:

- **The sync upsert is destructive for omitted fields.** A record that arrives
  without `tissue_json` sets that column to NULL rather than leaving it. The app
  always sends the full mapped record so this does not bite in practice, but a
  partial payload would quietly erase findings.
- The local dev database was SQLite and still exists at
  `database/database.sqlite`, with the old `.env` saved as `.env.sqlite.bak`.
  The device-test data in it was **not** migrated — it is throwaway test data,
  and the MySQL database starts from `DemoSeeder`.

### The rule that is easiest to break

The tissue severity order `necrosis > slough > granulation > callus > epithelial`
**must stay identical in three places**: `TISSUE_SEVERITY` in
`inference/pipeline.py`, `WoundScan::TISSUE_SEVERITY`, and
`TissueFinding.severityOrder` in the app. Each says so in a comment. Change one
and the two modes start describing the same wound differently — which is the
exact bug the parity suite exists to catch.

---

## Done

### F1 — model manifest and Range-capable delivery ✅

`GET /api/v1/models/manifest` publishes sizes, sha256 and a version derived from
the checksums. `GET /api/v1/models/file/{name}` serves the files with byte-range
support so the phone can resume.

Public on purpose: they are needed during setup, before a participant has an
account, and the files are not patient data. They are also unmetered — anyone
with the URL can pull 208 MB. Fine for a pilot; rate-limit by IP in nginx if it
becomes a bill, rather than adding auth that would mean issuing a token before
sign-in.

**nginx must serve these files, not PHP.** See `DEPLOYMENT.md` §1. `php artisan
serve` failed to deliver the 175 MB backbone once, and one failure answered
mid-transfer with an HTML error page that the phone wrote into the middle of a
model — a file of exactly the right length that could never verify.

### F5 — server-side analysis ✅

`inference/pipeline.py` ports the phone's analysis; `inference/server.py` is a
FastAPI sidecar on loopback; `POST /api/v1/analyse` is the authenticated,
rate-limited front door. The sidecar authenticates nobody by design — Laravel
decides who may ask. The photo is not stored: it was sent to be measured, not
kept, and retaining a patient's wound photograph needs its own consent and
retention rule, neither of which exists yet.

Verified: 401 unauthenticated, correct analysis authenticated, non-images
rejected before reaching the sidecar, ~1 s per analysis.

### Tissue findings ✅

The analysis reports **every** tissue class with its probability, whether it
cleared its own tuned threshold, and the threshold used — not one winning label.

The head is multi-label, and collapsing it to a single winner made the answer
turn on hundredths. On a real photograph the phone reported Necrosis and this
server reported Callus for the same wound, because the two sat 0.013 apart and
the platforms disagree by more than that.

| Piece | Where |
|---|---|
| `TissueFinding` | `inference/pipeline.py` |
| API field | `tissue_findings[]` on `POST /api/v1/analyse` |
| Storage | `wound_scans.tissue_json` = `{ label, findings[] }` |
| Derivation | `WoundScan::primary_tissue_type`, `tissue_summary`, `present_tissues` |
| Sync validation | shape-checked in `WoundScanSyncController` |
| Dashboard | tissue column on Scans, summary beside the risk badge on Patient detail |

The severity order is `necrosis > slough > granulation > callus > epithelial`
and **must stay identical** in three places: `TISSUE_SEVERITY` here,
`WoundScan::TISSUE_SEVERITY`, and `TissueFinding.severityOrder` in the app.
Callus deliberately sits below granulation — it previously headlined a visibly
granulating wound as "Callus" for scraping over its 0.45 threshold at 0.53 while
granulation sat at 0.96.

Derivation lives in the model rather than in Vue so the dashboard and the phone
cannot drift. Scans synced before findings existed carry only a label and
resolve through it; nothing is backfilled, because the probabilities were never
recorded and cannot be recovered from a label.

---

## Parity with the phone

`python inference/parity_test.py` — four checks, all passing:

- labels match exactly on both fixtures
- dimensions within 3% *or* two segmentation-mask pixels
- every class lands on the same side of its threshold on both platforms
- reports how much headroom is left before that stops being true

Two silent mismatches turned up during the port, neither visible without real
clinical photographs:

- `img.copyResize` defaults to **nearest**, not linear — a linear filter made
  the measured wound area 7.5% larger than the phone's.
- `Interpolation.cubic` is **Catmull-Rom** (a = −0.5); OpenCV's `INTER_CUBIC` is
  a = −0.75. On CLIP's 480→224 downscale that moved a class probability by 20
  points. `_catmull_rom_resize` now reproduces package:image exactly, edge
  quirks included.

After both, the residual equals what the JPEG decoders alone produce (~0.2 of
255 per pixel). That part is irreducible — package:image decodes in pure Dart —
and the tissue head amplifies it, so **exact numeric parity is unattainable**.
Label stability is engineered rather than assumed, which is what the severity
rule and the presence-flag test are for.

Known and recorded: `200003 necrosis` diverges 0.166 against a 0.107 margin to
its own threshold. It cannot change today's headline because necrosis already
leads the severity order, but on another wound a class could cross. The suite
prints this on every run.

**The fixtures are photographs of real patients and are gitignored.** Drop them
in `inference/testdata/` and regenerate the device figures with
`integration_test/analysis_parity_test.dart` in the app repo.

---

## Gotchas worth not rediscovering

- **Restart the sidecar after editing `pipeline.py`.** Models and module load
  once at startup, and the endpoint keeps answering correct-looking results from
  the old code. It returned Callus where the parity suite said Necrosis during
  testing, purely from a stale process.
- **Do not expose port 8500.** The sidecar authenticates nobody.
- `php artisan serve` is not a file server. See F1.

---

## Next

- **F6 — remove the models from the APK** (app repo). Ready: the app prefers
  downloaded files and online mode needs no local models. ~220 MB to ~20 MB.
- Dashboard shows the tissue summary; the per-class breakdown with probabilities
  is in `tissue_json` and not yet surfaced. The app shows it on the result
  screen — worth mirroring for clinicians.
- The tissue column was verified through the API and the production build, but
  not visually: there is no browser automation on this machine.
