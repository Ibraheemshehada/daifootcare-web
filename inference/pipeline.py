"""Wound analysis, server side.

A port of the on-device pipeline in
`lib/features/wound/analysis/services/ai_service.dart`. It exists because PHP
has no TFLite runtime, so online mode needs somewhere to run the same four
models.

The two implementations must agree. A patient who switches from offline to
online mode, or a clinician comparing two patients on different modes, must not
see the wound described differently because of where the arithmetic happened.
Every step below is therefore a deliberate match to the Dart code rather than
the most natural way to write it in Python — the comments call out the places
where that costs something, and `parity_test.py` measures what disagreement
remains.
"""

from __future__ import annotations

import math
from dataclasses import dataclass, asdict
from typing import Optional

import base64
import cv2
import numpy as np

from ring import detect_ring, is_printed_label
from PIL import Image, ImageOps

# ---------------------------------------------------------------------------
# Configuration — must stay in step with the constants in ai_service.dart
# ---------------------------------------------------------------------------

MASK_THRESHOLD = 0.5
USE_FLIP_TTA = True
ASSUMED_FRAME_CM = 12.0

TISSUE_CLASSES = ['epithelial', 'granulation', 'necrosis', 'callus', 'slough']
TISSUE_THRESHOLDS = {
    'epithelial': 0.09,
    'granulation': 0.43,
    'necrosis': 0.60,
    'callus': 0.45,
    'slough': 0.63,
}

CLIP_MEAN = np.array([0.48145466, 0.45782750, 0.40821073], dtype=np.float32)
CLIP_STD = np.array([0.26862954, 0.26130258, 0.27577711], dtype=np.float32)

# Descending clinical seriousness, used to choose which present class to
# headline. Must match TissueFinding.severityOrder in tissue_finding.dart.
#
# Necrotic and sloughy tissue are devitalised and drive debridement decisions,
# so they lead. Callus sits below granulation deliberately: it is a pressure
# finding worth naming, but a bed that is mostly granulating should not be
# headlined as callus because callus scraped over a low threshold.
TISSUE_SEVERITY = ['necrosis', 'slough', 'granulation', 'callus', 'epithelial']

INFECTION_THRESHOLD = 0.41
ISCHAEMIA_THRESHOLD = 0.61


@dataclass
class TissueFinding:
    """One tissue class the model considered, and what it concluded.

    Mirrors TissueFinding in the app. The head is multi-label — a wound bed
    holds several tissue types at once — so every class is reported with the
    threshold it was judged against, rather than collapsing the answer to one
    winning label and discarding the rest.
    """

    type: str
    probability: float
    is_present: bool
    threshold_used: float

    def to_json(self) -> dict:
        return {
            'type': self.type,
            'probability': self.probability,
            'is_present': self.is_present,
            'threshold_used': self.threshold_used,
        }


@dataclass
class AnalysisResult:
    length: float
    width: float
    depth: float
    tissue_findings: list
    tissue_type: str
    infection: str
    ischaemia: str
    risk_badge: str
    healing_progress: float
    is_from_model: bool
    is_calibrated: bool
    area_cm2: float
    # What gave the centimetres their units, and how square the camera was.
    # Both qualify the measurement and neither can be recovered from it later:
    # without a ring the size is scaled by an assumption, and above 40 degrees
    # of tilt measured error triples.
    pixels_per_cm: Optional[float] = None
    tilt_deg: Optional[float] = None
    overlay_jpeg_b64: Optional[str] = None

    def to_json(self) -> dict:
        d = asdict(self)
        d['tissue_findings'] = [f.to_json() for f in self.tissue_findings]
        # `area` is the canonical key the app reads; `area_cm2` is kept for
        # backward compatibility with any existing consumer.
        d['area'] = self.area_cm2
        return d


class WoundAnalyzer:
    """Holds the four interpreters. Build once and reuse — loading the CLIP
    backbone costs seconds and about 200 MB of resident memory."""

    def __init__(self, models_dir: str):
        # LiteRT first, TensorFlow second. They expose the same Interpreter
        # API, but LiteRT holds 629 MB resident against TensorFlow's ~960 MB
        # for these same four models — measured — and installs in a fraction of
        # the space. Accepting either matters: the deployment script installs
        # the light one, and hardcoding the heavy one meant the sidecar died on
        # startup with ModuleNotFoundError on a correctly-provisioned server.
        try:
            from ai_edge_litert.interpreter import Interpreter
        except ImportError:  # pragma: no cover - depends on what is installed
            from tensorflow.lite import Interpreter

        def load(name):
            interp = Interpreter(
                model_path=f'{models_dir}/{name}',
                # One thread per interpreter. The web server already runs
                # requests concurrently, and letting each inference fan out
                # across every core makes latency worse under load, not better.
                num_threads=1,
            )
            interp.allocate_tensors()
            return interp

        self.seg = load('model1_wound_fp16.tflite')
        self.clip = load('clip_backbone_fp16.tflite')
        self.tissue = load('tissue_head.tflite')
        self.infection = load('infection_ischaemia_head.tflite')

        self.seg_size = self.seg.get_input_details()[0]['shape'][1]

    # -- plumbing ----------------------------------------------------------

    @staticmethod
    def _run(interp, x):
        inp = interp.get_input_details()[0]
        out = interp.get_output_details()[0]
        interp.set_tensor(inp['index'], x)
        interp.invoke()
        return interp.get_tensor(out['index'])

    # -- model 1: segmentation --------------------------------------------

    def _probs(self, resized: np.ndarray, flip: bool) -> np.ndarray:
        x = resized[:, ::-1, :] if flip else resized
        x = x[None, ...].astype(np.float32)
        y = self._run(self.seg, x)[0, :, :, 0]
        return y[:, ::-1] if flip else y

    def _segment(self, image: np.ndarray, pixels_per_cm: Optional[float]):
        n = self.seg_size
        orig_h, orig_w = image.shape[:2]
        ppc = pixels_per_cm or (max(orig_w, orig_h) / ASSUMED_FRAME_CM)

        # INTER_NEAREST, not the linear default anyone would reach for first:
        # `img.copyResize` in package:image defaults to Interpolation.nearest,
        # and the device does not override it. Using a smoother filter here
        # produced a mask ~7.5% larger in area than the device's for the same
        # photo — invisible in the length and width, which are set by the shape
        # of the blob, but plainly visible in the area, which counts pixels.
        resized = cv2.resize(image, (n, n), interpolation=cv2.INTER_NEAREST)
        resized = resized.astype(np.float32) / 255.0

        probs = self._probs(resized, flip=False)
        if USE_FLIP_TTA:
            probs = (probs + self._probs(resized, flip=True)) / 2.0

        mask = probs >= MASK_THRESHOLD
        if not mask.any():
            # An under-confident model still has a peak; take half of it rather
            # than reporting no wound.
            peak = float(probs.max())
            if peak <= 0:
                return 0.0, 0.0, 0.0, None
            mask = probs > 0.5 * peak

        # Open then close with a 5x5 square, exactly as on the device.
        k = np.ones((5, 5), np.uint8)
        m = mask.astype(np.uint8)
        m = cv2.morphologyEx(m, cv2.MORPH_OPEN, k)
        m = cv2.morphologyEx(m, cv2.MORPH_CLOSE, k)

        # Largest connected region only. 8-connectivity matches the Dart flood
        # fill, which walks all eight neighbours.
        count, labels, stats, _ = cv2.connectedComponentsWithStats(m, connectivity=8)
        if count <= 1:
            return 0.0, 0.0, 0.0, None

        # Refuse any region that is printed ink on a white card. Without this the
        # segmenter measures the calibration label itself and returns its 1.5 cm
        # diameter as the wound size — a wrong number that once agreed with the
        # clinician exactly, which is the most dangerous kind because nothing
        # flags it. The device applies the same rule; the two must not disagree.
        keep = [i for i in range(1, count)
                if stats[i, cv2.CC_STAT_AREA] >= 10
                and not is_printed_label(image, (labels == i))]
        if not keep:
            return 0.0, 0.0, 0.0, None
        largest = max(keep, key=lambda i: stats[i, cv2.CC_STAT_AREA])
        comp = labels == largest

        sx, sy = orig_w / n, orig_h / n
        ys, xs = np.nonzero(comp)
        px = xs * sx
        py = ys * sy

        major, minor = _pca_extent(px, py)
        area_px = float(comp.sum()) * sx * sy

        return major / ppc, minor / ppc, area_px / (ppc * ppc), comp

    # -- model 2 and 3: shared CLIP backbone ------------------------------

    def _embedding(self, image: np.ndarray) -> np.ndarray:
        return self._run(self.clip, _clip_preprocess(image)[None, ...])

    def _tissue_probs(self, emb) -> dict:
        p = self._run(self.tissue, emb)[0]
        return {c: float(p[i]) for i, c in enumerate(TISSUE_CLASSES)}

    def _infection_status(self, emb):
        p = self._run(self.infection, emb)[0]  # [none, infection, ischaemia, both]
        p_inf = float(p[1]) + float(p[3])
        p_isc = float(p[2]) + float(p[3])

        has_inf = p_inf >= INFECTION_THRESHOLD
        has_isc = p_isc >= ISCHAEMIA_THRESHOLD

        if has_inf and has_isc:
            badge = 'High Risk'
        elif has_inf:
            badge = 'Infection Detected'
        elif has_isc:
            badge = 'Impaired Blood Flow'
        else:
            badge = 'Normal'

        return (
            'Present' if has_inf else 'Not Present',
            'Impaired' if has_isc else 'Adequate',
            badge,
        )

    # -- entry point -------------------------------------------------------

    def analyse(
        self,
        image_bytes: bytes,
        pixels_per_cm: Optional[float] = None,
        manual_depth_cm: Optional[float] = None,
    ) -> AnalysisResult:
        image = _decode(image_bytes)

        # Scale from the printed ring, not from an assumption about how far the
        # phone was held — the assumption is why a real 1.4 cm wound came back as
        # 0.9 cm. A client-supplied value still wins, since a phone that already
        # found the ring has no reason to have it found twice.
        ring = None
        if pixels_per_cm is None:
            try:
                ring = detect_ring(image)
            except Exception:  # noqa: BLE001 - a detector fault must not cost
                ring = None    # the analysis; uncalibrated and flagged is fine
            if ring is not None:
                pixels_per_cm = ring.pixels_per_cm

        length, width, area, mask = self._segment(image, pixels_per_cm)

        emb = self._embedding(image)
        findings = _build_tissue_findings(self._tissue_probs(emb))
        infection, ischaemia, badge = self._infection_status(emb)

        return AnalysisResult(
            length=length,
            width=width,
            # Depth cannot come from a photo. It is the clinician's probe
            # measurement or it is absent; it is never estimated.
            depth=manual_depth_cm or 0.0,
            tissue_findings=findings,
            # Derived, and kept in the payload so a client older than this
            # server still gets a headline it understands.
            tissue_type=_primary_tissue_type(findings),
            infection=infection,
            ischaemia=ischaemia,
            risk_badge=badge,
            healing_progress=_healing_progress(area),
            is_from_model=True,
            is_calibrated=pixels_per_cm is not None,
            area_cm2=area,
            pixels_per_cm=pixels_per_cm,
            # The mask drawn over the photograph, returned to the client rather
            # than stored here: at analysis time no scan record exists yet to
            # attach it to. The app saves it beside the photograph and it syncs
            # by the same path as one rendered on the device, so both modes end
            # up with the same artefact in the same place.
            overlay_jpeg_b64=_overlay_b64(image, mask, ring),
            # How square the camera was. Reported rather than acted on here: the
            # phone refuses a photograph past 40°, but one already taken and
            # uploaded is better measured with a caveat than not at all.
            tilt_deg=ring.tilt_deg if ring is not None else None,
        )


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


def _decode(data: bytes) -> np.ndarray:
    """Decode to RGB, honouring EXIF orientation.

    The device calls `bakeOrientation` before doing anything else, so a photo
    taken in portrait measures the same on both paths. Skipping this would make
    length and width swap places on most phone photos.
    """
    import io

    img = Image.open(io.BytesIO(data))
    img = ImageOps.exif_transpose(img)
    return np.array(img.convert('RGB'))


def _catmull_rom_resize(src: np.ndarray, dst_w: int, dst_h: int) -> np.ndarray:
    """Reproduces `img.copyResize(..., interpolation: Interpolation.cubic)`.

    OpenCV's INTER_CUBIC is not the same filter. Both are called "bicubic", but
    OpenCV uses a = -0.75 while package:image uses the Catmull-Rom form
    (a = -0.5), and on the downscale CLIP needs — 480 px down to 224 — that
    difference is not subtle. It moved the callus probability for a real wound
    from 0.53 on the device to 0.72 here: twenty points, easily enough to carry
    a class across its threshold and describe a wound differently in the two
    modes.

    The edge handling is copied faithfully rather than sensibly. package:image
    substitutes the *centre* pixel whenever a neighbour falls outside the image,
    and its `icp` sample tests the wrong axis, so the row above the first row
    reads as transparent black instead. Both are quirks; both change the pixels
    the model sees; neither can be corrected on this side alone without
    reintroducing the divergence this function exists to remove.
    """
    h, w = src.shape[:2]
    a = src.astype(np.float64)

    step_x, step_y = w / dst_w, h / dst_h
    fx = np.arange(dst_w) * step_x
    fy = np.arange(dst_h) * step_y

    x0 = fx.astype(np.int64)
    y0 = fy.astype(np.int64)
    tx = (fx - x0)[None, :, None]
    ty = (fy - y0)[:, None, None]

    px, nx, ax = x0 - 1, x0 + 1, x0 + 2
    py, ny, ay = y0 - 1, y0 + 1, y0 + 2

    def grid(ys, xs):
        """src[ys, xs] as a (dst_h, dst_w, 3) block, indices clamped so the
        gather is safe; every out-of-range position is overwritten below."""
        return a[np.clip(ys, 0, h - 1)[:, None], np.clip(xs, 0, w - 1)[None, :]]

    centre = grid(y0, x0)
    black = np.zeros_like(centre)

    def pick(ys, xs, use_centre, use_black=None):
        out = np.where(use_centre[..., None], centre, grid(ys, xs))
        if use_black is not None:
            out = np.where(use_black[..., None], black, out)
        return out

    col_px = (px < 0)[None, :]
    col_nx = (nx >= w)[None, :]
    col_ax = (ax >= w)[None, :]
    row_py = (py < 0)[:, None]
    row_ny = (ny >= h)[:, None]
    row_ay = (ay >= h)[:, None]

    def bcast(m):
        return np.broadcast_to(m, (dst_h, dst_w))

    rows = [
        [  # y - 1
            pick(py, px, bcast(col_px | row_py)),
            # The faithful quirk: guarded on the column, so a row above the
            # image yields transparent black rather than the centre pixel.
            pick(py, x0, bcast(col_px), bcast(~col_px & row_py)),
            pick(py, nx, bcast(row_py | col_nx)),
            pick(py, ax, bcast(col_ax | row_py)),
        ],
        [  # y
            pick(y0, px, bcast(col_px)),
            centre,
            pick(y0, nx, bcast(col_nx)),
            pick(y0, ax, bcast(col_ax)),
        ],
        [  # y + 1
            pick(ny, px, bcast(col_px | row_ny)),
            pick(ny, x0, bcast(row_ny)),
            pick(ny, nx, bcast(col_nx | row_ny)),
            pick(ny, ax, bcast(col_ax | row_ny)),
        ],
        [  # y + 2
            pick(ay, px, bcast(col_px | row_ay)),
            pick(ay, x0, bcast(row_ay)),
            pick(ay, nx, bcast(col_nx | row_ay)),
            pick(ay, ax, bcast(col_ax | row_ay)),
        ],
    ]

    def cubic(t, ipp, icp, inp, iap):
        return icp + 0.5 * (
            t * (-ipp + inp)
            + t * t * (2 * ipp - 5 * icp + 4 * inp - iap)
            + t * t * t * (-ipp + 3 * icp - 3 * inp + iap)
        )

    cols = [cubic(tx, *r) for r in rows]
    out = cubic(ty, *cols)

    # package:image stores through an integer channel, which truncates.
    return np.clip(out, 0, 255).astype(np.uint8)


def _clip_preprocess(src: np.ndarray) -> np.ndarray:
    """Resize the shorter side to 224 (cubic), centre-crop, normalise.

    Must match training or accuracy collapses, and must match the device or the
    two modes describe the same wound differently.
    """
    h, w = src.shape[:2]
    scale = 224 / min(w, h)
    nw, nh = round(w * scale), round(h * scale)

    resized = _catmull_rom_resize(src, nw, nh)

    x0 = round((nw - 224) / 2)
    y0 = round((nh - 224) / 2)
    crop = resized[y0:y0 + 224, x0:x0 + 224]

    x = crop.astype(np.float32) / 255.0
    return (x - CLIP_MEAN) / CLIP_STD


def _pca_extent(px: np.ndarray, py: np.ndarray):
    """Extent along the region's principal axes, in original-image pixels.

    The rotated measurement, equivalent to cv2.minAreaRect. An axis-aligned box
    over-measures any wound lying diagonally in the frame, which would read as
    a wound that is not healing.
    """
    if px.size == 0:
        return 0.0, 0.0

    mx, my = px.mean(), py.mean()
    dx, dy = px - mx, py - my

    cxx = float((dx * dx).sum())
    cyy = float((dy * dy).sum())
    cxy = float((dx * dy).sum())

    theta = 0.5 * math.atan2(2 * cxy, cxx - cyy)
    ct, st = math.cos(theta), math.sin(theta)

    a = dx * ct + dy * st
    b = -dx * st + dy * ct

    return float(a.max() - a.min()), float(b.max() - b.min())


def _build_tissue_findings(probs: dict) -> list:
    """One finding per class: probability, whether it cleared its own tuned
    threshold, and the threshold used."""
    return [
        TissueFinding(
            type=name,
            probability=float(probs.get(name, 0.0)),
            is_present=float(probs.get(name, 0.0)) >= TISSUE_THRESHOLDS.get(name, 0.5),
            threshold_used=TISSUE_THRESHOLDS.get(name, 0.5),
        )
        for name in TISSUE_CLASSES
    ]


def _primary_tissue_type(findings: list) -> str:
    """The single label, for the places that can show only one.

    The most clinically serious class present, not the highest scoring one.
    Ranking by probability let a hundredth of a point decide between necrosis
    and callus, and the phone and the server disagreed on the same photograph.
    Severity cannot move that way.

    With nothing present it falls back to the most probable class, so the field
    is never blank. Kept identical to TissueFindings.primaryType in the app.
    """
    if not findings:
        return 'Unknown'

    candidates = [f for f in findings if f.is_present] or list(findings)

    def rank(f):
        order = (TISSUE_SEVERITY.index(f.type)
                 if f.type in TISSUE_SEVERITY else len(TISSUE_SEVERITY))
        return (order, -f.probability)

    best = min(candidates, key=rank)
    return best.type[0].upper() + best.type[1:]


def _healing_progress(area_cm2: float) -> float:
    """Kept identical to the device, including its known crudeness.

    The device notes that a real figure needs the patient's own baseline area
    from history, and that this coarse reference stands in until that exists.
    Improving it here alone would make the two modes disagree, so it is
    deliberately copied rather than fixed — when it is fixed, both change.
    """
    baseline_area = 100.0
    return min(max((baseline_area - area_cm2) / baseline_area * 100.0, 0.0), 100.0)


def _overlay_b64(image: np.ndarray, mask, ring) -> Optional[str]:
    """The photograph with the measured region drawn on it, as base64 JPEG.

    A size with nothing behind it cannot be checked. Before the model was
    retrained the segmenter measured the printed calibration label instead of the
    wound in 16 of 42 small-label photographs, once agreeing with a clinician's
    own figure by coincidence — and nobody could have seen it. Every wrong
    conclusion in this project was caught by looking at the mask, not the number.

    Returned inline rather than written to disk: at analysis time there is no
    scan record yet to attach a file to, and the client already has a path that
    stores and syncs an overlay. Encoded as JPEG at 900 px, which is a few
    hundred kilobytes rather than the several megabytes a full-resolution PNG
    would add to every response on a phone connection.
    """
    if mask is None or not mask.any():
        return None

    try:
        h, w = image.shape[:2]
        scale = 900 / max(h, w) if max(h, w) > 900 else 1.0
        out = cv2.cvtColor(image, cv2.COLOR_RGB2BGR)
        if scale < 1.0:
            out = cv2.resize(out, (int(w * scale), int(h * scale)),
                             interpolation=cv2.INTER_AREA)

        big = cv2.resize(mask.astype(np.uint8), (out.shape[1], out.shape[0]),
                         interpolation=cv2.INTER_NEAREST).astype(bool)
        out[big] = (0.55 * out[big] + 0.45 * np.array([40, 40, 220])).astype(np.uint8)
        cnts, _ = cv2.findContours(big.astype(np.uint8), cv2.RETR_EXTERNAL,
                                   cv2.CHAIN_APPROX_SIMPLE)
        cv2.drawContours(out, cnts, -1, (60, 60, 255), max(2, out.shape[1] // 300))

        # The ring too: a measurement shown without the thing that gave it its
        # units cannot be checked either.
        if ring is not None:
            cv2.ellipse(out,
                        (int(ring.cx * scale), int(ring.cy * scale)),
                        (int(ring.major_px * scale / 2), int(ring.minor_px * scale / 2)),
                        0, 0, 360, (120, 220, 40), max(2, out.shape[1] // 400))

        ok, buf = cv2.imencode('.jpg', out, [int(cv2.IMWRITE_JPEG_QUALITY), 85])
        if not ok:
            return None
        return base64.b64encode(buf.tobytes()).decode('ascii')
    except Exception:  # noqa: BLE001
        # An overlay explains a result; it is not part of one. Losing it must
        # never cost the measurement.
        return None
