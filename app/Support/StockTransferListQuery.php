<?php

namespace App\Support;

use App\Enums\StockTransferStatus;
use App\Http\Requests\Branch\IndexStockTransferRequest;
use App\Models\Branch;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The stock transfer listing, seen from one branch or from all (P2-E3-T3, T5).
 *
 * Branch staff stand in their own branch, and `StockTransferBranchScope` has
 * already narrowed the table to the transfers they are a party to. Central
 * staff see every transfer, or stand in one branch with `?branch=` — which
 * applies `visibleToBranch()` explicitly, as a filter, without touching
 * `BranchContext` (LOW-07).
 *
 * The direction filter and the summary are relative to that standing point:
 * "masuk" is what comes to it, "keluar" what leaves it.
 */
final class StockTransferListQuery
{
    private const PER_PAGE = 20;

    /**
     * @param  array{search: string, status: string, direction: string}  $filters
     */
    private function __construct(
        private readonly ?Branch $perspective,
        private readonly array $filters,
    ) {}

    /**
     * @param  array{search: string, status: string, direction: string}  $filters
     */
    public static function for(User $actor, ?int $requestedBranchId, array $filters): self
    {
        $branchId = $actor->isCentral() ? $requestedBranchId : $actor->branch_id;

        return new self(
            $branchId === null ? null : Branch::query()->findOrFail($branchId),
            $filters,
        );
    }

    /**
     * @return array{id: int, name: string, code: string}|null
     */
    public function perspective(): ?array
    {
        return $this->perspective === null ? null : [
            'id' => $this->perspective->id,
            'name' => $this->perspective->name,
            'code' => $this->perspective->code,
        ];
    }

    /**
     * @return LengthAwarePaginator<int, mixed>
     */
    public function paginate(): LengthAwarePaginator
    {
        $branchId = $this->perspective?->id;

        return $this->base()
            ->with(['fromBranch:id,name,code', 'toBranch:id,name,code', 'requestedBy:id,name'])
            ->withCount('items')
            ->withSum('items', 'quantity_requested')
            ->when($branchId !== null && $this->filters['direction'] === IndexStockTransferRequest::DIRECTION_INCOMING,
                fn (Builder $query) => $query->where('to_branch_id', $branchId))
            ->when($branchId !== null && $this->filters['direction'] === IndexStockTransferRequest::DIRECTION_OUTGOING,
                fn (Builder $query) => $query->where('from_branch_id', $branchId))
            ->when($this->filters['search'] !== '',
                fn (Builder $query) => $query->where('code', 'like', "%{$this->filters['search']}%"))
            ->when($this->filters['status'] !== '',
                fn (Builder $query) => $query->where('status', $this->filters['status']))
            ->latestFirst()
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (StockTransfer $transfer): array => StockTransferPresenter::row($transfer, $branchId));
    }

    /**
     * What is waiting on whom, for the stat cards.
     *
     * Without a standing point (central, all branches) "to approve" and "to
     * ship" are simply every transfer in that state.
     *
     * @return array{to_approve: int, to_ship: int, to_receive: int, waiting_on_others: int}
     */
    public function summary(): array
    {
        $branchId = $this->perspective?->id;

        $count = fn (StockTransferStatus $status, ?string $end) => $this->base()
            ->where('status', $status)
            ->when($branchId !== null && $end !== null, fn (Builder $query) => $query->where($end, $branchId))
            ->count();

        return [
            'to_approve' => $count(StockTransferStatus::Requested, 'from_branch_id'),
            'to_ship' => $count(StockTransferStatus::Approved, 'from_branch_id'),
            'to_receive' => $count(StockTransferStatus::InTransit, 'to_branch_id'),
            // Requests this branch sent that the other end has not acted on yet.
            'waiting_on_others' => $branchId === null ? 0 : $this->base()
                ->where('to_branch_id', $branchId)
                ->whereIn('status', [StockTransferStatus::Requested, StockTransferStatus::Approved])
                ->count(),
        ];
    }

    /**
     * @return Builder<StockTransfer>
     */
    private function base(): Builder
    {
        return StockTransfer::query()
            ->when($this->perspective !== null,
                fn (Builder $query) => $query->visibleToBranch($this->perspective->id));
    }
}
