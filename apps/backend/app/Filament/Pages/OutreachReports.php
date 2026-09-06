<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\DomainResource;
use App\Models\DailyOutreachReport;
use App\Models\OutreachDraft;
use App\Services\Outreach\DailyReports;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class OutreachReports extends Page
{
    use WithPagination;

    protected static ?string $navigationLabel = 'Daily outreach reports';

    protected static ?string $title = 'Daily outreach reports';

    protected string $view = 'filament.pages.outreach-reports';

    #[Url]
    public string $date = '';

    #[Locked]
    public ?int $selectedDraftId = null;

    public static function canAccess(): bool
    {
        return Filament::auth()->check() && DomainResource::canViewAny();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->date = $this->date ?: now('Asia/Yerevan')->toDateString();
        app(DailyReports::class)->bounds($this->date);
    }

    public function updatedDate(): void
    {
        $this->selectedDraftId = null;
        $this->resetPage();
    }

    public function selectDraft(int $id): void
    {
        abort_unless(static::canAccess(), 403);
        $draft = OutreachDraft::findOrFail($id);
        abort_unless(DomainResource::canView($draft->domain), 403);
        $this->selectedDraftId = $id;
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $reports = app(DailyReports::class);
        $selected = OutreachDraft::with('domain')->find($this->selectedDraftId);
        if ($selected) {
            abort_unless(DomainResource::canView($selected->domain), 403);
        }

        return [
            'drafts' => $reports->drafts($this->date)->with(['domain', 'messages'])->latest('id')->paginate(25),
            'audits' => $reports->audits($this->date)->with('domain')->latest('id')->limit(25)->get(),
            'summary' => $reports->summary($this->date),
            'snapshot' => DailyOutreachReport::where('report_date', $this->date)->first(),
            'selected' => $selected,
        ];
    }
}
