/*
  POPSTAR legacy SQL Server schema inventory

  Run only against an isolated, copied database attached as READ_ONLY.
  This script performs SELECT queries only. It does not create, alter,
  write, or delete any object or data.
*/
SET NOCOUNT ON;

SELECT
    DB_NAME() AS database_name,
    @@SERVERNAME AS server_name,
    CAST(SERVERPROPERTY('ProductVersion') AS nvarchar(128)) AS sql_server_version,
    DATABASEPROPERTYEX(DB_NAME(), 'Status') AS database_status,
    DATABASEPROPERTYEX(DB_NAME(), 'Updateability') AS database_updateability,
    GETDATE() AS collected_at;

-- 1. All user tables with approximate row counts and storage size.
SELECT
    s.name AS schema_name,
    t.name AS table_name,
    SUM(CASE WHEN p.index_id IN (0, 1) THEN p.rows ELSE 0 END) AS row_count,
    CAST(SUM(a.total_pages) * 8.0 / 1024 AS decimal(18, 2)) AS total_mb
FROM sys.tables t
JOIN sys.schemas s ON s.schema_id = t.schema_id
LEFT JOIN sys.partitions p ON p.object_id = t.object_id
LEFT JOIN sys.allocation_units a ON a.container_id = p.partition_id
WHERE t.is_ms_shipped = 0
GROUP BY s.name, t.name
ORDER BY row_count DESC, total_mb DESC, s.name, t.name;

-- 2. Columns, data types, nullability, defaults and identity columns.
SELECT
    s.name AS schema_name,
    t.name AS table_name,
    c.column_id,
    c.name AS column_name,
    ty.name AS data_type,
    c.max_length,
    c.precision,
    c.scale,
    c.is_nullable,
    c.is_identity,
    dc.definition AS default_definition
FROM sys.tables t
JOIN sys.schemas s ON s.schema_id = t.schema_id
JOIN sys.columns c ON c.object_id = t.object_id
JOIN sys.types ty ON ty.user_type_id = c.user_type_id
LEFT JOIN sys.default_constraints dc ON dc.object_id = c.default_object_id
WHERE t.is_ms_shipped = 0
ORDER BY s.name, t.name, c.column_id;

-- 3. Primary keys and unique constraints.
SELECT
    s.name AS schema_name,
    t.name AS table_name,
    kc.name AS constraint_name,
    kc.type_desc AS constraint_type,
    ic.key_ordinal,
    c.name AS column_name
FROM sys.key_constraints kc
JOIN sys.tables t ON t.object_id = kc.parent_object_id
JOIN sys.schemas s ON s.schema_id = t.schema_id
JOIN sys.index_columns ic ON ic.object_id = kc.parent_object_id AND ic.index_id = kc.unique_index_id
JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
WHERE t.is_ms_shipped = 0
ORDER BY s.name, t.name, kc.name, ic.key_ordinal;

-- 4. Foreign keys and their column mappings.
SELECT
    fk.name AS foreign_key_name,
    ps.name AS parent_schema,
    pt.name AS parent_table,
    pc.name AS parent_column,
    rs.name AS referenced_schema,
    rt.name AS referenced_table,
    rc.name AS referenced_column,
    fkc.constraint_column_id,
    fk.delete_referential_action_desc,
    fk.update_referential_action_desc,
    fk.is_disabled
FROM sys.foreign_keys fk
JOIN sys.foreign_key_columns fkc ON fkc.constraint_object_id = fk.object_id
JOIN sys.tables pt ON pt.object_id = fk.parent_object_id
JOIN sys.schemas ps ON ps.schema_id = pt.schema_id
JOIN sys.columns pc ON pc.object_id = pt.object_id AND pc.column_id = fkc.parent_column_id
JOIN sys.tables rt ON rt.object_id = fk.referenced_object_id
JOIN sys.schemas rs ON rs.schema_id = rt.schema_id
JOIN sys.columns rc ON rc.object_id = rt.object_id AND rc.column_id = fkc.referenced_column_id
ORDER BY ps.name, pt.name, fk.name, fkc.constraint_column_id;

-- 5. Views: report-facing objects often live here.
SELECT
    s.name AS schema_name,
    v.name AS view_name,
    v.create_date,
    v.modify_date,
    m.definition
FROM sys.views v
JOIN sys.schemas s ON s.schema_id = v.schema_id
LEFT JOIN sys.sql_modules m ON m.object_id = v.object_id
WHERE v.is_ms_shipped = 0
ORDER BY s.name, v.name;

-- 6. Stored procedures: inspect report and posting logic without executing it.
SELECT
    s.name AS schema_name,
    p.name AS procedure_name,
    p.create_date,
    p.modify_date,
    m.definition
FROM sys.procedures p
JOIN sys.schemas s ON s.schema_id = p.schema_id
LEFT JOIN sys.sql_modules m ON m.object_id = p.object_id
WHERE p.is_ms_shipped = 0
ORDER BY s.name, p.name;

-- 7. Database objects likely relevant to the POPSTAR ERP/POS report mapping.
SELECT
    o.type_desc,
    s.name AS schema_name,
    o.name AS object_name,
    o.create_date,
    o.modify_date
FROM sys.objects o
JOIN sys.schemas s ON s.schema_id = o.schema_id
WHERE o.is_ms_shipped = 0
  AND (
      o.name COLLATE Latin1_General_CI_AI LIKE '%BOOK%'
      OR o.name COLLATE Latin1_General_CI_AI LIKE '%SALE%'
      OR o.name COLLATE Latin1_General_CI_AI LIKE '%CASH%'
      OR o.name COLLATE Latin1_General_CI_AI LIKE '%BANK%'
      OR o.name COLLATE Latin1_General_CI_AI LIKE '%SUP%'
      OR o.name COLLATE Latin1_General_CI_AI LIKE '%AP%'
      OR o.name COLLATE Latin1_General_CI_AI LIKE '%AR%'
      OR o.name COLLATE Latin1_General_CI_AI LIKE '%STOCK%'
      OR o.name COLLATE Latin1_General_CI_AI LIKE '%ITEM%'
      OR o.name COLLATE Latin1_General_CI_AI LIKE '%POS%'
      OR o.name COLLATE Latin1_General_CI_AI LIKE '%POPSTAR%'
  )
ORDER BY o.type_desc, s.name, o.name;
