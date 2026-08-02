import { readFile, writeFile } from 'node:fs/promises'

const version = process.env.VERSION?.trim()
const publicKey = process.env.UPDATER_PUBLIC_KEY?.trim()

if (!/^\d+\.\d+\.\d+$/.test(version || '')) {
  throw new Error('VERSION must use semantic version format, for example 1.0.1')
}
if (!publicKey) {
  throw new Error('TAURI_UPDATER_PUBLIC_KEY is required')
}

const path = 'src-tauri/tauri.conf.json'
const config = JSON.parse(await readFile(path, 'utf8'))
config.version = version
config.plugins.updater.pubkey = publicKey
await writeFile(path, `${JSON.stringify(config, null, 2)}\n`)

const packagePath = 'package.json'
const packageJson = JSON.parse(await readFile(packagePath, 'utf8'))
packageJson.version = version
await writeFile(packagePath, `${JSON.stringify(packageJson, null, 2)}\n`)

const cargoPath = 'src-tauri/Cargo.toml'
const cargo = await readFile(cargoPath, 'utf8')
const updatedCargo = cargo.replace(/^(version\s*=\s*")[^"]+("\s*)$/m, `$1${version}$2`)
if (updatedCargo === cargo) {
  throw new Error('Unable to update the package version in src-tauri/Cargo.toml')
}
await writeFile(cargoPath, updatedCargo)
