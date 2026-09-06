<?php

declare(strict_types=1);

namespace App\Services\Outreach;

use App\Models\OutreachMessage;
use Illuminate\Container\Attributes\Bind;

#[Bind(SmtpOutreachTransport::class)]
interface OutreachTransport
{
    public function send(OutreachMessage $message): void;
}
