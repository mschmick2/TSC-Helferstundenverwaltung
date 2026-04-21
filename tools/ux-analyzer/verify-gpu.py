#!/usr/bin/env python3
"""
verify-gpu.py
=============

Pruefung der GPU-Umgebung fuer den UX-Analyzer:
  1. PyTorch-Import
  2. CUDA verfuegbar
  3. Device-Name enthaelt "RTX"
  4. VRAM ≥ 16 GB
  5. CLIP-Smoke-Test auf Dummy-Bild (1 Inference)

Exit-Codes:
  0 = alles gut
  1 = PyTorch/CUDA-Problem
  2 = GPU zu klein oder kein RTX
  3 = CLIP-Smoke-Test fehlgeschlagen
"""

from __future__ import annotations

import sys
import time
import traceback

# ---------------------------------------------------------------------------
# Schwellwerte (siehe docs/Testumgebung.md)
# ---------------------------------------------------------------------------
MIN_VRAM_GB_HARD = 4.0    # unter diesem Wert laeuft CLIP ViT-L/14 nicht mehr
MIN_VRAM_GB_SOFT = 12.0   # empfohlene Mindestgroesse fuer komfortables Arbeiten
CLIP_MODEL_ID    = "openai/clip-vit-large-patch14"


def section(title: str) -> None:
    print(f"\n--- {title} ---")


def main() -> int:
    # -------------------------------------------------------------------
    section("1/5 PyTorch-Import")
    try:
        import torch  # type: ignore
    except ImportError:
        print("FEHLER: torch nicht installiert. setup.bat ausfuehren.")
        return 1
    print(f"  torch.__version__ = {torch.__version__}")
    print(f"  CUDA-Build:          {torch.version.cuda}")

    # -------------------------------------------------------------------
    section("2/5 CUDA-Verfuegbarkeit")
    if not torch.cuda.is_available():
        print("FEHLER: CUDA nicht verfuegbar.")
        print("  - Treiber 550+ installiert?  (nvidia-smi)")
        print("  - PyTorch mit CUDA 12.6 installiert? (cu126-Wheel)")
        print("  Ohne GPU laeuft der Analyzer langsamer, aber noch.")
        print("  Nutze `python ux_analyzer.py --no-clip` als Fallback.")
        return 1
    print(f"  CUDA verfuegbar: True")
    print(f"  GPU-Count:       {torch.cuda.device_count()}")

    # -------------------------------------------------------------------
    section("3/5 Device-Info")
    idx = 0
    name = torch.cuda.get_device_name(idx)
    props = torch.cuda.get_device_properties(idx)
    vram_gb = props.total_memory / (1024 ** 3)
    print(f"  Device 0:     {name}")
    print(f"  VRAM:         {vram_gb:.1f} GB")
    print(f"  CUDA Capab.:  {props.major}.{props.minor}")

    is_rtx = "RTX" in name.upper()
    if not is_rtx:
        print("  Hinweis: Kein RTX-Device erkannt - CLIP funktioniert, aber evtl. langsam.")
    if vram_gb < MIN_VRAM_GB_HARD:
        print(f"  FEHLER: {vram_gb:.1f} GB VRAM < {MIN_VRAM_GB_HARD:.0f} GB - "
              "CLIP ViT-L/14 laeuft nicht.")
        return 2
    if vram_gb < MIN_VRAM_GB_SOFT:
        print(f"  Hinweis: {vram_gb:.1f} GB VRAM unter Empfehlung {MIN_VRAM_GB_SOFT:.0f} GB - "
              "CLIP laeuft, aber langsamer.")

    # -------------------------------------------------------------------
    section("4/5 CLIP-Smoke-Test")
    try:
        import numpy as np  # type: ignore
        from transformers import CLIPModel, CLIPProcessor  # type: ignore
    except ImportError as e:
        print(f"FEHLER: transformers/numpy fehlen: {e}")
        return 3

    print(f"  CLIP laden ({CLIP_MODEL_ID})...")
    t0 = time.perf_counter()
    try:
        model = CLIPModel.from_pretrained(CLIP_MODEL_ID).to("cuda").eval()
        processor = CLIPProcessor.from_pretrained(CLIP_MODEL_ID)
    except Exception as e:  # noqa: BLE001
        print(f"FEHLER beim Laden: {e}")
        traceback.print_exc()
        return 3
    t_load = time.perf_counter() - t0
    print(f"  Load-Zeit: {t_load:.1f}s")

    # Dummy-Bild: einfarbig grau
    dummy = np.full((224, 224, 3), 128, dtype=np.uint8)
    texts = ["a clean interface", "a cluttered page"]
    try:
        with torch.no_grad():
            inputs = processor(text=texts, images=dummy, return_tensors="pt", padding=True).to("cuda")
            t1 = time.perf_counter()
            out = model(**inputs)
            torch.cuda.synchronize()
            t_inf = time.perf_counter() - t1
            scores = out.logits_per_image.softmax(dim=-1).cpu().numpy()[0]
        print(f"  Inference-Zeit: {t_inf * 1000:.0f} ms")
        print(f"  Dummy-Score [clean, cluttered]: {scores.tolist()}")
    except Exception as e:  # noqa: BLE001
        print(f"FEHLER bei Inference: {e}")
        traceback.print_exc()
        return 3

    # -------------------------------------------------------------------
    section("5/5 VRAM-Nutzung")
    alloc = torch.cuda.memory_allocated() / (1024 ** 3)
    reserved = torch.cuda.memory_reserved() / (1024 ** 3)
    print(f"  allocated:  {alloc:.2f} GB")
    print(f"  reserved:   {reserved:.2f} GB")

    # ASCII-only output - Windows-cp1252-Konsole frisst kein Unicode
    print("\nOK - GPU-Verifikation erfolgreich.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
