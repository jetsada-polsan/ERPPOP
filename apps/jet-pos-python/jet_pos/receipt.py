from __future__ import annotations

from dataclasses import dataclass
from decimal import Decimal
from html import escape
from typing import Iterable


@dataclass(frozen=True)
class ReceiptLine:
    sku_code: str
    name_th: str
    qty: Decimal
    unit_price: Decimal


def receipt_html(
    receipt_no: str,
    lines: Iterable[ReceiptLine],
    total: Decimal,
    method: str,
    change: Decimal,
    company: dict[str, object] | None = None,
) -> str:
    details = company or {}
    company_name = escape(str(details.get("name") or "JET POS"))
    address = escape(str(details.get("address") or ""))
    payment_label = {
        "cash": "เงินสด", "transfer": "โอน / QR", "credit_card": "บัตรเครดิต", "cheque": "เช็ค", "mixed": "เงินสด + โอน",
    }.get(method, method)
    item_html = "".join(
        f"<tr><td><b>{escape(line.sku_code)}</b><br>{escape(line.name_th)}</td><td class='right'>{line.qty.normalize()} × {line.unit_price:.2f}</td><td class='right'>{line.qty * line.unit_price:.2f}</td></tr>"
        for line in lines
    )
    return f"""<!doctype html><html><head><meta charset='utf-8'><style>
      body{{font-family:'Tahoma','Segoe UI',sans-serif;font-size:10pt;margin:10mm;color:#111}} .center{{text-align:center}} .right{{text-align:right;white-space:nowrap}} table{{border-collapse:collapse;width:100%}} td{{padding:3px 0;border-bottom:1px dotted #bbb;vertical-align:top}} .total{{font-size:16pt;font-weight:700}} .muted{{color:#555}}
      </style></head><body><div class='center'><b>{company_name}</b><br><span class='muted'>{address}</span><br><br><b>ใบเสร็จรับเงิน / ใบกำกับอย่างย่อ</b><br>{escape(receipt_no)}</div><hr>
      <table>{item_html}</table><p class='right'>ชำระโดย: {escape(payment_label)}</p><p class='right total'>รวมสุทธิ ฿{total:.2f}</p><p class='right'>เงินทอน ฿{change:.2f}</p><hr><p class='center muted'>ขอบคุณที่ใช้บริการ</p></body></html>"""
