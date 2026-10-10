#!/usr/bin/env bash
# =====================================================================
#  RED RANGE - KONTROLCU tarafi tek-komut kurulum (Pardus / Debian / mac)
#
#  Butun yapiyi TEK KALISTA ayaga kaldirir: sanal ortam + tum bagimliliklar
#  (flet GUI ve Cenk koprusu icin psycopg dahil), ardindan redrange'deki
#  HICBIR modulu atlamadan hepsinin yerinde ve import edilebilir oldugunu
#  dogrular. .ps1 yerine dogrudan Linux'ta calisir.
#
#  KULLANIM:
#     ./install.sh               # tam kurulum + dogrulama
#     ./install.sh --check       # kurma, sadece ortami/modulleri dogrula
#     ./install.sh --selftest    # kurulumdan sonra uctan uca selftest.py calistir
#     ./install.sh --no-bridge   # psycopg'yi atla (sadece motor + GUI)
#     ./install.sh -h            # bu yardim
#
#  NOT: Bu script HEDEF makineyi hazirlamaz. Hedef (BT-x) kurulumlari
#       icin scripts/setup_BT-*.sh kullanilir (bkz. PARDUS_SETUP.md).
# =====================================================================
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
# Git Bash yol yeniden yazmasini kapat (Windows'ta calistirilirsa).
export MSYS_NO_PATHCONV=1

CHECK_ONLY=0     # 1 => kurma, sadece dogrula
RUN_SELFTEST=0   # 1 => sonunda selftest.py
WITH_BRIDGE=1    # 0 => psycopg kurma (requirements yerine sadece flet)

while [[ $# -gt 0 ]]; do
    case "$1" in
        --check)     CHECK_ONLY=1; shift ;;
        --selftest)  RUN_SELFTEST=1; shift ;;
        --no-bridge) WITH_BRIDGE=0; shift ;;
        -h|--help)   grep '^#' "$0" | grep -v '^#!' | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Bilinmeyen secenek: $1" >&2; exit 1 ;;
    esac
done

G="\033[92m"; R="\033[91m"; Y="\033[93m"; X="\033[0m"
ok()   { echo -e "  ${G}[OK]${X}    $1"; }
bad()  { echo -e "  ${R}[HATA]${X}  $1"; }
warn() { echo -e "  ${Y}[DIKKAT]${X} $1"; }

# --- Python surumu -----------------------------------------------------------
echo "[1/5] Python kontrol ediliyor"
PY=python3; command -v "$PY" >/dev/null 2>&1 || PY=python
command -v "$PY" >/dev/null 2>&1 || { bad "python3 bulunamadi. 'sudo apt install python3 python3-venv' kurun."; exit 1; }
if ! "$PY" -c 'import sys; sys.exit(0 if sys.version_info >= (3,10) else 1)'; then
    bad "Python 3.10+ gerekli (flet 1.0 icin). Bulunan: $("$PY" -V 2>&1)"; exit 1
fi
ok "$("$PY" -V 2>&1)"

# --- Modul envanteri: HICBIRI atlanmasin -------------------------------------
# Kurulumdan once de sonra da dosyalarin yerinde oldugunu dogrularuz.
echo "[2/5] Modul envanteri dogrulaniyor (atlamadan)"
REQUIRED_FILES=(
    core/__init__.py core/config.py core/engine.py core/events.py
    core/models.py core/probes.py core/sessions.py core/startup.py core/vectors.py
    ui/__init__.py ui/app.py
    agent/beacon.py agent/decoy_listener.py agent/weak_service.py
    run_gui.py run_headless.py bridge_cenk.py
    selftest.py diagnose.py config.json requirements.txt
    scripts/setup_BT-1.sh scripts/setup_BT-2.sh scripts/setup_BT-3.sh scripts/setup_BT-4.sh
    setup_bridge.sh
)
missing=0
for f in "${REQUIRED_FILES[@]}"; do
    [[ -e "$f" ]] || { bad "eksik: $f"; missing=1; }
done
[[ "$missing" -eq 0 ]] || { bad "Yukaridaki moduller eksik; depo tam cekilmemis olabilir."; exit 1; }
ok "${#REQUIRED_FILES[@]} dosya/mudul yerinde"
# Shell scriptlerini calistirilabilir yap (zip/kopya calistirma bitini dusurur).
chmod +x scripts/setup_BT-*.sh setup_bridge.sh install.sh 2>/dev/null || true

# --- Sanal ortam + bagimliliklar ---------------------------------------------
if [[ "$CHECK_ONLY" -eq 0 ]]; then
    echo "[3/5] Sanal ortam ve bagimliliklar kuruluyor"
    if [[ ! -d .venv ]]; then "$PY" -m venv .venv; fi
else
    echo "[3/5] --check: kurulum atlandi"
    [[ -d .venv ]] || warn ".venv yok; once bayraksiz calistirip kurun."
fi

# venv aktivasyonu (Linux/mac veya Windows Git Bash)
if   [[ -f .venv/bin/activate ]];     then . .venv/bin/activate
elif [[ -f .venv/Scripts/activate ]]; then . .venv/Scripts/activate
elif [[ "$CHECK_ONLY" -eq 0 ]];       then bad "venv aktivasyon betigi bulunamadi"; exit 1
fi

if [[ "$CHECK_ONLY" -eq 0 ]]; then
    python -m pip install --quiet --upgrade pip
    if [[ "$WITH_BRIDGE" -eq 1 ]]; then
        python -m pip install --quiet -r requirements.txt
        ok "flet + psycopg (kopru dahil) kuruldu"
    else
        # Kopru haric: requirements'tan sadece flet satirini al.
        python -m pip install --quiet "$(grep -E '^flet' requirements.txt)"
        ok "flet kuruldu (--no-bridge: psycopg atlandi)"
    fi
fi

# --- Import dumani: moduller gercekten yuklenebiliyor mu ----------------------
echo "[4/5] Import dogrulamasi"
import_check() {  # $1 = aciklama, $2 = python ifadesi
    if python -c "$2" >/dev/null 2>&1; then ok "$1"; else bad "$1 -> import edilemedi"; return 1; fi
}
rc=0
import_check "core motoru (startup/engine/vectors/...)" \
    "import core.config, core.engine, core.events, core.models, core.probes, core.sessions, core.startup, core.vectors" || rc=1
import_check "agent modulleri (beacon/decoy/weak_service derlenebilir)" \
    "import py_compile; [py_compile.compile(f, doraise=True) for f in ('agent/beacon.py','agent/decoy_listener.py','agent/weak_service.py')]" || rc=1
if [[ "$CHECK_ONLY" -eq 0 ]]; then
    import_check "GUI katmani (flet + ui.app)" "import flet, ui.app" || rc=1
    if [[ "$WITH_BRIDGE" -eq 1 ]]; then
        import_check "Cenk koprusu (psycopg + bridge_cenk)" "import psycopg, bridge_cenk" || rc=1
    fi
fi
[[ "$rc" -eq 0 ]] || { bad "Bazi moduller yuklenemedi (yukari bak)."; exit 1; }

# --- Opsiyonel uctan uca selftest --------------------------------------------
if [[ "$RUN_SELFTEST" -eq 1 ]]; then
    echo "[5/5] selftest.py (uctan uca, 127.0.0.1)"
    python selftest.py
else
    echo "[5/5] Kurulum tamam."
fi

echo
echo -e "${G}HAZIR.${X} Sonraki adimlar:"
echo "  1) Sanal ortami aktive et:   . .venv/bin/activate"
echo "  2) config.json icindeki 'host' alanlarini GERCEK Pardus IP'leri ile degistir"
echo "     (SET_ME kaldikca her sey BEKLIYOR gorunur). Sorun olursa: python diagnose.py"
echo "  3) Panel (GUI):              python run_gui.py"
echo "     Arayuzsuz / servis:       python run_headless.py"
echo "  4) Cenk (puan tablosu) ile baglamak icin: ./setup_bridge.sh  (bkz. BRIDGE.md)"
