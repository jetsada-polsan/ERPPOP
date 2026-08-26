#define MyAppName "PopCentral POS (UAT)"
#ifndef MyAppVersion
  #define MyAppVersion "0.1.0"
#endif

[Setup]
AppId={{B9FC8338-8F8C-41B3-A018-FE50E6EFC428}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher=POPSTAR FOOD
DefaultDirName={autopf}\PopCentral POS
DefaultGroupName=PopCentral POS
UninstallDisplayName={#MyAppName}
OutputDir=..\dist-installer
OutputBaseFilename=PopCentral-POS-UAT-{#MyAppVersion}-setup
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
PrivilegesRequired=lowest

[Files]
Source: "..\dist\PopCentral-POS\*"; DestDir: "{app}"; Flags: recursesubdirs ignoreversion

[Icons]
Name: "{autoprograms}\PopCentral POS (UAT)"; Filename: "{app}\PopCentral-POS.exe"
Name: "{autodesktop}\PopCentral POS (UAT)"; Filename: "{app}\PopCentral-POS.exe"; Tasks: desktopicon

[Tasks]
Name: "desktopicon"; Description: "สร้างทางลัดบน Desktop"; GroupDescription: "ทางลัดเพิ่มเติม:"

[Run]
Filename: "{app}\PopCentral-POS.exe"; Description: "เปิด PopCentral POS (UAT)"; Flags: nowait postinstall skipifsilent
