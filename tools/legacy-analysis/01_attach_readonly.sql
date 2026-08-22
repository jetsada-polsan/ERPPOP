/*
    แนบฐาน BPlus สำเนาเพื่อวิเคราะห์ แล้วล็อกเป็นอ่านอย่างเดียวทันที
    -------------------------------------------------------------------
    รันบนเครื่อง SQL Server "สำหรับวิเคราะห์แยก" เท่านั้น
    ห้ามรันบน 192.168.88.200 (production เดิม) และห้ามแนบไฟล์ต้นฉบับ

    ก่อนรัน:
      1. แตก ZIP แล้วคัดลอกเฉพาะสองไฟล์นี้ไปยังเครื่องวิเคราะห์
           BusinessData\Data\BPLUSERP_POPSTAR_2021.mdf      (3.87 GB)
           BusinessData\Data\BPLUSERP_POPSTAR_2021_log.ldf  (35 MB)
      2. ตรวจเวอร์ชันปลายทาง — ไฟล์นี้เขียนล่าสุดด้วย SQL Server 2017 (internal 869)
         ปลายทางต้องเป็น 2017 ขึ้นไป มิฉะนั้น attach ไม่ผ่าน
           SELECT @@VERSION;
           SELECT SERVERPROPERTY('ProductMajorVersion');   -- ต้อง >= 14
*/

-- ตรวจเวอร์ชันก่อน (14 = 2017, 15 = 2019, 16 = 2022)
SELECT
    SERVERPROPERTY('ProductVersion')      AS product_version,
    SERVERPROPERTY('ProductMajorVersion') AS major_version,
    SERVERPROPERTY('Edition')             AS edition,
    CASE WHEN CAST(SERVERPROPERTY('ProductMajorVersion') AS int) >= 14
         THEN 'OK - แนบได้'
         ELSE 'หยุด - เครื่องนี้เก่ากว่าไฟล์ฐานข้อมูล (ต้อง 2017 ขึ้นไป)'
    END AS attach_check;
GO

CREATE DATABASE [LEGACY_ANALYSIS_POPSTAR_2021]
ON
    (FILENAME = 'D:\LegacyAnalysis\BPLUSERP_POPSTAR_2021.mdf'),
    (FILENAME = 'D:\LegacyAnalysis\BPLUSERP_POPSTAR_2021_log.ldf')
FOR ATTACH;
GO

-- ล็อกอ่านอย่างเดียวทันทีหลังแนบ ก่อนทำอะไรต่อ
ALTER DATABASE [LEGACY_ANALYSIS_POPSTAR_2021] SET READ_ONLY WITH ROLLBACK IMMEDIATE;
GO

-- ยืนยันว่าล็อกแล้วจริง ต้องได้ READ_ONLY
SELECT name, state_desc, is_read_only, recovery_model_desc, compatibility_level
FROM sys.databases
WHERE name = 'LEGACY_ANALYSIS_POPSTAR_2021';
GO
