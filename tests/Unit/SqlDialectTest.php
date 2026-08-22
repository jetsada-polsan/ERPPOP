<?php

namespace Tests\Unit;

use App\Support\SqlDialect;
use PHPUnit\Framework\TestCase;

/**
 * ตรึงไวยากรณ์ทั้งสองสาย — สาย pgsql คือสิ่งที่ production รันจริงแต่ชุดเทสต์เป็น SQLite
 * ถ้าไม่ตรึงไว้ตรงนี้ จะไม่มีอะไรจับได้เลยเวลาแก้แล้วสาย production พัง
 */
class SqlDialectTest extends TestCase
{
    public function test_date_minus_days_matches_each_dialect(): void
    {
        $this->assertSame('current_date', SqlDialect::dateMinusDays('pgsql', 0));
        $this->assertSame("current_date - interval '30 days'", SqlDialect::dateMinusDays('pgsql', 30));
        $this->assertSame("current_date - interval '90 days'", SqlDialect::dateMinusDays('pgsql', 90));

        $this->assertSame("date('now')", SqlDialect::dateMinusDays('sqlite', 0));
        $this->assertSame("date('now', '-30 day')", SqlDialect::dateMinusDays('sqlite', 30));
    }

    public function test_hour_truncation_matches_each_dialect(): void
    {
        $this->assertSame(
            "to_char(date_trunc('hour', r.receipt_date), 'YYYY-MM-DD HH24:00')",
            SqlDialect::truncateToHour('pgsql', 'r.receipt_date'),
        );
        $this->assertSame(
            "strftime('%Y-%m-%d %H:00', r.receipt_date)",
            SqlDialect::truncateToHour('sqlite', 'r.receipt_date'),
        );
    }
}
