use keyring::Entry;

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

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    tauri::Builder::default()
        .plugin(tauri_plugin_http::init())
        .plugin(tauri_plugin_sql::Builder::default().build())
        .plugin(tauri_plugin_updater::Builder::new().build())
        .invoke_handler(tauri::generate_handler![save_device_token, read_device_token])
        .run(tauri::generate_context!())
        .expect("failed to run POPSTAR POS");
}
