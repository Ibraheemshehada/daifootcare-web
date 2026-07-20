# Phase 3 — web side

Tracks the server work for offline model delivery and online analysis. The
mobile half is in `PHASE3_TRACKER.md` in the app repo; the two are meant to be
read together, because most of what follows is one contract split across them.

---

## START HERE — handoff for 2026-07-20

**Phase 3 is feature-complete on both sides.** F6 landed in the app repo: the
models are out of the APK, so the release build went 284.8 MB to 86.7 MB
universal, and ~30 MB for the single ABI a phone installs.

That makes this server the *only* source of the models. Nothing here changed for
it, but the consequence is worth stating plainly: **if the model endpoints are
down or misconfigured, a new offline-mode install has no way to analyse a
wound.** The nginx checks in `DEPLOYMENT.md` §4 stopped being a nicety.

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
not exist. Nothing in the app relies on writing orphans, and a `user` delete now
genuinely cascades to patients, devices and scans — which it silently did not
before.

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
