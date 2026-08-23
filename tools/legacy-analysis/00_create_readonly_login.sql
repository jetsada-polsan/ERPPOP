/*
    สร้าง login สำหรับอ่านอย่างเดียวบน SQL Server ระบบเดิม
    ------------------------------------------------------
    ⚠️  ไฟล์นี้เป็นคำสั่ง "เขียน" — Claude ไม่รันให้ และรันผ่าน mssql_readonly.php ไม่ได้
        เจ้าของต้องรันเองใน SSMS หรือ sqlcmd บนเครื่อง POPSTAR-BPLUS

    ทำไมต้องมี: บัญชี `jetsada` ที่ใช้อยู่เป็น sysadmin เต็มตัว (ตรวจเมื่อ 2026-08-23)
    ลบฐาน สร้างฐาน แก้ข้อมูล ปิดเซิร์ฟเวอร์ ทำได้หมด การเอาบัญชีระดับนั้นมาใช้
    อ่านรายงานคือความเสี่ยงที่ไม่จำเป็น ต่อให้เครื่องมือฝั่งเราจะกันคำสั่งเขียนไว้แล้วก็ตาม

    หลังสร้างเสร็จ ให้เก็บรหัสของ login ใหม่เข้า Keychain แทนของเดิม:
        security delete-generic-password -a jetsada -s erppop-legacy-mssql
        security add-generic-password -a erp_readonly -s erppop-legacy-mssql -w
    แล้วแก้ KEYCHAIN_ACCOUNT ใน mssql_readonly.php เป็น 'erp_readonly'
*/

USE [master];
GO

-- ตั้งรหัสผ่านเอง อย่าพิมพ์ลงแชตหรือ commit
CREATE LOGIN [erp_readonly] WITH PASSWORD = N'<ตั้งรหัสผ่านตรงนี้>', CHECK_POLICY = ON;
GO

USE [BPLUSERP_POPSTAR_2021];   -- เปลี่ยนเป็นชื่อฐานจริงถ้าไม่ตรง
GO

CREATE USER [erp_readonly] FOR LOGIN [erp_readonly];
ALTER ROLE [db_datareader] ADD MEMBER [erp_readonly];

-- จำเป็นสำหรับอ่านโค้ดของ trigger, view และ stored procedure
-- (sys.sql_modules.definition จะเป็น NULL ถ้าไม่มีสิทธิ์นี้)
GRANT VIEW DEFINITION TO [erp_readonly];
GO

-- ตรวจว่าได้สิทธิ์ที่ต้องการจริงและไม่ได้เกินกว่านั้น
EXECUTE AS LOGIN = 'erp_readonly';
SELECT
    SUSER_SNAME() AS login_name,
    IS_SRVROLEMEMBER('sysadmin') AS is_sysadmin,        -- ต้องได้ 0
    IS_ROLEMEMBER('db_owner') AS is_db_owner,           -- ต้องได้ 0
    IS_ROLEMEMBER('db_datawriter') AS is_db_datawriter, -- ต้องได้ 0
    IS_ROLEMEMBER('db_datareader') AS is_db_datareader; -- ต้องได้ 1
REVERT;
GO
