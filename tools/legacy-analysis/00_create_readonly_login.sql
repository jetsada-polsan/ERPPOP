/*
    สร้าง login อ่านอย่างเดียวสำหรับงานวิเคราะห์ฐาน BPlus
    =====================================================
    ⚠️  ไฟล์นี้เป็นคำสั่งเขียน — Claude ไม่รันให้ และรันผ่าน mssql_readonly.php ไม่ได้
        เจ้าของรันเองใน SSMS บนเครื่อง POPSTAR-BPLUS เท่านั้น

    ทำไมต้องมี: บัญชี `jetsada` ที่ใช้อยู่เป็น sysadmin + dbcreator + db_owner
    + db_datawriter + db_ddladmin (ตรวจจริงเมื่อ 2026-08-23) ลบฐาน แก้ข้อมูล
    ปิดเซิร์ฟเวอร์ได้ทั้ง instance — ไม่ใช่บัญชีที่ควรใช้อ่านรายงาน

    เป้าหมาย: BPLUSERP_POPSTAR_2021 (database_id 5, ONLINE, compatibility_level 100)
    ยืนยันจาก sys.databases เมื่อ 2026-08-23 — บนเครื่องนี้มีสองฐานคือตัวนี้กับ _BK
*/

/* ---------- 1. สร้าง login ระดับเซิร์ฟเวอร์ ---------- */
USE [master];
GO

-- ตั้งรหัสผ่านเอง ห้ามใช้รหัสเดียวกับ jetsada และห้ามพิมพ์ลงแชตหรือ commit
-- DEFAULT_DATABASE ชี้ไปฐานเป้าหมาย login นี้จึงไม่ต้องมีสิทธิ์อะไรใน master
CREATE LOGIN [erp_readonly]
    WITH PASSWORD = N'<ตั้งรหัสผ่านตรงนี้>',
         DEFAULT_DATABASE = [BPLUSERP_POPSTAR_2021],
         CHECK_POLICY = ON;
GO

-- ไม่เพิ่มเข้า server role ใดทั้งสิ้น เหลือแค่ public ตามค่าเริ่มต้น
-- และไม่ให้ VIEW ANY DATABASE เพื่อไม่ให้เห็นฐานอื่นนอกเป้าหมาย

/* ---------- 2. ให้สิทธิ์เฉพาะในฐานเป้าหมายฐานเดียว ---------- */
USE [BPLUSERP_POPSTAR_2021];
GO

CREATE USER [erp_readonly] FOR LOGIN [erp_readonly];
GO

-- อ่านข้อมูลได้อย่างเดียว
ALTER ROLE [db_datareader] ADD MEMBER [erp_readonly];
GO

-- จำเป็นสำหรับอ่านโค้ดของ trigger, view และ stored procedure
-- ถ้าไม่มีสิทธิ์นี้ sys.sql_modules.definition จะคืนค่า NULL ทั้งหมด
GRANT VIEW DEFINITION TO [erp_readonly];
GO

/* ---------- 3. ปฏิเสธสิทธิ์เขียนอย่างชัดเจน (ชั้นที่สอง) ---------- */
-- DENY ชนะ GRANT เสมอ ต่อให้ภายหลังมีใครเผลอเพิ่ม role ให้ ก็ยังเขียนไม่ได้
DENY INSERT, UPDATE, DELETE, ALTER, CONTROL, REFERENCES TO [erp_readonly];
GO

-- ปฏิเสธการเรียก stored procedure และ function ของ BPlus
-- จำกัดที่ schema dbo เท่านั้น ไม่แตะ schema sys เพราะ query ตรวจสิทธิ์ยังต้องใช้
DENY EXECUTE ON SCHEMA::[dbo] TO [erp_readonly];
GO

/* ---------- 4. ตรวจผลลัพธ์ (SELECT ล้วน ไม่ใช้ EXECUTE AS) ---------- */

-- 4a. server role ที่ติดมา — ต้องได้เฉพาะ public
SELECT
    sp.name AS login_name,
    sp.type_desc,
    sp.default_database_name,
    sp.is_disabled,
    ISNULL(role.name, 'public เท่านั้น') AS server_role
FROM sys.server_principals sp
LEFT JOIN sys.server_role_members srm ON srm.member_principal_id = sp.principal_id
LEFT JOIN sys.server_principals role ON role.principal_id = srm.role_principal_id
WHERE sp.name = 'erp_readonly';

-- 4b. database role ที่ติดมา — ต้องได้เฉพาะ db_datareader
SELECT
    dp.name AS database_user,
    r.name AS database_role
FROM sys.database_principals dp
LEFT JOIN sys.database_role_members drm ON drm.member_principal_id = dp.principal_id
LEFT JOIN sys.database_principals r ON r.principal_id = drm.role_principal_id
WHERE dp.name = 'erp_readonly';

-- 4c. สิทธิ์ที่ให้และที่ปฏิเสธไว้อย่างชัดเจน
--     ต้องเห็น VIEW DEFINITION = GRANT และกลุ่มคำสั่งเขียน = DENY
SELECT
    perm.permission_name,
    perm.state_desc,
    perm.class_desc,
    ISNULL(SCHEMA_NAME(perm.major_id), '(ทั้งฐานข้อมูล)') AS scope
FROM sys.database_permissions perm
JOIN sys.database_principals dp ON dp.principal_id = perm.grantee_principal_id
WHERE dp.name = 'erp_readonly'
ORDER BY perm.state_desc, perm.permission_name;

-- 4d. สรุปแบบอ่านง่าย — ทุกบรรทัดต้องขึ้น OK
SELECT 'sysadmin'      AS check_item, CASE WHEN IS_SRVROLEMEMBER('sysadmin', 'erp_readonly')      = 1 THEN 'ผิด: ติดมา' ELSE 'OK: ไม่มี' END AS result
UNION ALL SELECT 'dbcreator',         CASE WHEN IS_SRVROLEMEMBER('dbcreator', 'erp_readonly')     = 1 THEN 'ผิด: ติดมา' ELSE 'OK: ไม่มี' END
UNION ALL SELECT 'securityadmin',     CASE WHEN IS_SRVROLEMEMBER('securityadmin', 'erp_readonly') = 1 THEN 'ผิด: ติดมา' ELSE 'OK: ไม่มี' END
UNION ALL SELECT 'db_owner',          CASE WHEN IS_ROLEMEMBER('db_owner', 'erp_readonly')         = 1 THEN 'ผิด: ติดมา' ELSE 'OK: ไม่มี' END
UNION ALL SELECT 'db_datawriter',     CASE WHEN IS_ROLEMEMBER('db_datawriter', 'erp_readonly')    = 1 THEN 'ผิด: ติดมา' ELSE 'OK: ไม่มี' END
UNION ALL SELECT 'db_ddladmin',       CASE WHEN IS_ROLEMEMBER('db_ddladmin', 'erp_readonly')      = 1 THEN 'ผิด: ติดมา' ELSE 'OK: ไม่มี' END
UNION ALL SELECT 'db_securityadmin',  CASE WHEN IS_ROLEMEMBER('db_securityadmin', 'erp_readonly') = 1 THEN 'ผิด: ติดมา' ELSE 'OK: ไม่มี' END
UNION ALL SELECT 'db_datareader',     CASE WHEN IS_ROLEMEMBER('db_datareader', 'erp_readonly')    = 1 THEN 'OK: มี' ELSE 'ผิด: ไม่มี' END;
GO

/*
    หลังรันเสร็จและผลตรวจข้อ 4 ผ่านครบ:

      security delete-generic-password -a jetsada -s erppop-legacy-mssql
      security add-generic-password    -a erp_readonly -s erppop-legacy-mssql -w

    แล้วแก้ KEYCHAIN_ACCOUNT ใน tools/legacy-analysis/mssql_readonly.php
    จาก 'jetsada' เป็น 'erp_readonly'
*/
