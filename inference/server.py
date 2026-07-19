"""The inference sidecar.

Laravel handles the API, the auth and the records; it cannot handle this. PHP
has no TFLite runtime, so the models need a process of their own. This is that
process, and it is deliberately small: it takes an image, returns an analysis,
and knows nothing about patients, tokens or the database.

It must not be exposed to the internet. Laravel is the only client, over
loopback, and it is Laravel that decides whether the caller is allowed to ask.
Binding to 127.0.0.1 is what enforces that, so leave it alone.

    uvicorn server:app --host 127.0.0.1 --port 8500
"""

from __future__ import annotations

import logging
import os
import time
from typing import Optional

from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from fastapi.responses import JSONResponse

from pipeline import WoundAnalyzer

MODELS_DIR = os.environ.get(
    'DFC_MODELS_DIR',
    os.path.join(os.path.dirname(__file__), '..', 'storage', 'app', 'models'),
)

# A wound photo from a phone is a few MB. Anything far larger is a mistake or an
# attempt to exhaust memory, and decoding it first would be the expensive way to
# find out.
MAX_IMAGE_BYTES = 25 * 1024 * 1024

log = logging.getLogger('dfc.inference')
logging.basicConfig(level=logging.INFO, format='%(asctime)s %(levelname)s %(message)s')

app = FastAPI(title='DiaFootCare inference', docs_url=None, redoc_url=None)

_analyzer: Optional[WoundAnalyzer] = None


@app.on_event('startup')
def _load():
    """Load the models once, at startup.

    Deliberately eager: the backbone takes seconds to load, and doing it lazily
    would hand that delay to whichever patient happened to be first after a
    restart. Failing here instead means a bad deployment is visible immediately
    rather than on someone's first scan.
    """
    global _analyzer
    t0 = time.time()
    _analyzer = WoundAnalyzer(os.path.abspath(MODELS_DIR))
    log.info('models loaded in %.1fs from %s', time.time() - t0, MODELS_DIR)


@app.get('/health')
def health():
    """Cheap enough for a load balancer, honest enough to be worth checking."""
    return {
        'status': 'ok' if _analyzer is not None else 'loading',
        'models_dir': os.path.abspath(MODELS_DIR),
    }


@app.post('/analyse')
async def analyse(
    image: UploadFile = File(...),
    pixels_per_cm: Optional[float] = Form(None),
    manual_depth_cm: Optional[float] = Form(None),
):
    if _analyzer is None:
        raise HTTPException(503, 'Models are still loading.')

    data = await image.read()
    if not data:
        raise HTTPException(400, 'Empty image.')
    if len(data) > MAX_IMAGE_BYTES:
        raise HTTPException(413, 'Image too large.')

    # A calibration of zero or a negative one is not a scale; treating it as
    # one would divide by it and report an infinite wound.
    if pixels_per_cm is not None and pixels_per_cm <= 0:
        raise HTTPException(422, 'pixels_per_cm must be greater than zero.')
    if manual_depth_cm is not None and manual_depth_cm < 0:
        raise HTTPException(422, 'manual_depth_cm cannot be negative.')

    t0 = time.time()
    try:
        result = _analyzer.analyse(
            data,
            pixels_per_cm=pixels_per_cm,
            manual_depth_cm=manual_depth_cm,
        )
    except Exception:
        # The image itself is never logged — it is a photograph of a patient.
        log.exception('analysis failed (%d bytes)', len(data))
        raise HTTPException(422, 'That image could not be analysed.')

    took = time.time() - t0
    log.info('analysed %d bytes in %.2fs calibrated=%s',
             len(data), took, result.is_calibrated)

    return JSONResponse({**result.to_json(), 'took_ms': round(took * 1000)})
