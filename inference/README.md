# Inference sidecar

Wound analysis for online mode. PHP has no TFLite runtime, so the models run
here and Laravel proxies to them.

```bash
pip install -r requirements.txt
uvicorn server:app --host 127.0.0.1 --port 8500
```

Loopback only, on purpose. This service runs models and authenticates nobody —
Laravel is its single client and decides who may ask. Do not expose it.

## Parity with the phone

`pipeline.py` is a port of `ai_service.dart`. The two must describe a wound the
same way: a patient switching modes, or two patients on different modes, must
not get different answers because of where the arithmetic ran.

```bash
python parity_test.py
```

The fixtures are real clinical photographs and are **not in git** — drop them in
`testdata/` and regenerate the recorded device figures with
`integration_test/analysis_parity_test.dart` in the mobile repo.

Two things bit during the port, both invisible against a happy path:

- `img.copyResize` defaults to **nearest**, not linear. Using a linear filter
  here made the measured wound area 7.5% larger than the phone's.
- `Interpolation.cubic` is **Catmull-Rom** (a = -0.5); OpenCV's `INTER_CUBIC` is
  a = -0.75. On CLIP's 480→224 downscale that moved one class's probability by
  20 points. `_catmull_rom_resize` reproduces package:image exactly, edge quirks
  included.

After both fixes the residual equals what the JPEG decoders alone produce
(~0.2 of 255 per pixel). That last part is irreducible — package:image decodes
in pure Dart — and the tissue head amplifies it, so **exact numeric parity is
not attainable**. Label stability is therefore engineered rather than assumed:
the headline tissue is the most clinically serious class clearing its threshold,
not the highest-scoring one, because the latter flipped Necrosis to Callus
between modes on a real photograph.
