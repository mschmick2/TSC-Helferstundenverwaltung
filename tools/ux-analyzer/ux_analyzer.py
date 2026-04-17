#!/usr/bin/env python3
"""
VAES UX-Analyzer
================

Automatisierte, wiederholbare UX-Qualitaetsbewertung via Computer Vision
und (optional) CLIP-Deep-Learning.

Analysedimensionen (Standardgewichte):
  contrast        15%   Text-Kontrast (WCAG-Heuristik)
  color_palette   10%   K-means-Farben, Saettigung, Hues
  layout          15%   Quadrantengewicht, Weissraum, Alignment
  complexity      10%   Sobel-Gradienten, Hotspots
  viewport        15%   Mobile Touch-Targets, Zeilenlaenge
  consistency     10%   Symmetrie, vertikaler Rhythmus
  clip_ux         25%   CLIP ViT-L/14 Semantik-Score (braucht GPU empfehlenswert)

Nutzung:
  python ux_analyzer.py screenshot.png
  python ux_analyzer.py e2e-tests/screenshots/ --batch -o report.json
  python ux_analyzer.py before.png --compare after.png -o diff.json
  python ux_analyzer.py screenshot.png --no-clip --viewport mobile
"""

from __future__ import annotations

import argparse
import json
import math
import os
import sys
from dataclasses import asdict, dataclass, field
from pathlib import Path
from typing import Optional

import numpy as np

try:
    import cv2  # type: ignore
except ImportError:
    sys.stderr.write("FEHLER: opencv-python nicht installiert. setup.bat ausfuehren.\n")
    sys.exit(1)

# CLIP ist optional.
CLIP_AVAILABLE = False
try:
    import torch  # type: ignore
    from transformers import CLIPModel, CLIPProcessor  # type: ignore
    CLIP_AVAILABLE = True
except Exception:  # noqa: BLE001
    CLIP_AVAILABLE = False


# ---------------------------------------------------------------------------
# Datamodel
# ---------------------------------------------------------------------------

@dataclass
class AnalyzerResult:
    analyzer: str
    score: float  # 0.0 .. 1.0
    findings: list[str] = field(default_factory=list)
    metadata: dict = field(default_factory=dict)


@dataclass
class Report:
    file: str
    overall_score: float
    grade: str
    viewport: str
    dimensions: tuple[int, int]
    results: list[AnalyzerResult]


WEIGHTS = {
    "contrast": 0.15,
    "color_palette": 0.10,
    "layout": 0.15,
    "complexity": 0.10,
    "viewport": 0.15,
    "consistency": 0.10,
    "clip_ux": 0.25,
}

CLIP_UX_DIMENSIONS = [
    {
        "name": "visual_quality",
        "positive": ["a clean, modern, professional software interface"],
        "negative": ["a cluttered, messy, amateur web page"],
        "weight": 0.25,
    },
    {
        "name": "readability",
        "positive": ["clear readable text with good contrast"],
        "negative": ["tiny unreadable text with poor contrast"],
        "weight": 0.25,
    },
    {
        "name": "navigation_clarity",
        "positive": ["a page with obvious buttons and intuitive navigation"],
        "negative": ["a page with hidden navigation and confusing layout"],
        "weight": 0.25,
    },
    {
        "name": "visual_hierarchy",
        "positive": ["a page with distinct sections and important content standing out"],
        "negative": ["a flat page with no grouping and no hierarchy"],
        "weight": 0.25,
    },
]


# ---------------------------------------------------------------------------
# Analyzers
# ---------------------------------------------------------------------------

def analyze_contrast(img_bgr: np.ndarray) -> AnalyzerResult:
    """Heuristik: Pruefe WCAG-Kontrast in Text-Kandidaten-Regionen.
    Keine Segmentierung - wir nutzen einen Proxy (Varianz + Kantendichte)."""
    gray = cv2.cvtColor(img_bgr, cv2.COLOR_BGR2GRAY)
    edges = cv2.Canny(gray, 80, 160)
    # Regions-of-Interest: Bloecke mit vielen Kanten (Text-Kandidaten)
    h, w = gray.shape
    grid = 40
    block_h, block_w = max(h // grid, 1), max(w // grid, 1)
    scores: list[float] = []
    findings: list[str] = []
    for y in range(0, h - block_h, block_h):
        for x in range(0, w - block_w, block_w):
            block = gray[y:y + block_h, x:x + block_w]
            edge_block = edges[y:y + block_h, x:x + block_w]
            edge_density = float(edge_block.mean()) / 255.0
            if edge_density < 0.03:
                continue
            # Kontrast = (max - min) / 255
            local = (float(block.max()) - float(block.min())) / 255.0
            scores.append(local)
    if not scores:
        return AnalyzerResult("contrast", 0.5, ["Keine Text-Regionen erkannt"], {})
    mean_contrast = float(np.mean(scores))
    below = sum(1 for s in scores if s < 0.45)
    if below > 0:
        findings.append(
            f"{below} von {len(scores)} Textregionen mit geringem Kontrast (< 0.45)"
        )
    return AnalyzerResult(
        "contrast",
        round(mean_contrast, 3),
        findings,
        {"regions": len(scores), "low_contrast_regions": below},
    )


def analyze_color_palette(img_bgr: np.ndarray, k: int = 6) -> AnalyzerResult:
    """K-means-Clustering; gute Werte bei moderater Farbvielfalt + nicht zu bunt."""
    small = cv2.resize(img_bgr, (160, 160), interpolation=cv2.INTER_AREA)
    data = small.reshape((-1, 3)).astype(np.float32)
    criteria = (cv2.TERM_CRITERIA_EPS + cv2.TERM_CRITERIA_MAX_ITER, 10, 1.0)
    _, _, centers = cv2.kmeans(data, k, None, criteria, 3, cv2.KMEANS_PP_CENTERS)
    centers_hsv = cv2.cvtColor(centers.reshape(1, -1, 3).astype(np.uint8), cv2.COLOR_BGR2HSV)[0]
    hues = centers_hsv[:, 0].astype(int).tolist()
    sats = centers_hsv[:, 1].astype(int).tolist()
    unique_hues = len({h // 20 for h in hues})
    avg_sat = float(np.mean(sats)) / 255.0
    # Score: 3-5 Hues optimal; zu wenig oder zu viel reduziert.
    hue_score = 1.0 - abs(unique_hues - 4) * 0.2
    hue_score = max(min(hue_score, 1.0), 0.0)
    sat_score = 1.0 - abs(avg_sat - 0.35) * 1.5
    sat_score = max(min(sat_score, 1.0), 0.0)
    score = round((hue_score + sat_score) / 2, 3)
    findings: list[str] = []
    if unique_hues < 3:
        findings.append("Farbpalette wirkt monoton (<3 Hue-Bins)")
    if unique_hues > 6:
        findings.append("Farbpalette wirkt ueberladen (>6 Hue-Bins)")
    return AnalyzerResult(
        "color_palette",
        score,
        findings,
        {"unique_hues": unique_hues, "avg_saturation": round(avg_sat, 3)},
    )


def analyze_layout(img_bgr: np.ndarray) -> AnalyzerResult:
    """Quadrantengewicht + Whitespace + Alignment via Sobel."""
    gray = cv2.cvtColor(img_bgr, cv2.COLOR_BGR2GRAY)
    h, w = gray.shape
    # Whitespace: Pixel > 230
    whitespace = float(np.mean(gray > 230))
    # Quadrantengewichte (Varianz)
    q1 = float(gray[:h // 2, :w // 2].var()) / 255.0
    q2 = float(gray[:h // 2, w // 2:].var()) / 255.0
    q3 = float(gray[h // 2:, :w // 2].var()) / 255.0
    q4 = float(gray[h // 2:, w // 2:].var()) / 255.0
    quads = [q1, q2, q3, q4]
    qstd = float(np.std(quads))
    # Alignment: vertikale Kanten (Sobel x)
    sobelx = cv2.Sobel(gray, cv2.CV_64F, 1, 0, ksize=3)
    vertical_edge_ratio = float(np.mean(np.abs(sobelx) > 30))
    score_ws = 1.0 - abs(whitespace - 0.45) * 1.5
    score_ws = max(min(score_ws, 1.0), 0.0)
    score_balance = 1.0 - min(qstd / 2.0, 1.0)
    score_alignment = min(vertical_edge_ratio * 5, 1.0)
    score = round((score_ws + score_balance + score_alignment) / 3, 3)
    findings: list[str] = []
    if whitespace < 0.15:
        findings.append("Sehr wenig Whitespace - UI wirkt dicht")
    if whitespace > 0.75:
        findings.append("Sehr viel Whitespace - Inhalt wirkt duenn")
    if qstd > 1.5:
        findings.append("Unausgewogene Verteilung der Inhaltsdichte ueber Quadranten")
    return AnalyzerResult(
        "layout",
        score,
        findings,
        {
            "whitespace_ratio": round(whitespace, 3),
            "quadrant_std": round(qstd, 3),
            "vertical_edge_ratio": round(vertical_edge_ratio, 3),
        },
    )


def analyze_complexity(img_bgr: np.ndarray) -> AnalyzerResult:
    """Sobel-Gradient-Magnitude als Proxy fuer visuelle Komplexitaet."""
    gray = cv2.cvtColor(img_bgr, cv2.COLOR_BGR2GRAY)
    gx = cv2.Sobel(gray, cv2.CV_64F, 1, 0, ksize=3)
    gy = cv2.Sobel(gray, cv2.CV_64F, 0, 1, ksize=3)
    mag = np.sqrt(gx ** 2 + gy ** 2)
    avg = float(mag.mean())
    score = 1.0 - min(avg / 80.0, 1.0)
    findings: list[str] = []
    if avg > 70:
        findings.append("Sehr hohe visuelle Komplexitaet - evtl. zu viele Elemente")
    return AnalyzerResult(
        "complexity",
        round(score, 3),
        findings,
        {"avg_gradient": round(avg, 2)},
    )


def analyze_viewport(img_bgr: np.ndarray, viewport: str) -> AnalyzerResult:
    """Viewport-spezifische Heuristiken."""
    h, w = img_bgr.shape[:2]
    findings: list[str] = []
    score = 1.0
    if viewport == "mobile":
        # Touch-Targets: finde Buttons via einfache Kontursuche, messe kleinste Bounding-Box-Seite.
        gray = cv2.cvtColor(img_bgr, cv2.COLOR_BGR2GRAY)
        thr = cv2.adaptiveThreshold(gray, 255, cv2.ADAPTIVE_THRESH_MEAN_C, cv2.THRESH_BINARY_INV, 31, 5)
        contours, _ = cv2.findContours(thr, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
        small_targets = 0
        candidate_targets = 0
        for c in contours:
            x, y, cw, ch = cv2.boundingRect(c)
            area = cw * ch
            # nur Kandidaten in Button-Groessenbereich
            if 400 < area < 10_000 and 0.5 < (cw / max(ch, 1)) < 6:
                candidate_targets += 1
                if min(cw, ch) < 44:
                    small_targets += 1
        if candidate_targets == 0:
            findings.append("Keine klickbaren Elemente erkannt")
            score = 0.7
        else:
            ratio_small = small_targets / candidate_targets
            score = 1.0 - ratio_small
            if ratio_small > 0.3:
                findings.append(
                    f"{small_targets} von {candidate_targets} Touch-Targets unter 44x44px"
                )
    elif viewport == "desktop":
        # Zu breite Content-Spalten verschlechtern Lesbarkeit.
        if w > 1500:
            findings.append("Sehr breiter Viewport - pruefe Max-Content-Width")
    return AnalyzerResult(
        "viewport",
        round(max(min(score, 1.0), 0.0), 3),
        findings,
        {"width": w, "height": h},
    )


def analyze_consistency(img_bgr: np.ndarray) -> AnalyzerResult:
    """Links-Rechts-Symmetrie + vertikaler Rhythmus (horizontale Kanten)."""
    gray = cv2.cvtColor(img_bgr, cv2.COLOR_BGR2GRAY)
    h, w = gray.shape
    left = gray[:, : w // 2]
    right = cv2.flip(gray[:, w // 2:], 1)
    right = right[:, : left.shape[1]]
    diff = np.abs(left.astype(int) - right.astype(int))
    symmetry = 1.0 - float(diff.mean()) / 255.0
    # Vertikaler Rhythmus: horizontale Kanten
    sobely = cv2.Sobel(gray, cv2.CV_64F, 0, 1, ksize=3)
    row_energy = np.abs(sobely).mean(axis=1)
    # Autocorrelation auf row_energy gibt Rhythmus-Hinweis
    if len(row_energy) > 32:
        ac = np.correlate(row_energy - row_energy.mean(), row_energy - row_energy.mean(), mode="full")
        ac = ac[len(ac) // 2:]
        if ac.max() > 0:
            ac = ac / ac.max()
            rhythm = float(np.mean(ac[10:100])) if len(ac) > 100 else 0.3
        else:
            rhythm = 0.3
    else:
        rhythm = 0.3
    score = round((symmetry * 0.6 + min(rhythm * 2, 1.0) * 0.4), 3)
    findings: list[str] = []
    if symmetry < 0.6:
        findings.append("Geringe Links-Rechts-Symmetrie (ok bei asymmetrischen Layouts)")
    return AnalyzerResult(
        "consistency",
        score,
        findings,
        {"symmetry": round(symmetry, 3), "rhythm": round(rhythm, 3)},
    )


# ---------------------------------------------------------------------------
# CLIP
# ---------------------------------------------------------------------------

_CLIP_CACHE = {"model": None, "processor": None, "device": None}


def _load_clip(model_id: str = "openai/clip-vit-large-patch14"):
    if _CLIP_CACHE["model"] is not None:
        return _CLIP_CACHE["model"], _CLIP_CACHE["processor"], _CLIP_CACHE["device"]
    device = "cuda" if torch.cuda.is_available() else "cpu"
    print(f"[CLIP] Laden auf {device}... (erster Lauf ~8s)", file=sys.stderr)
    model = CLIPModel.from_pretrained(model_id).to(device).eval()
    processor = CLIPProcessor.from_pretrained(model_id)
    _CLIP_CACHE.update(model=model, processor=processor, device=device)
    return model, processor, device


def analyze_clip_ux(img_bgr: np.ndarray) -> AnalyzerResult:
    if not CLIP_AVAILABLE:
        return AnalyzerResult("clip_ux", 0.5, ["CLIP nicht verfuegbar - Skipped"], {})
    model, processor, device = _load_clip()
    img_rgb = cv2.cvtColor(img_bgr, cv2.COLOR_BGR2RGB)
    dimension_scores: dict = {}
    total = 0.0
    findings: list[str] = []
    for dim in CLIP_UX_DIMENSIONS:
        texts = dim["positive"] + dim["negative"]
        with torch.no_grad():
            inputs = processor(text=texts, images=img_rgb, return_tensors="pt", padding=True).to(device)
            outputs = model(**inputs)
            logits = outputs.logits_per_image.softmax(dim=-1).cpu().numpy()[0]
            pos = float(np.sum(logits[: len(dim["positive"])]))
        dimension_scores[dim["name"]] = round(pos, 3)
        total += pos * dim["weight"]
        if pos < 0.4:
            findings.append(f"CLIP: schwacher Score fuer {dim['name']} ({pos:.2f})")
    return AnalyzerResult(
        "clip_ux",
        round(total, 3),
        findings,
        {"dimension_scores": dimension_scores, "device": device},
    )


# ---------------------------------------------------------------------------
# Orchestrierung
# ---------------------------------------------------------------------------

def detect_viewport(width: int) -> str:
    if width <= 500:
        return "mobile"
    if width <= 900:
        return "tablet"
    return "desktop"


def overall_grade(score: float) -> str:
    if score >= 0.90:
        return "A"
    if score >= 0.80:
        return "B"
    if score >= 0.70:
        return "C"
    if score >= 0.55:
        return "D"
    return "F"


def analyze_image(
    image_path: Path,
    use_clip: bool = True,
    viewport_override: Optional[str] = None,
) -> Report:
    img = cv2.imread(str(image_path))
    if img is None:
        raise FileNotFoundError(f"Bild nicht lesbar: {image_path}")
    h, w = img.shape[:2]
    viewport = viewport_override or detect_viewport(w)

    analyzers = [
        analyze_contrast(img),
        analyze_color_palette(img),
        analyze_layout(img),
        analyze_complexity(img),
        analyze_viewport(img, viewport),
        analyze_consistency(img),
    ]
    if use_clip and CLIP_AVAILABLE:
        analyzers.append(analyze_clip_ux(img))

    # Overall Score (gewichtetes Mittel)
    weights_used = {a.analyzer: WEIGHTS.get(a.analyzer, 0.0) for a in analyzers}
    weight_sum = sum(weights_used.values()) or 1.0
    overall = sum(a.score * weights_used[a.analyzer] for a in analyzers) / weight_sum

    return Report(
        file=str(image_path),
        overall_score=round(overall, 3),
        grade=overall_grade(overall),
        viewport=viewport,
        dimensions=(w, h),
        results=analyzers,
    )


def report_to_dict(r: Report) -> dict:
    d = asdict(r)
    d["dimensions"] = list(r.dimensions)
    return d


def print_report(r: Report) -> None:
    print(f"\n=== {r.file} ===")
    print(f"Viewport: {r.viewport} ({r.dimensions[0]}x{r.dimensions[1]})")
    print(f"Overall Score: {r.overall_score:.0%}  Grade: {r.grade}")
    for a in r.results:
        print(f"  - {a.analyzer:<14} {a.score:.2f}")
        for f in a.findings:
            print(f"      ! {f}")


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def main() -> int:
    parser = argparse.ArgumentParser(description="VAES UX-Analyzer")
    parser.add_argument("path", help="Bilddatei oder Ordner (mit --batch)")
    parser.add_argument("--batch", action="store_true", help="Ordner-Modus")
    parser.add_argument("--output", "-o", help="Output JSON-Pfad")
    parser.add_argument("--viewport", choices=["mobile", "tablet", "desktop"])
    parser.add_argument("--no-clip", action="store_true", help="CLIP-Analyse ueberspringen")
    parser.add_argument("--json-only", action="store_true", help="Keine Terminal-Ausgabe")
    parser.add_argument("--compare", help="Vergleiche gegen zweites Bild")
    args = parser.parse_args()

    path = Path(args.path)

    if args.compare:
        other = Path(args.compare)
        r1 = analyze_image(path, use_clip=not args.no_clip, viewport_override=args.viewport)
        r2 = analyze_image(other, use_clip=not args.no_clip, viewport_override=args.viewport)
        delta = r2.overall_score - r1.overall_score
        result = {
            "before": report_to_dict(r1),
            "after": report_to_dict(r2),
            "delta": round(delta, 3),
        }
        if args.output:
            Path(args.output).write_text(json.dumps(result, indent=2))
        if not args.json_only:
            print(f"Before: {r1.overall_score:.0%} ({r1.grade})")
            print(f"After:  {r2.overall_score:.0%} ({r2.grade})")
            print(f"Delta:  {delta:+.0%}")
        return 0

    if args.batch:
        if not path.is_dir():
            print(f"FEHLER: {path} ist kein Ordner (--batch)", file=sys.stderr)
            return 1
        images = sorted([p for p in path.iterdir() if p.suffix.lower() in {".png", ".jpg", ".jpeg"}])
        reports: list[dict] = []
        for img_path in images:
            try:
                rep = analyze_image(img_path, use_clip=not args.no_clip, viewport_override=args.viewport)
            except Exception as e:  # noqa: BLE001
                print(f"FEHLER bei {img_path}: {e}", file=sys.stderr)
                continue
            reports.append(report_to_dict(rep))
            if not args.json_only:
                print_report(rep)
        aggregate = {
            "total": len(reports),
            "reports": reports,
            "average_score": (
                round(sum(r["overall_score"] for r in reports) / max(len(reports), 1), 3)
            ),
        }
        if args.output:
            Path(args.output).write_text(json.dumps(aggregate, indent=2))
        if not args.json_only:
            print(f"\n=== Gesamt ===")
            print(f"Bilder: {len(reports)}")
            print(f"Schnitt: {aggregate['average_score']:.0%}")
        return 0

    rep = analyze_image(path, use_clip=not args.no_clip, viewport_override=args.viewport)
    if args.output:
        Path(args.output).write_text(json.dumps(report_to_dict(rep), indent=2))
    if not args.json_only:
        print_report(rep)
    return 0


if __name__ == "__main__":
    sys.exit(main())
