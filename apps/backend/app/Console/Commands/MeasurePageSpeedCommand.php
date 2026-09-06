<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Domain;
use App\Services\PageSpeed\PageSpeedDispatcher;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class MeasurePageSpeedCommand extends Command
{
    protected $signature = 'websites:pagespeed {--domain-id=} {--limit=25 : Maximum measurements queued or recovered in a batch} {--refresh : Refresh the specified domain only}';

    protected $description = 'Queue internal PageSpeed measurements for completed website audit pages';

    public function handle(PageSpeedDispatcher $dispatcher): int
    {
        $id = $this->option('domain-id');
        $limit = $this->option('limit');
        if (! ctype_digit((string) $limit) || (int) $limit < 1 || (int) $limit > 100
            || ($id !== null && (! ctype_digit((string) $id) || (int) $id < 1)) || ($this->option('refresh') && $id === null)) {
            $this->error('Use a limit from 1 to 100, a positive domain ID, and --refresh only with --domain-id.');

            return self::FAILURE;
        }
        try {
            $dispatcher->assertConfigured();
            if ($id !== null) {
                $audit = Domain::query()->find($id)?->latestWebsiteAudit;
                if ($audit === null) {
                    $this->error('No website audit found for this domain.');

                    return self::FAILURE;
                }
                $queued = $dispatcher->request($audit, (bool) $this->option('refresh'));
            } else {
                $queued = $dispatcher->recover((int) $limit);
                foreach ($dispatcher->strategies() as $strategy) {
                    if ($queued >= (int) $limit) {
                        break;
                    }
                    $pages = $dispatcher->eligiblePages()->whereDoesntHave('pageSpeedMeasurements', fn ($query) => $query->where('strategy', $strategy)
                        ->where(fn ($query) => $query->whereNotNull('active_key')->orWhere('expires_at', '>', now())))
                        ->orderBy('id')->limit((int) $limit - $queued)->get();
                    foreach ($pages as $page) {
                        $queued += $dispatcher->requestPage($page, $strategy)->wasRecentlyCreated ? 1 : 0;
                    }
                }
            }
        } catch (ValidationException $exception) {
            $this->error($exception->validator->errors()->first());

            return self::FAILURE;
        }
        $this->info("Queued or recovered {$queued} PageSpeed measurements.");

        return self::SUCCESS;
    }
}
