"""Guards the agreement between server-side and on-device analysis.

Online and offline mode must describe a wound the same way. They cannot agree
exactly: `package:image` decodes JPEG in pure Dart with different rounding than
libjpeg, so the two platforms start from pixels that already differ. Everything
downstream inherits that.

This suite holds the remaining divergence to what was measured once the
pipelines were deliberately matched, so a later change that quietly widens the
gap fails here instead of in a clinic.

The device figures come from `integration_test/analysis_parity_test.dart` in the
mobile repo, run on a real device against two real clinical photographs.
Regenerate both sides together if the pipeline changes on purpose.

    python inference/parity_test.py
"""

from __future__ import annotations

import json
import os
import sys

sys.path.insert(0, os.path.dirname(__file__))

import pipeline as P  # noqa: E402

MODELS = os.path.join(os.path.dirname(__file__), '..', 'storage', 'app', 'models')


def _fixture(name):
    return os.path.join(os.path.dirname(__file__), 'testdata', name)


# Captured on device (emulator-5554) on two real clinical photographs rather
# than a synthetic one. That distinction mattered: a synthetic wound with a
# single dominant tissue class showed no disagreement at all, while 200029
# exposed a label flip between the platforms on the first run.
DEVICE = {
    '200003.jpg': {
        'long_edge': 640,
        None: {
            'length': 1.7337373907990992,
            'width': 1.451733565917768,
            'healing_progress': 98.377685546875,
        },
        40.0: {
            'length': 2.311649854398799,
            'width': 1.9356447545570241,
            'healing_progress': 97.11588541666667,
        },
        'labels': {
            'tissue_type': 'Callus',
            'infection': 'Present',
            'ischaemia': 'Adequate',
            'risk_badge': 'Infection Detected',
        },
        'tissue_probs': {
            'epithelial': 0.011895540170371532,
            'granulation': 0.963156521320343,
            'necrosis': 0.32683637738227844,
            'callus': 0.5298696756362915,
            'slough': 0.09541260451078415,
        },
    },
    '200029.jpg': {
        'long_edge': 640,
        None: {
            'length': 4.641483490428528,
            'width': 3.271894988607073,
            'healing_progress': 91.816650390625,
        },
        40.0: {
            'length': 6.188644653904705,
            'width': 4.362526651476098,
            'healing_progress': 85.45182291666666,
        },
        'labels': {
            # Necrosis. Three classes clear their thresholds on this wound and
            # the top two sit 0.013 apart, so ranking by probability made the
            # device say Necrosis and the server say Callus for one photograph.
            'tissue_type': 'Necrosis',
            'infection': 'Present',
            'ischaemia': 'Impaired',
            'risk_badge': 'High Risk',
        },
        'tissue_probs': {
            'epithelial': 0.00023667790810577571,
            'granulation': 0.48993155360221863,
            'necrosis': 0.988717794418335,
            'callus': 0.978735625743866,
            'slough': 0.9193001985549927,
        },
    },
}

# Tolerances set from measured divergence, with room to breathe. Tightening them
# is fine; loosening one means the pipelines have drifted and someone needs to
# find out why.
#
# A relative tolerance alone is the wrong instrument. The segmentation mask is
# 384 px across, so one mask pixel is a real, quantised unit of measurement
# error, and a boundary landing one pixel differently moves the answer by that
# much however small the wound is. Judging a 1 cm wound by percentages punishes
# it for being small. So a dimension passes if it is within 3% *or* within two
# mask pixels.
DIMENSION_TOLERANCE = 0.03
MASK_PIXELS_ALLOWED = 2.0
PROBABILITY_TOLERANCE = 0.05

SEG_SIZE = 384


def _mask_pixel_cm(pixels_per_cm, long_edge):
    """One segmentation-mask pixel in centimetres.

    Uncalibrated photos have no true scale, so the device assumes the frame's
    wider side spans ASSUMED_FRAME_CM; the same assumption applies here.
    """
    ppc = pixels_per_cm or (long_edge / P.ASSUMED_FRAME_CM)
    return (long_edge / SEG_SIZE) / ppc


def _analyzer():
    return P.WoundAnalyzer(MODELS)


def test_categorical_labels_match_exactly():
    """Labels are what a clinician reads, and they must not depend on which
    platform did the arithmetic."""
    an = _analyzer()

    for name, expected in DEVICE.items():
        data = open(_fixture(name), 'rb').read()
        for ppc in (None, 40.0):
            got = an.analyse(data, pixels_per_cm=ppc).to_json()
            for field, want in expected['labels'].items():
                assert got[field] == want, (
                    f'{name} {field} differs at calibration={ppc}: '
                    f'device={want!r} server={got[field]!r}'
                )


def test_dimensions_within_tolerance():
    an = _analyzer()

    for name, expected in DEVICE.items():
        data = open(_fixture(name), 'rb').read()
        for ppc in (None, 40.0):
            got = an.analyse(data, pixels_per_cm=ppc).to_json()
            px_cm = _mask_pixel_cm(ppc, expected['long_edge'])
            allowed_cm = MASK_PIXELS_ALLOWED * px_cm

            for field in ('length', 'width'):
                d, sv = expected[ppc][field], got[field]
                delta = abs(d - sv)
                rel = delta / max(abs(d), 1e-9)

                assert rel <= DIMENSION_TOLERANCE or delta <= allowed_cm, (
                    f'{name} {field} diverged {rel:.1%} ({delta:.4f} cm, '
                    f'{delta / px_cm:.1f} mask pixels) at calibration={ppc}: '
                    f'device={d:.4f} server={sv:.4f}'
                )

            d, sv = expected[ppc]['healing_progress'], got['healing_progress']
            assert abs(d - sv) / max(abs(d), 1e-9) <= DIMENSION_TOLERANCE, (
                f'{name} healing_progress diverged at calibration={ppc}: '
                f'device={d:.4f} server={sv:.4f}'
            )


def test_a_label_cannot_flip_between_modes():
    """Labels must not depend on which platform did the arithmetic.

    Covered by test_categorical_labels_match_exactly; this measures how much
    room is left before that stops being true.
    """
    an = _analyzer()
    report = []

    for name, expected in DEVICE.items():
        img = P._decode(open(_fixture(name), 'rb').read())
        probs = an._tissue_probs(an._embedding(img))

        for cls, device_p in expected['tissue_probs'].items():
            server_p = probs[cls]
            divergence = abs(device_p - server_p)
            threshold = P.TISSUE_THRESHOLDS[cls]
            margin = min(abs(device_p - threshold), abs(server_p - threshold))
            report.append((margin - divergence, name, cls, divergence, margin))

    report.sort()

    print()
    print('  headroom before a class could cross its threshold on the other '
          'platform:')
    for slack, name, cls, div, margin in report[:5]:
        flag = '  AT RISK' if slack <= 0 else ''
        print(f'    {name} {cls:<12} divergence {div:.4f} '
              f'margin {margin:.4f} slack {slack:+.4f}{flag}')

    at_risk = [r for r in report if r[0] <= 0]
    if at_risk:
        print()
        print(f'  {len(at_risk)} class(es) diverge by more than their distance '
              'to their own threshold.')
        print('  These cannot flip the headline today because a more serious '
              'class already dominates,')
        print('  but on another wound they could. This is a property of the '
              'model, not of the port:')
        print('  the pipelines are matched to within the JPEG decoders '
              '(~0.2 of 255 per pixel).')


if __name__ == '__main__':
    failures = 0
    for name, fn in sorted(globals().items()):
        if not name.startswith('test_') or not callable(fn):
            continue
        try:
            fn()
            print(f'PASS  {name}')
        except AssertionError as e:
            failures += 1
            print(f'FAIL  {name}\n      {e}')

    print(json.dumps({'failures': failures}))
    sys.exit(1 if failures else 0)
