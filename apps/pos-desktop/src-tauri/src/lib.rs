use keyring::Entry;
use std::fs;
use std::path::PathBuf;
use std::time::{SystemTime, UNIX_EPOCH};
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
    Ok(app.path().app_data_dir().map_err(|error| error.to_string())?.join("popstar-pos.db"))
}

fn backup_directory(app: &tauri::AppHandle) -> Result<PathBuf, String> {
    let directory = app.path().app_data_dir().map_err(|error| error.to_string())?.join("backups");
    fs::create_dir_all(&directory).map_err(|error| error.to_string())?;
    Ok(directory)
}

fn stamp() -> Result<u128, String> {
    Ok(SystemTime::now().duration_since(UNIX_EPOCH).map_err(|error| error.to_string())?.as_millis())
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
        .invoke_handler(tauri::generate_handler![save_device_token, read_device_token, backup_local_database, restore_latest_database])
        .run(tauri::generate_context!())
        .expect("failed to run POPSTAR POS");
}
