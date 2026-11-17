# ติดตั้งซิงค์ BPlus B18 อัตโนมัติบนเครื่องนี้ (รันใน PowerShell แบบ Run as administrator)
# - ไม่มี Python 3.12 ที่ C:\Program Files\Python312 จะดาวน์โหลดจาก python.org (ตรวจลายเซ็น) แล้วติดตั้งให้ทุก user
# - ไม่ใส่รหัสหรือ token ใดๆ: เจ้าของแก้ C:\BplusSync\bplus-sync.ini เอง
$ErrorActionPreference = 'Stop'
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$dest = 'C:\BplusSync'

Start-Transcript -Path (Join-Path $here 'install-log.txt') -Force | Out-Null
$python = 'C:\Program Files\Python312\python.exe'
if (-not (Test-Path $python)) {
    # ดาวน์โหลดจาก python.org แล้วติดตั้งแบบเงียบให้ทุก user (Task รันเป็น SYSTEM)
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    $setup = Join-Path $env:TEMP 'python-3.12.10-amd64.exe'
    Write-Host 'กำลังดาวน์โหลด Python 3.12.10 จาก python.org ...'
    Invoke-WebRequest 'https://www.python.org/ftp/python/3.12.10/python-3.12.10-amd64.exe' -OutFile $setup -UseBasicParsing
    $sig = Get-AuthenticodeSignature $setup
    if ($sig.Status -ne 'Valid' -or $sig.SignerCertificate.Subject -notlike '*Python Software Foundation*') { throw "ลายเซ็นไฟล์ติดตั้ง Python ไม่ถูกต้อง: $($sig.Status)" }
    Write-Host 'กำลังติดตั้ง Python (ประมาณ 1-2 นาที) ...'
    Start-Process $setup -ArgumentList '/quiet', 'InstallAllUsers=1', 'PrependPath=1', 'Include_test=0' -Wait
    if (-not (Test-Path $python)) { throw 'ติดตั้ง Python ไม่สำเร็จ' }
}
Write-Host "Python: $python"
& $python -m pip install --upgrade pymssql
if ($LASTEXITCODE -ne 0) { throw 'pip install pymssql ไม่สำเร็จ' }
& $python -c "import pymssql; print('pymssql', pymssql.__version__)"

New-Item -ItemType Directory -Force -Path $dest | Out-Null
Copy-Item "$here\bplus-route-export.py", "$here\bplus-auto-sync.py", "$here\bplus-sync.ini.example" $dest -Force
$ini = Join-Path $dest 'bplus-sync.ini'
if (-not (Test-Path $ini)) { Copy-Item (Join-Path $dest 'bplus-sync.ini.example') $ini }
# ไฟล์ตั้งค่ามีรหัส: ให้ Administrators กับ SYSTEM อ่านได้เท่านั้น
icacls $ini /inheritance:r /grant:r 'Administrators:F' 'SYSTEM:F' | Out-Null

# ปุ่มกดซิงค์ทันทีบน Desktop ของทุก user
Copy-Item "$here\bplus-sync-now.cmd" $dest -Force
Copy-Item "$here\bplus-sync-now.cmd" 'C:\Users\Public\Desktop\BPlus Sync Now.cmd' -Force

$action = New-ScheduledTaskAction -Execute $python -Argument "`"$dest\bplus-auto-sync.py`"" -WorkingDirectory $dest
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Hours 1) -MultipleInstances IgnoreNew
foreach ($t in '12:00', '15:00', '18:00', '00:00') {
    $name = "BPlus Sync B18 $($t.Replace(':',''))"
    Register-ScheduledTask -TaskName $name -Action $action -Principal $principal -Settings $settings `
        -Trigger (New-ScheduledTaskTrigger -Daily -At $t) -Force | Out-Null
    Write-Host "ตั้งเวลา $name แล้ว"
}
Write-Host ''
Write-Host '--- ตรวจเครื่อง ---'
Write-Host ('BPlus SQL 192.168.88.200:1433 : ' + (Test-NetConnection 192.168.88.200 -Port 1433 -InformationLevel Quiet))
Write-Host ('ERP erp.popstarcenter.com:443 : ' + (Test-NetConnection erp.popstarcenter.com -Port 443 -InformationLevel Quiet))
Write-Host ('Timezone : ' + (tzutil /g))
Write-Host ('เวลาเครื่อง : ' + (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'))
powercfg /getactivescheme
Get-ScheduledTask -TaskName 'BPlus Sync B18*' | Format-Table TaskName, State -AutoSize | Out-String | Write-Host
Write-Host ''
Write-Host 'เสร็จ ขั้นต่อไป (เจ้าของทำเอง):'
Write-Host "  1. notepad $ini  ใส่ user/รหัส SQL ที่อ่านได้อย่างเดียว และ token"
Write-Host "  2. ทดสอบ: & '$python' $dest\bplus-auto-sync.py --dry-run"
Write-Host "  3. ส่งจริง: & '$python' $dest\bplus-auto-sync.py"
Stop-Transcript | Out-Null
