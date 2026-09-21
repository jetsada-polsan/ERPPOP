"""Run the operator-facing hardware UAT on a Windows POS terminal.

Examples::

    python e2e/hardware_uat.py --db C:\\PopCentral\\popstar-pos.db \\
        --scan 8850000000003 --scan 801001012550?
    python e2e/hardware_uat.py --db C:\\PopCentral\\popstar-pos.db \\
        --printer "Receipt 80mm" --paper-width 80 --print

The ``--print`` flag is deliberately required before a real Windows spooler
job is sent.  Without it the command only checks that the selected queue is
installed, making it safe to run as a read-only preflight.
"""
from __future__ import annotations

import argparse
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from pos_python.database import connect
from pos_python.hardware_uat import inspect_scan
from pos_python.printers import installed_printer_names, print_text_to_windows_queue


def parser() -> argparse.ArgumentParser:
    command = argparse.ArgumentParser(description="PopCentral POS hardware UAT")
    command.add_argument("--db", required=True, type=Path, help="SQLite ของ POS เครื่องที่จะทดสอบ")
    command.add_argument("--scan", action="append", default=[], help="โค้ดที่ยิงจาก scanner; ใช้ซ้ำได้หลายครั้ง")
    command.add_argument("--printer", help="ชื่อ Windows printer queue ที่ติดตั้งจริง")
    command.add_argument("--paper-width", type=int, choices=(58, 80), default=80)
    command.add_argument("--print", action="store_true", help="ส่งงานพิมพ์จริงไปยัง queue")
    return command


def main(argv: list[str] | None = None) -> int:
    args = parser().parse_args(argv)
    if not args.scan and not args.printer:
        parser().error("ต้องระบุ --scan หรือ --printer อย่างน้อยหนึ่งรายการ")

    db = connect(args.db)
    try:
        for code in args.scan:
            result = inspect_scan(db, code)
            print(
                f"SCAN PASS [{result.kind}] {result.code} -> {result.name} "
                f"qty={result.qty:g} unit_price={result.unit_price:,.2f}"
            )

        if args.printer:
            queues = installed_printer_names()
            if args.printer not in queues:
                raise RuntimeError(
                    f"ไม่พบ Windows printer queue: {args.printer} "
                    f"(ที่พบ: {', '.join(queues) or 'ไม่มี'} )"
                )
            print(f"PRINTER PASS queue={args.printer} paper={args.paper_width}mm")
            if args.print:
                print_text_to_windows_queue(
                    "PopCentral POS\nHARDWARE UAT\nเครื่องพิมพ์ทำงานปกติ\n",
                    args.printer,
                    paper_width_mm=args.paper_width,
                )
                print("PRINT PASS ส่งงานเข้า Windows spooler แล้ว")
            else:
                print("PRINT SKIP ยังไม่ได้ส่งงานจริง (เพิ่ม --print เมื่อต้องการทดสอบ)")
    finally:
        db.close()
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (RuntimeError, ValueError) as error:
        print(f"FAIL: {error}", file=sys.stderr)
        raise SystemExit(1)
