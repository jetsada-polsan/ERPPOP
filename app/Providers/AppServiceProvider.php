<?php

namespace App\Providers;

use App\Models\Document;
use App\Models\GlJournal;
use App\Observers\DocumentObserver;
use App\Observers\GlJournalObserver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Document::observe(DocumentObserver::class);
        GlJournal::observe(GlJournalObserver::class);

        Carbon::setLocale('th');

        // วันที่ไทยแบบย่อ: "2 ก.ค. 2569" หรือ "2 ก.ค. 2569 14:30" — ใช้แสดงผล
        // ทุกหน้าแทน format('d/m/Y') ที่เป็น ค.ศ.
        $thaiDate = function (bool $withTime = false): string {
            $months = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
            $out = $this->day.' '.$months[$this->month - 1].' '.($this->year + 543);

            return $withTime ? $out.' '.$this->format('H:i') : $out;
        };

        // วันที่ไทยเต็ม: "2 กรกฎาคม 2569" — ใช้ในเอกสารพิมพ์
        $thaiDateFull = function (): string {
            $months = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];

            return $this->day.' '.$months[$this->month - 1].' '.($this->year + 543);
        };

        Carbon::macro('thaiDate', $thaiDate);
        Carbon::macro('thaiDateFull', $thaiDateFull);
        CarbonImmutable::macro('thaiDate', $thaiDate);
        CarbonImmutable::macro('thaiDateFull', $thaiDateFull);
    }
}
