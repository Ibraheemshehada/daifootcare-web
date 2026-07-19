# Phase 3 — web side

Tracks the server work for offline model delivery and online analysis. The
mobile half is in `PHASE3_TRACKER.md` in the app repo; the two are meant to be
read together, because most of what follows is one contract split across them.

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
decides who may ask.

`/analyse` still does not store the image it is given: that photo was sent to be
measured. Photographs are kept only through the separate upload endpoint below,
which the phone calls after the scan record has synced and under a consent that
names it. Keeping the two paths apart means analysing a photo and retaining one
remain different decisions.

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

### Wound photographs ✅

`POST /wound-scans/{local_uuid}/image` accepts the photograph; `GET
/wound-scans/{scan}/image` streams it back. Keyed on `local_uuid` on the way in
because the phone knows it at capture time and the server id only after a
successful sync — and the upload has to survive being retried later, on another
connection, after the app restarts.

These are the most sensitive things the system holds. A wound photograph is
identifiable on its own — skin, scars, tattoos, jewellery, sometimes a
clinician's hands or a ward behind. So:

- stored on a `private` disk, never under `public/`, never given a URL that
  works without authentication
- streamed through a controller so every read passes an authorisation check
  rather than being guessable from a path
- readable by clinicians and the owning patient, nobody else
- `image_path` is hidden from API responses; clients get a `has_image` flag,
  because a path handed to a client is eventually fetched directly
- `Cache-Control: private, no-store` so a shared proxy never holds one

The `private` disk is defined separately from `local` on purpose: changing the
default disk must not be able to move patient images somewhere servable.

Uploads are idempotent — a retry after a timeout replaces rather than duplicates.
A `409` means the scan record has not synced yet, which is expected rather than
an error, and the phone retries on a later pass.

**The dashboard fetches images as blobs**, not with a plain `<img src>`: reads
are authorised and the dashboard authenticates with a bearer token, which a bare
`src` would not send. `WoundImage.vue` revokes its object URL on unmount so a
patient's photograph is not left resident after the clinician navigates away.

Verified: 401 unauthenticated on upload and read, 409 for an unsynced scan, 422
for a non-image, byte-identical round trip, nothing under `public/`, and
`image_path` absent from `/wound-scans`.

**Consent.** The app moved to consent v3 before sending anything, because v2's
"wound scans and measurements" did not clearly cover uploading the photograph
itself. Every participant is re-prompted.

**Open: retention.** Nothing deletes these. Honouring a withdrawal is a manual
database operation today. A study holding identifiable images needs a stated
retention period and a way to action a withdrawal without SQL.

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
