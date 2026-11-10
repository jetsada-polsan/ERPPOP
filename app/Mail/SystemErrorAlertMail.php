<?php

namespace App\Mail;

use App\Models\SystemError;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SystemErrorAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public SystemError $error,
        public string $reason
    ) {}

    public function build(): self
    {
        return $this->subject("[ERP ALERT 5xx] {$this->reason} - {$this->error->exception_class}")
            ->html(nl2br(e(
                "แจ้งเตือนระบบ MPM Food Group ERP\n\n"
                ."เหตุผล: {$this->reason}\n"
                .'เวลา: '.now()->format('Y-m-d H:i:s')."\n"
                ."HTTP Status: {$this->error->status}\n"
                ."Exception: {$this->error->exception_class}\n"
                ."File:Line: {$this->error->file_line}\n"
                .'Route: '.($this->error->route_name ?: 'N/A')."\n"
                ."URL: {$this->error->url}\n"
                .'User ID: '.($this->error->user_id ?: 'Guest / None')."\n"
                ."จำนวนสะสม: {$this->error->count} ครั้ง\n"
                ."ข้อความ: {$this->error->message}\n\n"
                ."---\n"
                .'สามารถตรวจสอบรายละเอียดเพิ่มเติมได้ที่หน้า สุขภาพระบบ (APM) ในระบบ ERP'
            )));
    }
}
