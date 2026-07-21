# Prompt for continuing DiaFootCare on the web repo

Paste everything below the line into a fresh Claude Code session opened in the
`daifootcare-web` folder.

---

You are continuing work on **DiaFootCare** — a diabetic foot ulcer monitoring
system. This repo is the Laravel 12 + Vue 3 server and clinical dashboard. The
Flutter app lives in a separate repo (`daifootcare_new`) and is **feature
complete**; do not assume you can change it, but do assume its behaviour is
fixed and this server must keep matching it.

**Read these first, in order.** They carry the reasoning behind decisions that
look arbitrary otherwise:

1. `PHASE3_TRACKER.md` — the handoff at the top, then what is open
2. `DEPLOYMENT.md` — the whole thing if deploying; §1, §1b and §4 at minimum
3. `inference/README.md` — the parity contract with the phone

## Where things stand

Phase 3 is feature complete on both sides. The server does four things:

- serves the **208 MB TFLite model bundle** with HTTP Range support, so phones
  in offline mode can download and resume it
- runs the **same analysis the phone runs**, in a Python sidecar, for phones in
  online mode
- accepts **idempotent record sync** from the app across ~11 record types
- shows clinicians a dashboard

Verified working: migrations on MySQL, the Laravel test suite, the 4-check
parity suite, the Vite build, and every endpoint the app calls.

## Five things that will cost you a day if you do not know them

1. **nginx must serve the model files, not PHP.** `php artisan serve` could not
   deliver the 175 MB backbone once — it answered mid-transfer with an HTML
   error page that the phone wrote into the middle of a model file, producing a
   file of exactly the right length that could never verify. php-fpm would pin a
   worker for the whole transfer. `DEPLOYMENT.md` §4 has the config, and it is
   **the only untested part of the deployment**. Run the curl checks at the end
   of that section; a `200` where a `206` is expected means ranges are broken
   and every resumed download silently refetches from zero.

2. **Restart the sidecar after editing `inference/pipeline.py`.** It loads the
   models and the module once at startup and will keep serving correct-looking
   results from the old code. This cost real debugging time already.

3. **The tissue severity order must stay identical in three files** —
   `TISSUE_SEVERITY` in `inference/pipeline.py`, `WoundScan::TISSUE_SEVERITY`,
   and `TissueFinding.severityOrder` in the app. Change one and the two analysis
   modes start describing the same wound differently. This is the exact bug
   `inference/parity_test.py` exists to catch.

4. **Exact numeric parity with the phone is impossible** and that is understood,
   not a bug to fix. `package:image` decodes JPEG in pure Dart with different
   rounding than libjpeg, and the tissue head amplifies the difference. Label
   stability is *engineered* — the headline tissue is the most clinically
   serious class clearing its threshold, never the highest-scoring one, because
   ranking by probability made the same photograph read "Necrosis" on the phone
   and "Callus" on the server.

5. **`inference/testdata/` is gitignored and holds photographs of real
   patients.** The parity suite needs them. Never commit them, never put them in
   a bug report, never upload them anywhere.

## The immediate task

Deploy to a the VPS provider VPS (**KVM 2** recommended — see `DEPLOYMENT.md` §1b for
why, sized from measured memory and CPU, not a rule of thumb).

Follow `DEPLOYMENT.md` in order. Two things it will not tell you:

- **Rotate `APP_KEY` on the server.** A `.env` backup containing the old one was
  committed to this repo's history and later removed. `php artisan key:generate`
  is enough; nothing depends on the old value.
- **The APK must be rebuilt after the domain exists**, because `API_BASE_URL` is
  compiled in at build time. The app repo has the command. Do not hand anyone an
  installer before the server answers.

## Open work, roughly prioritised

1. The dashboard shows a tissue **summary** but not the per-class probabilities.
   `tissue_json` already stores them and the app already renders them, so this
   is presentation only.
2. Model endpoints are **unmetered** — anyone with the URL can pull 208 MB.
   Fine for a pilot; rate-limit by IP in nginx if it becomes a bill, rather than
   adding auth that would mean issuing a token before sign-in.
3. **Laravel test coverage is thin** — 2 tests. The endpoints are verified by
   hand and by the app, but there is no regression net. Feature tests for
   `wound-scans/sync` and `/analyse` would be the highest-value addition.
4. **Wound photographs are not stored.** `/analyse` deliberately does not keep
   the image. Upload was built once and reverted, because it began retaining
   identifiable images under a consent promise ("withdrawal removes your
   photographs") with no retention or withdrawal mechanism behind it. If it
   comes back, **solve retention first**.

## How to work here

- **Verify rather than assert.** Almost every real bug in this project was found
  by running the thing, not by reading it — an HTML error page inside a model
  file, a label that flipped between platforms, a dialog that logged 19
  framework errors per scan. If you claim something works, show the output.
- **Say when something is untested.** The nginx config is marked untested in the
  docs precisely because it is. Keep that habit.
- **This is a clinical app.** Never let it invent a measurement or a label. When
  analysis cannot run, it must say so — there is an `AnalysisException` family in
  the app for exactly this, and the server should not undermine it by returning
  plausible-looking defaults.
- Update `PHASE3_TRACKER.md` as you go. It is the handoff between sessions.
