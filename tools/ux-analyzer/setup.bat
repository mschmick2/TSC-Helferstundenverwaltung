@echo off
setlocal enableextensions

REM Setup-Script fuer den VAES UX-Analyzer (Windows)
REM Voraussetzungen:
REM   - Python 3.13 (KEIN 3.14) installiert: `py -3.13 --version`
REM   - Optional: NVIDIA GPU + Treiber 550+ fuer CUDA 12.6

echo === VAES UX-Analyzer Setup ===
echo.

where py >nul 2>&1
if errorlevel 1 (
    echo FEHLER: 'py' Launcher nicht gefunden. Bitte Python 3.13 installieren.
    exit /b 1
)

py -3.13 --version >nul 2>&1
if errorlevel 1 (
    echo FEHLER: Python 3.13 nicht gefunden. Bitte installieren von python.org.
    echo Tipp: WICHTIG NICHT 3.14 verwenden - PyTorch-CUDA-Wheels fehlen.
    exit /b 1
)

if exist venv\Scripts\activate.bat (
    echo [1/4] venv existiert bereits - ueberspringe create.
) else (
    echo [1/4] venv anlegen...
    py -3.13 -m venv venv
    if errorlevel 1 (
        echo FEHLER: venv-Erstellung fehlgeschlagen.
        exit /b 1
    )
)

echo [2/4] pip upgraden...
call venv\Scripts\python.exe -m pip install --upgrade pip wheel setuptools

echo [3/4] PyTorch mit CUDA 12.6 installieren (RTX-kompatibel)...
call venv\Scripts\python.exe -m pip install torch torchvision --index-url https://download.pytorch.org/whl/cu126
if errorlevel 1 (
    echo WARNUNG: CUDA-Install fehlgeschlagen. Installiere CPU-only-PyTorch als Fallback.
    call venv\Scripts\python.exe -m pip install torch torchvision
)

echo [4/4] Restliche Dependencies...
call venv\Scripts\python.exe -m pip install -r requirements.txt

echo.
echo === Installation fertig ===
echo.
call venv\Scripts\python.exe -c "import torch; print('PyTorch:', torch.__version__, '| CUDA verfuegbar:', torch.cuda.is_available())"
echo.
echo Aktivieren mit:  venv\Scripts\activate.bat
echo Testlauf:         python ux_analyzer.py --help

endlocal
