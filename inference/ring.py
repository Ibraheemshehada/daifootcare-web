"""The printed calibration ring, and the label guard that goes with it.

Both are ports of code validated on 239 real clinic photographs
(`clinical_validation/scripts/ring_detect.py` and `wound_mask.py`) and already
running on the phone. They are here because the server analyses photographs too,
and until now it did neither: an online scan was scaled by an assumed frame width
and could measure the printed sticker instead of the wound.

Keeping the two implementations in step matters more than either one alone. A
patient can switch between online and offline mode between visits, and the study
compares the two — so a wound must not change size because of which pipeline
happened to run.
"""
from __future__ import annotations

from dataclasses import dataclass
from typing import Optional

import cv2
import numpy as np

RING_CM_STANDARD = 2.0   # cyan label
RING_CM_SMALL = 1.5      # magenta label, used on toes


@dataclass
class Ring:
    cx: float
    cy: float
    major_px: float
    minor_px: float
    is_small: bool

    @property
    def ring_cm(self) -> float:
        return RING_CM_SMALL if self.is_small else RING_CM_STANDARD

    @property
    def pixels_per_cm(self) -> float:
        return self.major_px / self.ring_cm

    @property
    def tilt_deg(self) -> float:
        # The ring's major axis is unforeshortened, so SCALE survives tilt. The
        # wound does not: an extent along the tilt is compressed by cos θ, which
        # is -23% at 40°. That is why the number is reported rather than used.
        return float(np.degrees(np.arccos(min(1.0, self.minor_px / self.major_px))))


def detect_ring(rgb: np.ndarray) -> Optional[Ring]:
    """Find the printed annulus, or None. Every filter earned its place.

    Ported unchanged from the detector measured on 239 clinic photographs; the
    comments name the failure each one prevents.
    """
    bgr = cv2.cvtColor(rgb, cv2.COLOR_RGB2BGR)
    hsv = cv2.cvtColor(bgr, cv2.COLOR_BGR2HSV)

    # Hue is the size key: cyan is the 20 mm label, magenta the 15 mm one. The
    # first version looked for cyan only and silently failed on every photograph
    # of a toe wound, where the clinician had correctly reached for the small
    # magenta label.
    cyan = cv2.inRange(hsv, np.array([80, 60, 40]), np.array([115, 255, 255]))
    # Narrow and highly saturated on purpose. A generous magenta range swallowed
    # inflamed skin: one photograph produced 525,000 "magenta" pixels, a third of
    # the frame, which merged into blobs 977 px across and buried the real ring.
    mag = cv2.inRange(hsv, np.array([145, 110, 50]), np.array([175, 255, 255]))
    mask = cv2.bitwise_or(cyan, mag)

    # Open BEFORE close, and close gently. A 9x9 close sealed the small ring's
    # hole whenever it sat far enough away, turning the annulus into a disc that
    # the hole test then correctly rejected — the detector destroying the very
    # feature it looks for.
    mask = cv2.morphologyEx(mask, cv2.MORPH_OPEN, np.ones((5, 5), np.uint8))
    mask = cv2.morphologyEx(mask, cv2.MORPH_CLOSE, np.ones((3, 3), np.uint8))

    cnts, _ = cv2.findContours(mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    if not cnts:
        return None

    h, w = mask.shape
    best = None
    for c in cnts:
        a = cv2.contourArea(c)
        if a < 400 or len(c) < 5:
            continue
        (cx, cy), (d1, d2), _ang = cv2.fitEllipse(c)
        major, minor = max(d1, d2), min(d1, d2)
        if major <= 0 or minor / major < 0.5:
            continue

        ell_area = np.pi * (major / 2) * (minor / 2)
        if not (0.70 < a / ell_area < 1.25):
            continue

        # THE decisive test: the mark is an ANNULUS. A blue surgical drape is
        # solid, and without this the detector once locked onto one and reported
        # a 1102 px "ring" — a whole foot measured 2 cm wide.
        ix, iy = int(round(cx)), int(round(cy))
        if not (0 <= ix < w and 0 <= iy < h):
            continue
        r_in = int(max(2, minor * 0.15))
        patch = mask[max(0, iy - r_in):iy + r_in + 1, max(0, ix - r_in):ix + r_in + 1]
        if patch.size == 0 or patch.mean() > 60:
            continue

        if not (0.02 < major / max(h, w) < 0.45):
            continue

        # Rank by roundness, not size: the largest blob is often skin that
        # survived the filters by luck, while the calibration mark is the
        # roundest thing in frame with a clean hole.
        score = (minor / major) * min(1.0, a / 2000.0)
        if best is None or score > best[0]:
            best = (score, cx, cy, major, minor)

    if best is None:
        return None

    _score, cx, cy, major, minor = best

    ring_only = np.zeros(mask.shape, np.uint8)
    cv2.ellipse(ring_only, (int(cx), int(cy)),
                (int(major / 2), int(minor / 2)), 0, 0, 360, 255, -1)
    is_small = int(cv2.bitwise_and(mag, ring_only).sum()) > \
        int(cv2.bitwise_and(cyan, ring_only).sum())

    return Ring(cx=float(cx), cy=float(cy), major_px=float(major),
                minor_px=float(minor), is_small=bool(is_small))


def is_printed_label(rgb: np.ndarray, component: np.ndarray) -> bool:
    """Is this mask component printed ink on a white card rather than tissue?

    The segmenter reads the small magenta ring as granulation: before the model
    was retrained it measured the label instead of the wound in 16 of 42
    small-label photographs, returning the ring's own 1.5 cm — which once
    matched a clinician's figure exactly, by coincidence.

    The test is deliberately NOT "is it magenta". Vivid granulation occupies the
    same hue band, and a colour rule threw away real wounds. It asks what
    SURROUNDS the blob: printed ink sits on a white card, tissue sits in skin. On
    153 photographs the labels that were measured scored 0.59-0.87 white
    surround and the real wounds at most 0.25.
    """
    if component.sum() == 0:
        return False

    small = cv2.resize(rgb, (component.shape[1], component.shape[0]),
                       interpolation=cv2.INTER_NEAREST)
    hsv = cv2.cvtColor(cv2.cvtColor(small, cv2.COLOR_RGB2BGR), cv2.COLOR_BGR2HSV)
    paper = (hsv[..., 1] < 50) & (hsv[..., 2] > 170)

    k = np.ones((5, 5), np.uint8)
    comp = component.astype(np.uint8)
    collar = (cv2.dilate(comp, k, iterations=2) > 0) & (comp == 0)
    if collar.sum() == 0:
        # The region fills the frame; refusing it would delete a wound
        # photographed very close up, so an unanswerable question is a "no".
        return False
    return float(paper[collar].mean()) >= 0.40
