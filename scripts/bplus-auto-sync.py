#!/usr/bin/env python3
"""ซิงค์เอกสารสาย B18 จาก BPlus ขึ้น ERP อัตโนมัติ (รันบนเครื่องในร้านด้วย Windows Task Scheduler)

ขั้นตอนแต่ละรอบ
1. รัน scripts/bplus-route-export.py ตั้งแต่วันเริ่มซิงค์ (ค่า since ในไฟล์ตั้งค่า) อ่าน BPlus แบบ SELECT อย่างเดียว
2. บีบอัดไฟล์แล้วส่งขึ้น ERP ที่ POST /api/bplus/sync พร้อม token ที่ส่งได้แค่สายนี้
3. เขียนผลลง logs/bplus-sync-YYYY-MM.log ถ้าล้ม exit code ไม่เป็น 0 (Task Scheduler จะแสดง Last Run Result)

ส่งทั้งช่วงทุกรอบ ไม่ใช่เฉพาะใบที่เปลี่ยน: ใบที่ถูกแก้หรือยกเลิกใน BPlus ตามมาครบ และลูกหนี้ยกมาคิดจากวันเริ่มเดิมเสมอ
ห้ามเลื่อนวัน since ไปข้างหน้า: บิลที่อยู่ใน ERP แล้วจะกลายเป็นลูกหนี้ยกมาซ้ำ

ไฟล์ตั้งค่า (ค่าเริ่มต้น bplus-sync.ini ข้างสคริปต์นี้ หรือกำหนดด้วย --config) ห้ามใส่ในแชทหรือ git:
    [bplus]
    user = <user SQL ที่อ่านได้อย่างเดียว>
    password = <รหัส>
    route = B18
    since = 2026-11-01
    [erp]
    url = https://erp.popstarcenter.com/api/bplus/sync
    token = <token จาก php artisan bplus:sync-token --issue>

ใช้: python scripts/bplus-auto-sync.py            (ดึงและส่ง)
     python scripts/bplus-auto-sync.py --dry-run  (ดึงอย่างเดียว ไม่ส่ง ไว้ทดสอบ)
"""
import argparse
import configparser
import datetime
import gzip
import json
import os
import pathlib
import subprocess
import sys
import urllib.error
import urllib.request

HERE = pathlib.Path(__file__).resolve().parent
EXPORTER = HERE / "bplus-route-export.py"


def log(work: pathlib.Path, message: str) -> None:
    line = f"{datetime.datetime.now():%Y-%m-%d %H:%M:%S} {message}"
    print(line)
    logs = work / "logs"
    logs.mkdir(parents=True, exist_ok=True)
    with open(logs / f"bplus-sync-{datetime.date.today():%Y-%m}.log", "a", encoding="utf-8") as handle:
        handle.write(line + "\n")


def main() -> int:
    # Task Scheduler บน Windows ภาษาอังกฤษใช้ code page ที่พิมพ์ภาษาไทยไม่ได้ อย่าให้ print ภาษาไทยทำสคริปต์ล้ม
    for stream in (sys.stdout, sys.stderr):
        if hasattr(stream, "reconfigure"):
            stream.reconfigure(encoding="utf-8", errors="replace")
    parser = argparse.ArgumentParser()
    parser.add_argument("--config", default=str(HERE / "bplus-sync.ini"))
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()

    config = configparser.ConfigParser()
    if not config.read(args.config, encoding="utf-8-sig")  # Notepad บันทึกแบบมี BOM:
        print(f"ไม่พบไฟล์ตั้งค่า {args.config}")
        return 2
    route = config.get("bplus", "route", fallback="B18")
    since = config.get("bplus", "since")
    datetime.date.fromisoformat(since)
    work = pathlib.Path(config.get("sync", "work_dir", fallback=str(HERE / "bplus-sync-work")))
    work.mkdir(parents=True, exist_ok=True)

    env = dict(os.environ, BPLUS_USER=config.get("bplus", "user"), BPLUS_PASSWORD=config.get("bplus", "password"),
               PYTHONIOENCODING="utf-8")
    started = datetime.datetime.now()
    export = subprocess.run([sys.executable, str(EXPORTER), since, route], cwd=work, env=env,
                            capture_output=True, text=True, encoding="utf-8", timeout=1800)
    summary = [l for l in export.stdout.splitlines() if l.startswith(("เสร็จ", "เตือน"))]
    if export.returncode != 0:
        # ไม่เขียน stderr ทั้งก้อน (อาจมีชื่อ user/เครื่อง) เอาแค่บรรทัดสุดท้าย
        last = (export.stderr.strip().splitlines() or ["(ไม่มีข้อความ)"])[-1]
        log(work, f"ล้ม: ดึงจาก BPlus ไม่สำเร็จ {last[:300]}")
        return 1
    file = work / f"bplus-{route}-{datetime.date.today().isoformat()}.json"
    log(work, f"ดึงเสร็จ {(datetime.datetime.now() - started).seconds} วินาที " + " | ".join(summary))
    if args.dry_run:
        log(work, f"ทดสอบ: ไม่ส่งขึ้น ERP ไฟล์อยู่ที่ {file}")
        return 0

    body = gzip.compress(file.read_bytes())
    request = urllib.request.Request(config.get("erp", "url"), data=body, method="POST", headers={
        "Authorization": "Bearer " + config.get("erp", "token"),
        "Content-Type": "application/json", "Content-Encoding": "gzip", "Accept": "application/json",
        "User-Agent": "bplus-auto-sync",
    })
    try:
        with urllib.request.urlopen(request, timeout=600) as response:
            result = json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as error:
        try:
            message = json.loads(error.read().decode("utf-8")).get("message")
        except Exception:
            message = error.reason
        log(work, f"ล้ม: ERP ตอบ {error.code} {message}")
        return 1
    except (urllib.error.URLError, TimeoutError) as error:
        log(work, f"ล้ม: ต่อ ERP ไม่ได้ {error}")
        return 1

    if result.get("status") == "processing":
        # ERP ตอบรับทันทีแล้วบันทึกเบื้องหลัง (ทั้งสายใช้เวลาเกิน 60 วินาที) ผลจริงดูที่หน้าเอกสารสายขาย
        log(work, f"ส่งขึ้น ERP แล้ว {len(body) // 1024} KB: {result.get('received')} ใบ · ERP กำลังบันทึกเบื้องหลัง (รอบ #{result.get('run_id')})")
        return 0
    problems = result.get("problems") or {}
    log(work, f"ส่งขึ้น ERP แล้ว {len(body) // 1024} KB: รับ {result.get('received')} ใบ · เป็นเอกสาร ERP {result.get('erp_documents')} ใบ"
              + (f" · มีปัญหา {len(problems)} ใบ: " + ", ".join(list(problems)[:10]) if problems else ""))
    return 0


if __name__ == "__main__":
    sys.exit(main())
