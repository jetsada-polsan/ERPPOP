<?php

namespace App\Services\Etl;

use App\Services\Mssql\InteractsWithMssql;

/**
 * Read-only fetches of BPlus MSSQL master data tables, for MasterDataEtlService to
 * upsert into PostgreSQL. One method per source table/entity; each returns the full
 * table (these are all small-to-medium reference tables, safe to pull in one shot).
 */
class MssqlMasterDataSourceService
{
    use InteractsWithMssql;

    /** @return array<int, array<string, mixed>> */
    public function fetchBranches(): array
    {
        // BR_KEY = 0 is the pseudo-row "ทุกสาขา" (All Branches), not a real branch.
        return $this->fetchAll('SELECT * FROM BRANCH WHERE BR_KEY <> 0 ORDER BY BR_KEY');
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchProductCategories(): array
    {
        return $this->fetchAll('SELECT * FROM ICCAT ORDER BY ICCAT_KEY');
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchProductDepartments(): array
    {
        return $this->fetchAll('SELECT * FROM ICDEPT ORDER BY ICDEPT_KEY');
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchProductBrands(): array
    {
        return $this->fetchAll('SELECT * FROM BRAND ORDER BY BRN_KEY');
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchProductUnits(): array
    {
        return $this->fetchAll('SELECT * FROM UOFQTY ORDER BY UTQ_KEY');
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchProducts(): array
    {
        $sql = 'SELECT sm.*, cat.ICCAT_CODE, dept.ICDEPT_CODE, brn.BRN_CODE
                FROM SKUMASTER sm
                LEFT JOIN ICCAT cat ON cat.ICCAT_KEY = sm.SKU_ICCAT
                LEFT JOIN ICDEPT dept ON dept.ICDEPT_KEY = sm.SKU_ICDEPT
                LEFT JOIN BRAND brn ON brn.BRN_KEY = sm.SKU_BRN
                ORDER BY sm.SKU_KEY';

        return $this->fetchAll($sql);
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchProductBarcodes(): array
    {
        $sql = 'SELECT gm.*, sm.SKU_CODE
                FROM GOODSMASTER gm
                JOIN SKUMASTER sm ON sm.SKU_KEY = gm.GOODS_SKU
                ORDER BY gm.GOODS_KEY';

        return $this->fetchAll($sql);
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchWarehouses(): array
    {
        return $this->fetchAll('SELECT * FROM WAREHOUSE ORDER BY WH_KEY');
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchWarehouseLocations(): array
    {
        $sql = 'SELECT wl.*, wh.WH_CODE
                FROM WARELOCATION wl
                JOIN WAREHOUSE wh ON wh.WH_KEY = wl.WL_WH
                ORDER BY wl.WL_KEY';

        return $this->fetchAll($sql);
    }

    /**
     * Consolidated on-hand quantity per SKU/location (SKUBALANCE tracks lot/serial
     * detail we don't model yet, so this sums to one row per SKU+location).
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchStockBalances(): array
    {
        $sql = "SELECT sm.SKU_CODE, wl.WL_CODE, wh.WH_CODE, SUM(skb.SKB_QTY) AS TOTAL_QTY
                FROM SKUBALANCE skb
                JOIN SKUMASTER sm ON sm.SKU_KEY = skb.SKB_SKU
                JOIN WARELOCATION wl ON wl.WL_KEY = skb.SKB_WL
                JOIN WAREHOUSE wh ON wh.WH_KEY = wl.WL_WH
                GROUP BY sm.SKU_CODE, wl.WL_CODE, wh.WH_CODE";

        return $this->fetchAll($sql);
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchCustomers(): array
    {
        return $this->fetchAll('SELECT * FROM ARFILE ORDER BY AR_KEY');
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchCustomerAddresses(): array
    {
        $sql = "SELECT ara.*, ar.AR_CODE, addb.ADDB_ADDB_1, addb.ADDB_ADDB_2, addb.ADDB_ADDB_3,
                       addb.ADDB_PROVINCE, addb.ADDB_POST, addb.ADDB_PHONE, addb.ADDB_EMAIL
                FROM ARADDRESS ara
                JOIN ARFILE ar ON ar.AR_KEY = ara.ARA_AR
                JOIN ADDRBOOK addb ON addb.ADDB_KEY = ara.ARA_ADDB
                ORDER BY ara.ARA_KEY";

        return $this->fetchAll($sql);
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchCustomerContacts(): array
    {
        $sql = 'SELECT arc.*, ar.AR_CODE, ct.CT_NAME, ct.CT_SURNME, ct.CT_MOBILE, ct.CT_EMAIL
                FROM ARCONTACT arc
                JOIN ARFILE ar ON ar.AR_KEY = arc.ARC_AR
                JOIN CONTACT ct ON ct.CT_KEY = arc.ARC_CT
                ORDER BY arc.ARC_KEY';

        return $this->fetchAll($sql);
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchSuppliers(): array
    {
        return $this->fetchAll('SELECT * FROM APFILE ORDER BY AP_KEY');
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchSupplierAddresses(): array
    {
        $sql = "SELECT apa.*, ap.AP_CODE, addb.ADDB_ADDB_1, addb.ADDB_ADDB_2, addb.ADDB_ADDB_3,
                       addb.ADDB_PROVINCE, addb.ADDB_POST, addb.ADDB_PHONE, addb.ADDB_EMAIL
                FROM APADDRESS apa
                JOIN APFILE ap ON ap.AP_KEY = apa.APA_AP
                JOIN ADDRBOOK addb ON addb.ADDB_KEY = apa.APA_ADDB
                ORDER BY apa.APA_KEY";

        return $this->fetchAll($sql);
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchSalesmen(): array
    {
        return $this->fetchAll('SELECT * FROM SALESMAN ORDER BY SLMN_KEY');
    }
}
