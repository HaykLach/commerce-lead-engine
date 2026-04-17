<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

Artisan::command('app:about-leads', function (): void {
    $this->comment('Commerce lead engine backend skeleton is ready.');
})->purpose('Display backend skeleton status');
