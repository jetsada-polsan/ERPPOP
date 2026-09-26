"""ค่าตั้งต่อเครื่อง POS — ที่อยู่ ERP กับ device token

อ่านจากไฟล์ pos-config.json ในโฟลเดอร์ข้อมูล (นอกโฟลเดอร์ติดตั้ง จะได้ไม่ถูกทับ
ตอนอัปเดตโปรแกรม) หรือจาก env สำหรับตอนทดสอบ ไม่มีค่าครบ = ยังไม่ผูกเครื่องกับ ERP
โปรแกรมจะรันโหมด demo/offline ต่อได้ ไม่ล้ม
"""
from __future__ import annotations

import json
import os
from dataclasses import dataclass
from pathlib import Path
from urllib.parse import urlsplit, urlunsplit

CONFIG_FILENAME = "pos-config.json"
PUBLIC_HTTPS_HOSTS = {"erp.popstarcenter.com"}


def normalize_server_url(value: str) -> str:
    """Upgrade the public ERP hostname while preserving explicit private HTTP URLs."""
    # Config files can be copied from a browser or an older installer. Remove
    # a BOM as well as surrounding whitespace before urlsplit sees the value.
    raw = str(value or "").replace("\ufeff", "").strip().rstrip("/")
    if not raw:
        return raw
    parts = urlsplit(raw)
    host = (parts.hostname or "").lower()
    if host in PUBLIC_HTTPS_HOSTS:
        # Canonicalize the public endpoint so copied URL casing cannot change
        # the security check or request target.
        netloc = host
        try:
            port = parts.port
        except ValueError:
            port = None
        if port:
            netloc = f"{host}:{port}"
        return urlunsplit(("https", netloc, parts.path, parts.query, parts.fragment)).rstrip("/")
    return raw


@dataclass(frozen=True)
class DeviceConfig:
    server_url: str
    device_token: str
    allow_insecure: bool = False


def load_device_config(data_dir: Path) -> DeviceConfig | None:
    """คืนค่าตั้งเครื่องถ้าผูกกับ ERP แล้ว ไม่งั้น None (โปรแกรมรัน offline/demo ต่อได้)"""
    server = os.environ.get("POS_SERVER_URL")
    token = os.environ.get("POS_DEVICE_TOKEN")
    insecure = os.environ.get("POS_ALLOW_INSECURE") == "1"

    path = Path(data_dir) / CONFIG_FILENAME
    if (not server or not token) and path.is_file():
        try:
            raw = json.loads(path.read_text(encoding="utf-8"))
        except (ValueError, OSError):
            raw = {}
        server = server or raw.get("server_url")
        token = token or raw.get("device_token")
        insecure = insecure or bool(raw.get("allow_insecure"))

    if not server or not token:
        return None
    return DeviceConfig(
        server_url=normalize_server_url(str(server)),
        device_token=str(token),
        allow_insecure=bool(insecure),
    )


def save_device_config(data_dir: Path, config: DeviceConfig) -> None:
    path = Path(data_dir) / CONFIG_FILENAME
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps({
        "server_url": normalize_server_url(config.server_url),
        "device_token": config.device_token,
        "allow_insecure": config.allow_insecure,
    }, ensure_ascii=False, indent=2), encoding="utf-8")
