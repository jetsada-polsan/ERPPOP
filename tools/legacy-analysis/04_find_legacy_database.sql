/*
    ขั้นที่ 1 ของการตรวจ SQL Server ระบบเดิม — หาว่าฐานไหนคือ POPSTAR/BPlus ตัวจริง
    รันผ่าน tools/legacy-analysis/mssql_readonly.php ซึ่งบังคับ SELECT อย่างเดียว
    และตั้ง LOCK_TIMEOUT / DEADLOCK_PRIORITY LOW / READ UNCOMMITTED ให้เองทุกครั้ง
*/

-- ยืนยันปลายทางก่อนเสมอ
SELECT
    @@SERVERNAME AS server_name,
    DB_NAME() AS current_database,
    CAST(SERVERPROPERTY('ProductVersion') AS nvarchar(64)) AS product_version,
    CAST(SERVERPROPERTY('Edition') AS nvarchar(64)) AS edition,
    SUSER_SNAME() AS logged_in_as;

-- ฐานทั้งหมดบนเครื่องนี้ พร้อมสถานะและว่าเป็น read-only อยู่แล้วหรือไม่
SELECT
    d.name AS database_name,
    d.database_id,
    d.create_date,
    d.state_desc,
    d.is_read_only,
    d.recovery_model_desc,
    d.compatibility_level,
    CAST(SUM(mf.size) * 8.0 / 1024 AS decimal(18, 2)) AS size_mb
FROM sys.databases d
LEFT JOIN sys.master_files mf ON mf.database_id = d.database_id
WHERE d.database_id > 4          -- ข้าม master/tempdb/model/msdb
GROUP BY d.name, d.database_id, d.create_date, d.state_desc,
         d.is_read_only, d.recovery_model_desc, d.compatibility_level
ORDER BY size_mb DESC;
