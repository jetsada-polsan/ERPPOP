use keyring::Entry;
use std::fs;
use std::path::PathBuf;
#[cfg(windows)]
use std::os::windows::process::CommandExt;
#[cfg(windows)]
use std::process::Command;
use std::time::{SystemTime, UNIX_EPOCH};
use serde::Serialize;
use tauri::Manager;

const KEYRING_SERVICE: &str = "th.co.popstar.pos";
const KEYRING_USER: &str = "device-token";

#[tauri::command]
fn save_device_token(token: String) -> Result<(), String> {
    Entry::new(KEYRING_SERVICE, KEYRING_USER)
        .map_err(|error| error.to_string())?
        .set_password(&token)
        .map_err(|error| error.to_string())
}

#[tauri::command]
fn read_device_token() -> Result<String, String> {
    Entry::new(KEYRING_SERVICE, KEYRING_USER)
        .map_err(|error| error.to_string())?
        .get_password()
        .map_err(|error| error.to_string())
}

fn database_path(app: &tauri::AppHandle) -> Result<PathBuf, String> {
    Ok(app.path().app_config_dir().map_err(|error| error.to_string())?.join("popstar-pos.db"))
}

fn backup_directory(app: &tauri::AppHandle) -> Result<PathBuf, String> {
    let directory = app.path().app_config_dir().map_err(|error| error.to_string())?.join("backups");
    fs::create_dir_all(&directory).map_err(|error| error.to_string())?;
    Ok(directory)
}

fn stamp() -> Result<u128, String> {
    Ok(SystemTime::now().duration_since(UNIX_EPOCH).map_err(|error| error.to_string())?.as_millis())
}

#[derive(Serialize)]
struct StorageStatus {
    location: String,
    database_path: String,
    warning: Option<String>,
}

fn d_drive_path() -> PathBuf {
    PathBuf::from(r"D:\POPSTAR-POS\data")
}

#[cfg(windows)]
fn is_directory_link(path: &PathBuf) -> bool {
    fs::symlink_metadata(path).map(|metadata| metadata.file_type().is_symlink()).unwrap_or(false)
}

#[cfg(not(windows))]
fn is_directory_link(_path: &PathBuf) -> bool { false }

#[cfg(windows)]
fn create_directory_junction(link: &PathBuf, target: &PathBuf) -> Result<(), String> {
    let command = format!("mklink /J \"{}\" \"{}\"", link.display(), target.display());
    // mklink มีเฉพาะ cmd แต่ห้ามให้ console ดำเด้งขึ้นบนหน้าร้าน
    let output = Command::new("cmd")
        .args(["/C", &command])
        .creation_flags(0x08000000) // CREATE_NO_WINDOW
        .output()
        .map_err(|error| error.to_string())?;
    if output.status.success() { Ok(()) } else { Err(String::from_utf8_lossy(&output.stderr).trim().to_string()) }
}

#[cfg(not(windows))]
fn create_directory_junction(_link: &PathBuf, _target: &PathBuf) -> Result<(), String> { Ok(()) }

#[tauri::command]
fn prepare_local_storage(app: tauri::AppHandle) -> Result<StorageStatus, String> {
    let app_config = app.path().app_config_dir().map_err(|error| error.to_string())?;
    let database = app_config.join("popstar-pos.db");

    #[cfg(not(windows))]
    return Ok(StorageStatus { location: "app-config".to_string(), database_path: database.to_string_lossy().to_string(), warning: None });

    #[cfg(windows)]
    {
        let target_root = d_drive_path();
        if !PathBuf::from(r"D:\").is_dir() {
            return Ok(StorageStatus { location: "c-fallback".to_string(), database_path: database.to_string_lossy().to_string(), warning: Some("ไม่พบไดรฟ์ D: ฐานข้อมูลยังอยู่ที่ C: และควรติดตั้งไดรฟ์ข้อมูลก่อนเริ่มขาย".to_string()) });
        }
        fs::create_dir_all(&target_root).map_err(|error| format!("สร้างโฟลเดอร์ข้อมูล D: ไม่ได้: {}", error))?;
        if is_directory_link(&app_config) {
            return Ok(StorageStatus { location: "d-drive".to_string(), database_path: target_root.join("popstar-pos.db").to_string_lossy().to_string(), warning: None });
        }

        let target_database = target_root.join("popstar-pos.db");
        if !target_database.is_file() {
            let app_data_database = app.path().app_data_dir().map_err(|error| error.to_string())?.join("popstar-pos.db");
            let legacy = [app_config.join("popstar-pos.db"), app_data_database];
            if let Some(source) = legacy.iter().find(|path| path.is_file()) {
                fs::copy(source, &target_database).map_err(|error| format!("ย้ายฐานข้อมูลเดิมไป D: ไม่ได้: {}", error))?;
            }
        }

        if app_config.exists() {
            let legacy_dir = app_config.with_file_name(format!("th.co.popstar.pos.c-backup-{}", stamp()?));
            fs::rename(&app_config, &legacy_dir).map_err(|error| format!("เก็บโฟลเดอร์ฐานข้อมูลเดิมไว้ไม่ได้: {}", error))?;
        }
        create_directory_junction(&app_config, &target_root)?;
        Ok(StorageStatus { location: "d-drive".to_string(), database_path: target_database.to_string_lossy().to_string(), warning: None })
    }
}

#[tauri::command]
fn backup_local_database(app: tauri::AppHandle) -> Result<String, String> {
    let source = database_path(&app)?;
    if !source.is_file() {
        return Err("ไม่พบฐานข้อมูล POS ในเครื่อง".to_string());
    }
    let target = backup_directory(&app)?.join(format!("pos-backup-{}.db", stamp()?));
    fs::copy(&source, &target).map_err(|error| error.to_string())?;
    Ok(target.to_string_lossy().to_string())
}

#[tauri::command]
fn restore_latest_database(app: tauri::AppHandle) -> Result<String, String> {
    let source = database_path(&app)?;
    let directory = backup_directory(&app)?;
    let mut backups: Vec<PathBuf> = fs::read_dir(&directory)
        .map_err(|error| error.to_string())?
        .filter_map(|entry| entry.ok().map(|item| item.path()))
        .filter(|path| path.file_name().and_then(|name| name.to_str()).map(|name| name.starts_with("pos-backup-") && name.ends_with(".db")).unwrap_or(false))
        .collect();
    backups.sort();
    let latest = backups.pop().ok_or_else(|| "ยังไม่มีไฟล์ Backup POS ให้กู้คืน".to_string())?;
    if source.is_file() {
        let safety = directory.join(format!("pos-pre-restore-{}.db", stamp()?));
        fs::copy(&source, safety).map_err(|error| error.to_string())?;
    }
    fs::copy(&latest, &source).map_err(|error| error.to_string())?;
    Ok(latest.to_string_lossy().to_string())
}

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    tauri::Builder::default()
        .plugin(tauri_plugin_http::init())
        .plugin(tauri_plugin_sql::Builder::default().build())
        .plugin(tauri_plugin_updater::Builder::new().build())
        .invoke_handler(tauri::generate_handler![save_device_token, read_device_token, prepare_local_storage, backup_local_database, restore_latest_database])
        .run(tauri::generate_context!())
        .expect("failed to run POPSTAR POS");
}
