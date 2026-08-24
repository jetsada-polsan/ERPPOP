#define MyAppName "POPSTAR Python POS (UAT)"
#ifndef MyAppVersion
  #define MyAppVersion "0.1.0"
#endif

[Setup]
AppId={{B9FC8338-8F8C-41B3-A018-FE50E6EFC428}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher=POPSTAR FOOD
DefaultDirName={autopf}\POPSTAR Python POS
DefaultGroupName=POPSTAR Python POS
UninstallDisplayName={#MyAppName}
OutputDir=..\dist-installer
OutputBaseFilename=POPSTAR-Python-POS-UAT-{#MyAppVersion}-setup
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
PrivilegesRequired=lowest

[Files]
Source: "..\dist\POPSTAR-Python-POS\*"; DestDir: "{app}"; Flags: recursesubdirs ignoreversion

[Icons]
Name: "{autoprograms}\POPSTAR Python POS (UAT)"; Filename: "{app}\POPSTAR-Python-POS.exe"
Name: "{autodesktop}\POPSTAR Python POS (UAT)"; Filename: "{app}\POPSTAR-Python-POS.exe"; Tasks: desktopicon

[Tasks]
Name: "desktopicon"; Description: "สร้างทางลัดบน Desktop"; GroupDescription: "ทางลัดเพิ่มเติม:"

[Run]
Filename: "{app}\POPSTAR-Python-POS.exe"; Description: "เปิด POPSTAR Python POS (UAT)"; Flags: nowait postinstall skipifsilent
