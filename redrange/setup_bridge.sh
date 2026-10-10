#!/usr/bin/env bash
# Cenk koprusu hizli kurulum: venv + psycopg kurar, bridge_map.json'u ve DB
# erisimini onkontrolden gecirir, sorun yoksa kopruyu baslatir.
#
# Kullanim:
#   export BRIDGE_DB_PASSWORD='...'        # ls_scoreboard parolasi
#   ./setup_bridge.sh                      # kurulum + onkontrol + baslat
#   ./setup_bridge.sh --check              # sadece dogrula, baslatma
#   ./setup_bridge.sh --once               # bir tur isle ve cik
#   ./setup_bridge.sh --map bridge_map.demo.json --config config_demo.json
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
# Git Bash yol yeniden yazmasini kapat (Windows'ta calistirilirsa).
export MSYS_NO_PATHCONV=1

MAP=bridge_map.json
CONFIG=config.json
RUN=1          # 0 => sadece onkontrol
EXTRA=()       # kopruye gececek ek bayraklar (--once, --poll ...)

while [[ $# -gt 0 ]]; do
    case "$1" in
        --map)     MAP="$2"; shift 2 ;;
        --config)  CONFIG="$2"; shift 2 ;;
        --check)   RUN=0; shift ;;
        --once)    EXTRA+=(--once); shift ;;
        --poll)    EXTRA+=(--poll "$2"); shift 2 ;;
        -h|--help) grep '^#' "$0" | grep -v '^#!' | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Bilinmeyen secenek: $1" >&2; exit 1 ;;
    esac
done

# --- Python ve venv ----------------------------------------------------------
PY=python3; command -v "$PY" >/dev/null 2>&1 || PY=python
if [[ ! -d .venv ]]; then
    echo "[1/4] Sanal ortam olusturuluyor (.venv)"
    "$PY" -m venv .venv
fi
# venv aktivasyonu (Linux/mac veya Windows Git Bash)
if [[ -f .venv/bin/activate ]]; then . .venv/bin/activate
elif [[ -f .venv/Scripts/activate ]]; then . .venv/Scripts/activate
else echo "venv aktivasyon betigi bulunamadi" >&2; exit 1; fi

echo "[2/4] Bagimliliklar kuruluyor (psycopg dahil)"
python -m pip install --quiet --upgrade pip
python -m pip install --quiet -r requirements.txt

# --- Onkontrol: harita + DB + takimlar --------------------------------------
echo "[3/4] Onkontrol: $MAP + veritabani"
python - "$MAP" <<'PY'
import sys, uuid
from bridge_cenk import BridgeMap, CenkWriter

m = BridgeMap.load(sys.argv[1])

def is_uuid(s):
    try: uuid.UUID(str(s)); return True
    except Exception: return False

errs = []
if not m.server_id or str(m.server_id).startswith("SET_ME") or not is_uuid(m.server_id):
    errs.append(f"server_id gecersiz/doldurulmamis: {m.server_id!r}")
if not m.actor_uuid or str(m.actor_uuid).startswith("SET_ME") or not is_uuid(m.actor_uuid):
    errs.append(f"actor_uuid gecersiz/doldurulmamis: {m.actor_uuid!r}")
if not m.teams:
    errs.append("teams bos")
for mid, tid in m.teams.items():
    if not isinstance(tid, int) or tid <= 0:
        errs.append(f"team_id gecersiz ({mid} -> {tid}); Cenk'teki gercek ls_teams.id yazin")
if errs:
    print("  HARITA HATALARI:")
    for e in errs: print("   -", e)
    sys.exit(2)

# CenkWriter __init__ parolayi env'den okur ve baglanir -> parola+erisim testi
try:
    w = CenkWriter(m)
except Exception as e:
    print(f"  DB baglanti/parola hatasi: {type(e).__name__}: {e}")
    sys.exit(3)

missing = []
with w.conn.cursor() as cur:
    for mid, tid in m.teams.items():
        cur.execute("SELECT 1 FROM ls_teams WHERE id=%s AND server_id=%s", (tid, m.server_id))
        if cur.fetchone() is None:
            missing.append(f"{mid} -> team_id={tid}")
w.close()
if missing:
    print("  ls_teams'de BULUNMAYAN takimlar (id VE server_id eslesmeli):")
    for x in missing: print("   -", x)
    print("  Cok once Cenk'te takimlari olusturup id'lerini haritaya yazin.")
    sys.exit(4)

print("  Onkontrol TAMAM: harita gecerli, DB erisimi var, tum takimlar mevcut.")
PY

# --- Baslat ------------------------------------------------------------------
if [[ "$RUN" -eq 0 ]]; then
    echo "[4/4] --check: baslatilmadi. Baslatmak icin bayraksiz calistirin."
    exit 0
fi
echo "[4/4] Kopru baslatiliyor"
exec python bridge_cenk.py --map "$MAP" --config "$CONFIG" "${EXTRA[@]}"
