# VAES UX-Analyzer

Automatisierte, wiederholbare UX-Qualitaetsbewertung per Computer Vision.
Nutzt OpenCV + (optional) CLIP (ViT-L/14) fuer semantische Analyse.

## Voraussetzungen

- **Python 3.10 - 3.13** (NICHT 3.14 — PyTorch-CUDA-Wheels fehlen dort)
- Optional: NVIDIA GPU mit Treiber 550+ fuer CUDA 12.6
- Windows/Linux/macOS

## Setup (Windows)

```bash
cd tools\ux-analyzer
setup.bat
```

Das Script:
1. Legt `venv/` an (mit Python 3.13)
2. Installiert PyTorch + CUDA 12.6
3. Installiert OpenCV, Transformers, scikit-learn

## Setup (manuell, Linux/Mac)

```bash
python3.13 -m venv venv
source venv/bin/activate
pip install --upgrade pip wheel
pip install torch torchvision --index-url https://download.pytorch.org/whl/cu126
pip install -r requirements.txt
```

## Pruefen, ob CUDA korrekt laeuft

```bash
venv\Scripts\activate.bat
python -c "import torch; print('CUDA verfuegbar:', torch.cuda.is_available())"
```

Falls `False`: Installation lief auf CPU-Version von PyTorch. Das ist noch
funktionsfaehig, aber CLIP laeuft ~10x langsamer.

## Nutzung

```bash
venv\Scripts\activate.bat

# Einzelbild
python ux_analyzer.py ..\..\e2e-tests\screenshots\03-dashboard.png

# Batch-Modus: alle Bilder in einem Ordner
python ux_analyzer.py ..\..\e2e-tests\screenshots\ --batch -o baseline_report.json

# Viewport explizit setzen (sonst auto via Bildbreite)
python ux_analyzer.py mobile.png --viewport mobile

# Ohne CLIP (schneller, keine GPU noetig)
python ux_analyzer.py screenshot.png --no-clip

# JSON-only (fuer CI)
python ux_analyzer.py screenshots/ --batch --json-only -o ux.json

# Before/After-Vergleich
python ux_analyzer.py before.png --compare after.png -o diff.json
```

## Analysedimensionen

| Analyzer | Gewicht | GPU? | Was wird gemessen |
|----------|---------|------|-------------------|
| contrast | 15% | nein | Text-Kontrast in erkannten Textregionen |
| color_palette | 10% | nein | K-means-Farbcluster, Hues, Saettigung |
| layout | 15% | nein | Whitespace, Quadrantengewicht, Alignment |
| complexity | 10% | nein | Sobel-Gradient-Magnitude |
| viewport | 15% | nein | Mobile: Touch-Targets; Desktop: Spaltenbreite |
| consistency | 10% | nein | Links-Rechts-Symmetrie, vertikaler Rhythmus |
| clip_ux | 25% | **ja** | CLIP-Semantik: visual quality, readability, navigation, hierarchy |

## Grades

| Grade | Score | Bedeutung |
|-------|-------|-----------|
| A | ≥ 90% | Ausgezeichnet |
| B | 80-89% | Gut, Feinschliff |
| C | 70-79% | Akzeptabel, mehrere Verbesserungen moeglich |
| D | 55-69% | Unter Standard - vor Release fixen |
| F | < 55% | Ernste UX-Probleme |

## VAES-Workflow

```bash
# 1. Dev-Server starten
cd src\public && php -S localhost:8000

# 2. E2E-Tests mit Screenshot-Erzeugung
cd e2e-tests && npm test

# 3. UX-Analyse
cd tools\ux-analyzer && venv\Scripts\activate.bat
python ux_analyzer.py ..\..\e2e-tests\screenshots\ --batch -o baseline_report.json
```

## CI-Betrieb

In CI meist ohne GPU. Mit `--no-clip` bleibt alles lauffaehig:

```bash
python ux_analyzer.py screenshots/ --batch --no-clip --json-only -o ux.json
python -c "import json, sys; r = json.load(open('ux.json')); sys.exit(0 if r['average_score'] >= 0.65 else 1)"
```
