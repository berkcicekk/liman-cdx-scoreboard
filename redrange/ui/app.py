"""Flet 1.0 kontrol paneli.

MIMARI KURAL: ui/ -> core/ tek yonlu bagimlidir. Arayuz motoru YONETIR ve
OKUR, ama motorun icine mantik koymaz. Motor arayuz olmadan da calisir
(bkz. run_headless.py).

Flet 1.0 NOTU: olay isleyicileri event loop uzerinde calisir; icinde bloklayan
is yapilirsa TUM arayuz donar. Bu yuzden motor ve dinleyici ayri thread'lerde
doner, arayuz sadece periyodik olarak anlik goruntu okur ve page.update() der.
"""
from __future__ import annotations

import time

import flet as ft

from core.config import AppContext
from core.events import read_events, tail_events

STATE_COLORS = {
    "pending": ft.Colors.GREY_500,
    "up": ft.Colors.RED_400,          # saldirganin foothold'u canli
    "down": ft.Colors.ORANGE_400,     # dusuruldu, kok neden belirsiz
    "remediated": ft.Colors.GREEN_400  # savunan kazandi
}

STATE_LABEL = {
    "pending": "BEKLIYOR",
    "up": "FOOTHOLD CANLI",
    "down": "DUSURULDU",
    "remediated": "KAPATILDI",
}

EVENT_COLORS = {
    "foothold_established": ft.Colors.RED_300,
    "foothold_down": ft.Colors.ORANGE_300,
    "remediated": ft.Colors.GREEN_300,
    "regression": ft.Colors.PURPLE_200,
    "probe_error": ft.Colors.GREY_500,
}

EVENT_LABEL = {
    "foothold_established": "foothold kuruldu",
    "foothold_down": "foothold dusuruldu  [+gecici]",
    "remediated": "zafiyet kapatildi   [+KALICI]",
    "regression": "duzeltme geri alindi",
    "probe_error": "olcum hatasi",
}

MAX_LOG_LINES = 300


def build_app(ctx: AppContext):
    """AppContext'i saran Flet main fonksiyonunu uretir."""

    def main(page: ft.Page) -> None:
        page.title = "Red Range — Kontrol Paneli"
        page.theme_mode = ft.ThemeMode.DARK
        page.padding = 16
        page.window.width = 1180
        page.window.height = 820

        # ---------- ust bar ----------
        status_text = ft.Text("durduruldu", size=14, color=ft.Colors.GREY_400)
        tick_text = ft.Text("tik: 0", size=12, color=ft.Colors.GREY_500)

        def toggle(e):
            if ctx.engine.running:
                ctx.stop()
            else:
                ctx.start()
            sync_button()
            page.update()

        action_btn = ft.Button(content="Baslat", icon=ft.Icons.PLAY_ARROW,
                               on_click=toggle)

        def sync_button():
            if ctx.engine.running:
                action_btn.content = "Durdur"
                action_btn.icon = ft.Icons.STOP
                action_btn.bgcolor = ft.Colors.RED_700
                status_text.value = "calisiyor"
                status_text.color = ft.Colors.GREEN_400
            else:
                action_btn.content = "Baslat"
                action_btn.icon = ft.Icons.PLAY_ARROW
                action_btn.bgcolor = ft.Colors.GREEN_700
                status_text.value = "durduruldu"
                status_text.color = ft.Colors.GREY_400

        header = ft.Row(
            alignment=ft.MainAxisAlignment.SPACE_BETWEEN,
            controls=[
                ft.Row(controls=[
                    ft.Icon(ft.Icons.SHIELD, color=ft.Colors.BLUE_300, size=28),
                    ft.Text("RED RANGE", size=22, weight=ft.FontWeight.BOLD),
                    ft.Text(f"dinleyici :{ctx.config['listen_port']}",
                            size=12, color=ft.Colors.GREY_500),
                ]),
                ft.Row(controls=[tick_text, status_text, action_btn]),
            ],
        )

        # ---------- ozet kartlari ----------
        def stat_card(label: str, color: str):
            value = ft.Text("0", size=26, weight=ft.FontWeight.BOLD, color=color)
            card = ft.Container(
                content=ft.Column(spacing=2, controls=[
                    value,
                    ft.Text(label, size=11, color=ft.Colors.GREY_400),
                ]),
                padding=14, border_radius=10, bgcolor=ft.Colors.with_opacity(0.06, color),
                expand=True,
            )
            return card, value

        c_up, v_up = stat_card("FOOTHOLD CANLI", ft.Colors.RED_400)
        c_down, v_down = stat_card("DUSURULDU", ft.Colors.ORANGE_400)
        c_fixed, v_fixed = stat_card("KAPATILDI", ft.Colors.GREEN_400)
        c_sess, v_sess = stat_card("CANLI OTURUM", ft.Colors.BLUE_300)
        stats_row = ft.Row(spacing=12, controls=[c_up, c_down, c_fixed, c_sess])

        # ---------- makine tablosu ----------
        table = ft.DataTable(
            columns=[
                ft.DataColumn(label="Makine"),
                ft.DataColumn(label="Kullanici"),
                ft.DataColumn(label="Host"),
                ft.DataColumn(label="Vektor"),
                ft.DataColumn(label="Tespit"),
                ft.DataColumn(label="Durum"),
                ft.DataColumn(label="Sure"),
                ft.DataColumn(label="Gecici"),
                ft.DataColumn(label="Kalici"),
            ],
            rows=[],
            column_spacing=22,
            heading_row_color=ft.Colors.with_opacity(0.05, ft.Colors.WHITE),
        )

        table_box = ft.Container(
            content=ft.Column(controls=[table], scroll=ft.ScrollMode.AUTO),
            padding=10, border_radius=10,
            bgcolor=ft.Colors.with_opacity(0.03, ft.Colors.WHITE),
            expand=True,
        )

        # ---------- olay akisi ----------
        log_view = ft.ListView(controls=[], spacing=2, auto_scroll=True, expand=True)
        log_box = ft.Container(
            content=ft.Column(spacing=6, controls=[
                ft.Text("OLAY AKISI  (events.jsonl)", size=12,
                        weight=ft.FontWeight.BOLD, color=ft.Colors.GREY_400),
                log_view,
            ]),
            padding=10, border_radius=10,
            bgcolor=ft.Colors.with_opacity(0.03, ft.Colors.WHITE),
            expand=True,
        )

        # ---------- canli baglantilar (koken) ----------
        conn_list = ft.ListView(controls=[], spacing=3, expand=True)
        conn_box = ft.Container(
            content=ft.Column(spacing=6, controls=[
                ft.Text("CANLI BAGLANTILAR  (kim, nereden, nasil)", size=12,
                        weight=ft.FontWeight.BOLD, color=ft.Colors.GREY_400),
                conn_list,
            ]),
            padding=10, border_radius=10,
            bgcolor=ft.Colors.with_opacity(0.03, ft.Colors.WHITE),
            expand=True,
        )

        configured_ids = {t["machine_id"] for t in ctx.config.get("targets", [])}

        page.add(
            ft.Column(
                expand=True, spacing=14,
                controls=[header, stats_row,
                          ft.Row(expand=True, spacing=12,
                                 controls=[
                                     ft.Container(content=table_box, expand=3),
                                     ft.Container(expand=2, content=ft.Column(
                                         expand=True, spacing=12,
                                         controls=[conn_box, log_box])),
                                 ])],
            )
        )

        # ---------- log yardimcilari ----------
        def log_line(ev: dict) -> ft.Control:
            et = ev.get("event_type", "")
            stamp = time.strftime("%H:%M:%S", time.localtime(ev.get("ts", 0)))
            label = EVENT_LABEL.get(et, et)
            detail = ev.get("detail") or ""
            txt = f"{stamp}  {ev.get('machine_id','?'):<12} {ev.get('vector_type','?'):<7} {label}"
            if detail:
                txt += f"  — {detail}"
            return ft.Text(txt, size=11, font_family="monospace",
                           color=EVENT_COLORS.get(et, ft.Colors.GREY_300))

        def push_events(rows: list[dict]) -> None:
            for ev in rows:
                log_view.controls.append(log_line(ev))
            if len(log_view.controls) > MAX_LOG_LINES:
                del log_view.controls[:len(log_view.controls) - MAX_LOG_LINES]

        # acilista gecmisi doldur
        events_path = ctx.config["events_path"]
        push_events(read_events(events_path, limit=80))
        try:
            import os
            offset = os.path.getsize(events_path)
        except OSError:
            offset = 0

        # ---------- periyodik yenileme (arka plan thread'i) ----------
        def refresh_loop() -> None:
            nonlocal offset
            while True:
                try:
                    snap = ctx.engine.snapshot()
                    sess = ctx.sessions.snapshot()

                    counts = {"up": 0, "down": 0, "remediated": 0, "pending": 0}
                    rows = []
                    for r in snap:
                        counts[r["state"]] = counts.get(r["state"], 0) + 1
                        color = STATE_COLORS.get(r["state"], ft.Colors.GREY_400)
                        detect = "netstat/log" if r["vector_type"] == "beacon" else "nmap/port"
                        rows.append(ft.DataRow(cells=[
                            ft.DataCell(ft.Text(r["machine_id"], size=12)),
                            ft.DataCell(ft.Text(r["user_id"], size=12)),
                            ft.DataCell(ft.Text(r["host"], size=12,
                                                color=ft.Colors.GREY_400)),
                            ft.DataCell(ft.Text(r["vector_type"], size=12)),
                            ft.DataCell(ft.Text(detect, size=11,
                                                color=ft.Colors.GREY_500)),
                            ft.DataCell(ft.Text(STATE_LABEL.get(r["state"], r["state"]),
                                                size=12, color=color,
                                                weight=ft.FontWeight.BOLD)),
                            ft.DataCell(ft.Text(f"{r['since']}s", size=12,
                                                color=ft.Colors.GREY_500)),
                            ft.DataCell(ft.Text(str(r["temporary"]), size=12,
                                                color=ft.Colors.ORANGE_300)),
                            ft.DataCell(ft.Text(str(r["permanent"]), size=12,
                                                color=ft.Colors.GREEN_300)),
                        ]))
                    table.rows = rows

                    # --- canli baglantilar paneli ---
                    conn_rows: list[ft.Control] = []
                    for s in sess:
                        known = s["machine_id"] in configured_ids
                        if not s["identified"]:
                            col, tag = ft.Colors.RED_300, "KIMLIKSIZ"
                        elif not known:
                            col, tag = ft.Colors.ORANGE_300, "CONFIG'DE YOK"
                        else:
                            col, tag = ft.Colors.GREEN_300, "eslesti"
                        conn_rows.append(ft.Text(
                            f"{s['machine_id']:<14} <- {s['peer']:<22} "
                            f"kalp={s['beats']:<4} {int(s['age'])}s  [{tag}]",
                            size=11, font_family="monospace", color=col))
                    if not conn_rows:
                        conn_rows.append(ft.Text(
                            "hic baglanti yok — beacon servisleri calisiyor mu?",
                            size=11, color=ft.Colors.GREY_600))
                    # config'de olup hic baglanmayanlar
                    live_ids = {s["machine_id"] for s in sess}
                    for mid in sorted(configured_ids - live_ids):
                        conn_rows.append(ft.Text(
                            f"{mid:<14} -- baglanti YOK", size=11,
                            font_family="monospace", color=ft.Colors.GREY_600))
                    conn_list.controls = conn_rows

                    v_up.value = str(counts.get("up", 0))
                    v_down.value = str(counts.get("down", 0))
                    v_fixed.value = str(counts.get("remediated", 0))
                    v_sess.value = str(len(sess))
                    tick_text.value = f"tik: {ctx.engine.ticks}"
                    sync_button()

                    new_rows, offset = tail_events(events_path, offset)
                    if new_rows:
                        push_events(new_rows)

                    page.update()
                except Exception as exc:          # arayuz asla comesin
                    print("[ui] refresh hatasi:", exc, flush=True)
                time.sleep(1.0)

        sync_button()
        page.update()
        page.run_thread(refresh_loop)

    return main
