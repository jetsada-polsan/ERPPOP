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
    CASE WHEN DATABASEPROPERTYEX(DB_NAME(), 'Updateability') = 'READ_ONLY'
         THEN 'OK - database is read only'
         ELSE 'STOP - set the database READ_ONLY before collecting anything'
    END AS read_only_check,
    GETDATE() AS collected_at;

-- 1. All user tables with row counts, storage size and a business/system/archive split.
--
--    Row count is taken from the heap or clustered index only, and is read as a
--    GROUP BY key rather than summed. sys.allocation_units yields one row per
--    allocation type (IN_ROW_DATA, LOB_DATA, ROW_OVERFLOW_DATA), so summing
--    p.rows across that join multiplies the count for every table that holds a
--    LOB column - REPORTFILE.RPF_SQL among them. The size still sums, because
--    each allocation unit contributes real pages.
SELECT
    s.name AS schema_name,
    t.name AS table_name,
    p.rows AS row_count,
    CAST(SUM(a.total_pages) * 8.0 / 1024 AS decimal(18, 2)) AS total_mb,
    CASE
        WHEN t.name LIKE 'BPLUS%'
          OR t.name LIKE 'AUTO%'
          OR t.name IN ('BYDATANAME', 'CREDITS')
            THEN 'system'
        -- physical monthly tables such as C260701 / D260701
        WHEN t.name LIKE '[CDHLPS][0-9][0-9][0-9][0-9][0-9][0-9]'
            THEN 'archive'
        ELSE 'business'
    END AS table_class
FROM sys.tables t
JOIN sys.schemas s ON s.schema_id = t.schema_id
JOIN sys.indexes i ON i.object_id = t.object_id AND i.index_id IN (0, 1)
JOIN sys.partitions p ON p.object_id = t.object_id AND p.index_id = i.index_id
LEFT JOIN sys.allocation_units a ON a.container_id = p.partition_id
WHERE t.is_ms_shipped = 0
GROUP BY s.name, t.name, p.rows
ORDER BY p.rows DESC, total_mb DESC, s.name, t.name;

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

-- 8. Indexes: shows how the legacy reports were expected to read each table.
SELECT
    s.name AS schema_name,
    t.name AS table_name,
    i.name AS index_name,
    i.type_desc,
    i.is_unique,
    i.is_primary_key,
    STUFF((
        SELECT ', ' + c2.name
        FROM sys.index_columns ic2
        JOIN sys.columns c2
          ON c2.object_id = ic2.object_id AND c2.column_id = ic2.column_id
        WHERE ic2.object_id = i.object_id
          AND ic2.index_id = i.index_id
          AND ic2.is_included_column = 0
        ORDER BY ic2.key_ordinal
        FOR XML PATH('')), 1, 2, '') AS key_columns
FROM sys.indexes i
JOIN sys.tables t ON t.object_id = i.object_id
JOIN sys.schemas s ON s.schema_id = t.schema_id
WHERE t.is_ms_shipped = 0
  AND i.type > 0
ORDER BY s.name, t.name, i.name;

-- 9. Triggers. BPlus keeps posting rules in triggers, so a table-only reading of
--    the schema will map the stock and ledger effects of a document incorrectly.
SELECT
    s.name AS schema_name,
    t.name AS table_name,
    tr.name AS trigger_name,
    tr.is_disabled,
    tr.is_instead_of_trigger,
    m.definition
FROM sys.triggers tr
JOIN sys.tables t ON t.object_id = tr.parent_id
JOIN sys.schemas s ON s.schema_id = t.schema_id
LEFT JOIN sys.sql_modules m ON m.object_id = tr.object_id
ORDER BY s.name, t.name, tr.name;

-- 10. Scalar, inline and table-valued functions (section 6 covers procedures only).
SELECT
    s.name AS schema_name,
    o.name AS function_name,
    o.type_desc,
    o.create_date,
    o.modify_date,
    m.definition
FROM sys.objects o
JOIN sys.schemas s ON s.schema_id = o.schema_id
LEFT JOIN sys.sql_modules m ON m.object_id = o.object_id
WHERE o.is_ms_shipped = 0
  AND o.type IN ('FN', 'IF', 'TF')
ORDER BY o.type_desc, s.name, o.name;

-- 11. Document types. Every BPlus flow is a document type inside DOCINFO, so this
--     is the single most useful result for mapping booking, sale, return, payment
--     and stock movement onto the new ERP.
SELECT * FROM DOCTYPE ORDER BY 1;

-- 12. Document volume per type and year: separates the flows the business really
--     used from the ones that only ever existed as a menu.
SELECT
    d.DI_REF_TYPE,
    YEAR(d.DI_DATE) AS doc_year,
    COUNT(*) AS doc_count
FROM DOCINFO d
GROUP BY d.DI_REF_TYPE, YEAR(d.DI_DATE)
ORDER BY d.DI_REF_TYPE, doc_year;

-- 13. Fallback for section 12. DI_REF_TYPE and DI_DATE were read from the SQL of
--     the legacy reports, not from the database itself. If section 12 fails on a
--     column name, run this and send the result so the column names can be fixed.
SELECT c.name AS column_name, ty.name AS data_type, c.max_length, c.is_nullable
FROM sys.columns c
JOIN sys.types ty ON ty.user_type_id = c.user_type_id
WHERE c.object_id = OBJECT_ID('DOCINFO')
ORDER BY c.column_id;

-- 14. Tables behind each business flow. The table list comes from the FROM and
--     JOIN clauses of the 1,502 legacy report definitions in REPORTFILE, so these
--     are the tables the reports actually read rather than names guessed from a
--     pattern. Anything reported as missing here is a mapping assumption to drop.
WITH expected(flow, table_name) AS (
    SELECT * FROM (VALUES
        ('booking',      'DOCINFO'), ('booking',      'DOCTYPE'), ('booking', 'AROE'),
        ('booking',      'ARCONDITION'), ('booking',  'SHIPBY'),
        ('sales',        'SLDETAIL'), ('sales',       'TRANSTKH'), ('sales', 'TRANSTKD'),
        ('sales',        'VATTABLE'), ('sales',       'ARDETAIL'),
        ('sales_return', 'DOCINFO'), ('sales_return', 'TRANSTKH'),
        ('payable',      'APFILE'), ('payable',       'APADDRESS'), ('payable', 'APCAT'),
        ('payable',      'TRANPAYH'), ('payable',     'TRANPAYD'), ('payable', 'APPRICETAB'),
        ('receivable',   'ARFILE'), ('receivable',    'ARDETAIL'), ('receivable', 'ARCAT'),
        ('receivable',   'ARPAYMENT'), ('receivable', 'PAYMENTTYPE'),
        ('cash_book',    'CASHBOOK'), ('cash_book',   'CASHACCOUNT'),
        ('bank',         'BANKACCOUNT'), ('bank',     'BANKFILE'), ('bank', 'BANKSTATEMENT'),
        ('bank',         'CHEQUEBOOK'), ('bank',      'CHEQUEIN'),
        ('product',      'SKUMASTER'), ('product',    'GOODSMASTER'), ('product', 'UOFQTY'),
        ('product',      'ICCAT'), ('product',        'ICDEPT'), ('product', 'BRAND'),
        ('stock',        'SKUMOVE'), ('stock',        'WAREHOUSE'), ('stock', 'WARELOCATION'),
        ('stock',        'ICCOMMIT'),
        ('price',        'ARPRICETAB'), ('price',     'PRICECHANGE'), ('price', 'PRICETAG'),
        ('price',        'ARPLU'), ('price',          'ARCBUY'),
        ('promotion',    'ARCAMPAIGN'), ('promotion', 'PRMTPLAN'),
        ('salesman',     'SALESMAN'), ('salesman',    'DEPTTAB'), ('salesman', 'PRJTAB'),
        ('org',          'BRANCH'), ('org',           'ADDRBOOK'), ('org', 'ACCOUNTCHART'),
        ('org',          'MISCLOOKUP'), ('org',       'MKTPLAN'),
        ('member',       'MEMBER'), ('member',        'MBPOINT'), ('member', 'MBTYPE')
    ) v(flow, table_name)
)
SELECT
    e.flow,
    e.table_name,
    CASE WHEN t.object_id IS NULL THEN 'missing' ELSE 'present' END AS presence,
    ISNULL(p.rows, 0) AS row_count
FROM expected e
LEFT JOIN sys.tables t ON t.name = e.table_name AND t.is_ms_shipped = 0
LEFT JOIN sys.indexes i ON i.object_id = t.object_id AND i.index_id IN (0, 1)
LEFT JOIN sys.partitions p ON p.object_id = t.object_id AND p.index_id = i.index_id
ORDER BY e.flow, e.table_name;
