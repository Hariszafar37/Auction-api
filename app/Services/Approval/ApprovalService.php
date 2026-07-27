<?php

namespace App\Services\Approval;

use App\Models\ApprovalHistory;
use App\Models\BusinessProfile;
use App\Models\DealerProfile;
use App\Models\GovProfile;
use App\Models\PowerOfAttorney;
use App\Models\SellerProfile;
use App\Models\UserDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Central service for the Admin Approval Dashboard.
 *
 * Two responsibilities:
 *  1. record()      — append an immutable audit row whenever an admin approves/rejects.
 *  2. dashboard()   — build a normalized, filterable, paginated view that unions the live
 *                     profile/POA tables so every record (incl. pending & legacy) appears.
 *
 * The dashboard list is sourced from the LIVE tables (current truth) rather than the audit
 * log, so it is fully backward-compatible with records created before history tracking.
 */
class ApprovalService
{
    public const TYPE_DEALER     = 'dealer';
    public const TYPE_BUSINESS   = 'business';
    public const TYPE_SELLER     = 'seller';
    public const TYPE_GOVERNMENT = 'government';
    public const TYPE_POA        = 'poa';

    /**
     * Audit-only approval type for per-document reviews performed from the admin
     * user-detail page. Deliberately NOT part of self::TYPES: documents are not
     * standalone dashboard rows, they are supporting evidence attached to a user.
     * They surface as (a) the `document_*` fields on each dashboard record and
     * (b) interleaved entries in that record's history timeline.
     */
    public const TYPE_DOCUMENT = 'document';

    /** Action string used for document review audit rows. */
    public const ACTION_DOCUMENT_REVIEWED = 'document_reviewed';

    public const TYPES = [
        self::TYPE_DEALER,
        self::TYPE_BUSINESS,
        self::TYPE_SELLER,
        self::TYPE_GOVERNMENT,
        self::TYPE_POA,
    ];

    /**
     * Append an audit row for an approval action.
     */
    public function record(
        string $approvalType,
        int $relatedId,
        ?int $subjectUserId,
        string $action,
        ?string $previousStatus,
        ?string $newStatus,
        ?string $remarks,
        ?int $performedBy,
    ): ApprovalHistory {
        return ApprovalHistory::create([
            'approval_type'   => $approvalType,
            'related_id'      => $relatedId,
            'subject_user_id' => $subjectUserId,
            'action'          => $action,
            'previous_status' => $previousStatus,
            'new_status'      => $newStatus,
            'remarks'         => $remarks,
            'performed_by'    => $performedBy,
            'performed_at'    => now(),
        ]);
    }

    /**
     * Build the dashboard payload: summary counts + a paginated, normalized record list.
     *
     * @return array{summary: array<string,int>, records: LengthAwarePaginator}
     */
    public function dashboard(array $filters): array
    {
        // Base collection ignores the status filter so the summary cards can show the
        // full pending/approved/rejected breakdown within the other active filters.
        $base = $this->normalizedCollection($filters);

        $summary = [
            'pending'            => $base->where('status', 'pending')->count(),
            'approved'           => $base->where('status', 'approved')->count(),
            'rejected'           => $base->where('status', 'rejected')->count(),
            // POA-only lifecycle state — always 0 for other approval types, so
            // pending + approved + rejected + revision_requested === total holds.
            'revision_requested' => $base->where('status', 'revision_requested')->count(),
            'total'              => $base->count(),
        ];

        $records = $base;
        $status  = $filters['status'] ?? null;
        if ($status && $status !== 'all') {
            $records = $records->where('status', $status)->values();
        }

        $records = $records->sortByDesc(fn ($r) => $r['action_date'] ?? $r['applied_date'])->values();

        $perPage = (int) ($filters['per_page'] ?? 20);
        $page    = (int) ($filters['page'] ?? 1);

        $paginator = new LengthAwarePaginator(
            $records->forPage($page, $perPage)->values(),
            $records->count(),
            $perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath()],
        );

        return ['summary' => $summary, 'records' => $paginator];
    }

    /**
     * Full audit-log feed across all approval types, paginated.
     */
    public function history(array $filters): LengthAwarePaginator
    {
        $query = ApprovalHistory::with(['subjectUser:id,name,email', 'performer:id,name'])
            ->orderByDesc('performed_at');

        if (! empty($filters['approval_type']) && $filters['approval_type'] !== 'all') {
            $query->where('approval_type', $filters['approval_type']);
        }
        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }
        if (! empty($filters['performed_by'])) {
            $query->where('performed_by', $filters['performed_by']);
        }
        if (! empty($filters['date_from'])) {
            $query->where('performed_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }
        if (! empty($filters['date_to'])) {
            $query->where('performed_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }

        return $query->paginate((int) ($filters['per_page'] ?? 30));
    }

    /**
     * Timeline of actions for a single approval record (real audit rows, with a synthesized
     * "applied" entry at the start and a synthesized decision for legacy pre-audit records).
     *
     * @return Collection<int,array<string,mixed>>
     */
    public function recordHistory(string $type, int $relatedId): Collection
    {
        $record = $this->findRecord($type, $relatedId);
        if (! $record) {
            return collect();
        }

        $entries = collect();

        // Synthetic "applied" entry — applications are not themselves recorded.
        $entries->push([
            'action'          => 'applied',
            'previous_status' => null,
            'new_status'      => 'pending',
            'remarks'         => null,
            'performed_by'    => null,
            'performed_by_name' => null,
            'performed_at'    => optional($record->created_at)->toIso8601String(),
            'synthesized'     => true,
            'document_type'   => null,
        ]);

        $rows = ApprovalHistory::with('performer:id,name')
            ->where('approval_type', $type)
            ->where('related_id', $relatedId)
            ->orderBy('performed_at')
            ->get();

        if ($rows->isNotEmpty()) {
            foreach ($rows as $row) {
                $entries->push([
                    'action'            => $row->action,
                    'previous_status'   => $row->previous_status,
                    'new_status'        => $row->new_status,
                    'remarks'           => $row->remarks,
                    'performed_by'      => $row->performed_by,
                    'performed_by_name' => $row->performer?->name,
                    'performed_at'      => optional($row->performed_at)->toIso8601String(),
                    'synthesized'       => false,
                    'document_type'     => null,
                ]);
            }
        } else {
            // Legacy record reviewed before audit logging existed — synthesize from the
            // profile/POA's own reviewer fields so the timeline is not empty.
            $reviewedAt = $record->reviewed_at;
            $status     = $this->rawStatus($type, $record);
            if ($reviewedAt && in_array($status, ['approved', 'rejected'], true)) {
                $record->loadMissing('reviewer:id,name');
                $entries->push([
                    'action'            => $status,
                    'previous_status'   => 'pending',
                    'new_status'        => $status,
                    'remarks'           => $this->remarks($type, $record),
                    'performed_by'      => $record->reviewed_by,
                    'performed_by_name' => $record->reviewer?->name,
                    'performed_at'      => $reviewedAt->toIso8601String(),
                    'synthesized'       => true,
                    'document_type'     => null,
                ]);
            }
        }

        // Document reviews are recorded against the applicant, not against this specific
        // approval record, so they are pulled by subject_user_id and interleaved by date.
        // Kept out of the legacy-synthesis check above on purpose: a profile with zero
        // real approval rows must still synthesize its decision entry.
        $entries = $entries->merge($this->documentHistoryFor($record->user_id));

        return $entries->sortBy('performed_at')->values();
    }

    /**
     * Audit entries for every document review performed on an applicant.
     *
     * @return Collection<int,array<string,mixed>>
     */
    private function documentHistoryFor(?int $subjectUserId): Collection
    {
        if (! $subjectUserId) {
            return collect();
        }

        $rows = ApprovalHistory::with('performer:id,name')
            ->where('approval_type', self::TYPE_DOCUMENT)
            ->where('subject_user_id', $subjectUserId)
            ->orderBy('performed_at')
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        // Resolve document types in one query so the timeline can say *which* document.
        $types = UserDocument::whereIn('id', $rows->pluck('related_id')->unique())
            ->pluck('type', 'id');

        return $rows->map(fn (ApprovalHistory $row) => [
            'action'            => $row->action,
            'previous_status'   => $row->previous_status,
            'new_status'        => $row->new_status,
            'remarks'           => $row->remarks,
            'performed_by'      => $row->performed_by,
            'performed_by_name' => $row->performer?->name,
            'performed_at'      => optional($row->performed_at)->toIso8601String(),
            'synthesized'       => false,
            'document_type'     => $types[$row->related_id] ?? null,
        ]);
    }

    // ── Normalization ──────────────────────────────────────────────────────────────

    /**
     * Build the merged, normalized collection across all sources, applying every filter
     * except `status` (status is applied later so summary counts stay meaningful).
     *
     * @return Collection<int,array<string,mixed>>
     */
    private function normalizedCollection(array $filters): Collection
    {
        $type    = $filters['approval_type'] ?? null;
        $sources = ($type && $type !== 'all') ? [$type] : self::TYPES;

        $merged = collect();
        foreach ($sources as $source) {
            $merged = $merged->merge($this->normalizeSource($source, $filters));
        }

        // Date filters applied in-memory across the unioned set.
        if (! empty($filters['date_from'])) {
            $from   = Carbon::parse($filters['date_from'])->startOfDay();
            $merged = $merged->filter(fn ($r) => $r['applied_date'] && Carbon::parse($r['applied_date'])->gte($from));
        }
        if (! empty($filters['date_to'])) {
            $to     = Carbon::parse($filters['date_to'])->endOfDay();
            $merged = $merged->filter(fn ($r) => $r['applied_date'] && Carbon::parse($r['applied_date'])->lte($to));
        }
        if (! empty($filters['action_from'])) {
            $from   = Carbon::parse($filters['action_from'])->startOfDay();
            $merged = $merged->filter(fn ($r) => $r['action_date'] && Carbon::parse($r['action_date'])->gte($from));
        }
        if (! empty($filters['action_to'])) {
            $to     = Carbon::parse($filters['action_to'])->endOfDay();
            $merged = $merged->filter(fn ($r) => $r['action_date'] && Carbon::parse($r['action_date'])->lte($to));
        }

        return $this->attachDocumentNotes($merged->values());
    }

    /**
     * Overlay each record with the latest document-review note left for that applicant.
     *
     * Document reviews live in `user_documents` (written from the admin user-detail
     * page) and are entirely separate from the profile-level `rejection_reason` that
     * feeds `remarks`. This batch-loads them by user_id so the dashboard can show both
     * without an N+1 and without altering `remarks` semantics.
     *
     * @param  Collection<int,array<string,mixed>>  $merged
     * @return Collection<int,array<string,mixed>>
     */
    private function attachDocumentNotes(Collection $merged): Collection
    {
        $userIds = $merged->pluck('user_id')->filter()->unique()->values();

        if ($userIds->isEmpty()) {
            return $merged;
        }

        // Only documents that actually carry a note are useful here. `admin_notes` is
        // nullable and may also hold an empty string, so trim-filter in PHP rather than
        // relying on driver-specific SQL (MySQL in prod, SQLite in tests).
        $byUser = UserDocument::with('reviewer:id,name')
            ->whereIn('user_id', $userIds)
            ->whereNotNull('admin_notes')
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (UserDocument $d) => trim((string) $d->admin_notes) !== '')
            ->groupBy('user_id');

        return $merged->map(function (array $record) use ($byUser) {
            $docs = $byUser->get($record['user_id']);

            if (! $docs || $docs->isEmpty()) {
                return $record;
            }

            /** @var UserDocument $latest */
            $latest = $docs->first();

            return array_merge($record, [
                'document_remarks'         => $latest->admin_notes,
                'document_type'            => $latest->type,
                'document_status'          => $latest->status,
                'document_reviewed_at'     => optional($latest->reviewed_at)->toIso8601String(),
                'document_reviewed_by'     => $latest->reviewer?->name,
                'document_notes_count'     => $docs->count(),
            ]);
        });
    }

    /**
     * Query a single source table and map each row to the normalized shape.
     *
     * @return Collection<int,array<string,mixed>>
     */
    private function normalizeSource(string $type, array $filters): Collection
    {
        $search      = trim((string) ($filters['search'] ?? ''));
        $name        = trim((string) ($filters['name'] ?? ''));
        $email       = trim((string) ($filters['email'] ?? ''));
        $company     = trim((string) ($filters['company'] ?? ''));
        $performedBy = $filters['performed_by'] ?? null;

        $query = $this->baseQuery($type);

        // User-centric search (name OR email OR company) — applied at DB level.
        if ($search !== '') {
            $query->where(function ($q) use ($search, $type) {
                $q->whereHas('user', function ($uq) use ($search) {
                    $uq->where('name', 'like', "%{$search}%")
                       ->orWhere('email', 'like', "%{$search}%");
                });
                $companyColumn = $this->companyColumn($type);
                if ($companyColumn) {
                    $q->orWhere($companyColumn, 'like', "%{$search}%");
                }
            });
        }
        if ($name !== '') {
            $query->whereHas('user', fn ($uq) => $uq->where('name', 'like', "%{$name}%"));
        }
        if ($email !== '') {
            $query->whereHas('user', fn ($uq) => $uq->where('email', 'like', "%{$email}%"));
        }
        if ($company !== '' && $this->companyColumn($type)) {
            $query->where($this->companyColumn($type), 'like', "%{$company}%");
        }
        if ($performedBy) {
            $query->where('reviewed_by', $performedBy);
        }

        return $query->get()->map(fn (Model $record) => $this->mapRecord($type, $record));
    }

    private function baseQuery(string $type)
    {
        return match ($type) {
            self::TYPE_DEALER     => DealerProfile::query()->with(['user:id,name,email', 'reviewer:id,name']),
            self::TYPE_BUSINESS   => BusinessProfile::query()->with(['user:id,name,email', 'reviewer:id,name']),
            self::TYPE_SELLER     => SellerProfile::query()->with(['user:id,name,email', 'reviewer:id,name']),
            self::TYPE_GOVERNMENT => GovProfile::query()->with(['user:id,name,email', 'reviewer:id,name']),
            self::TYPE_POA        => PowerOfAttorney::query()->with(['user:id,name,email', 'reviewer:id,name']),
        };
    }

    private function companyColumn(string $type): ?string
    {
        return match ($type) {
            self::TYPE_DEALER     => 'company_name',
            self::TYPE_BUSINESS   => 'legal_business_name',
            self::TYPE_GOVERNMENT => 'entity_name',
            default               => null,
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function mapRecord(string $type, Model $record): array
    {
        $user = $record->user;

        return [
            'approval_type'  => $type,
            'related_id'     => $record->id,
            'user_id'        => $record->user_id,
            'applicant_name' => $user?->name,
            'email'          => $user?->email,
            'company_name'   => $this->companyName($type, $record),
            'identifier'     => $this->identifier($type, $record),
            'applied_date'   => optional($record->created_at)->toIso8601String(),
            'status'         => $this->normalizedStatus($type, $record),
            'raw_status'     => $this->rawStatus($type, $record),
            'action_date'    => optional($record->reviewed_at)->toIso8601String(),
            'action_by_id'   => $record->reviewed_by,
            'action_by_name' => $record->reviewer?->name,
            'remarks'        => $this->remarks($type, $record),
            // Populated by attachDocumentNotes(); defaulted here so every record has
            // a stable shape whether or not the applicant has any reviewed documents.
            'document_remarks'     => null,
            'document_type'        => null,
            'document_status'      => null,
            'document_reviewed_at' => null,
            'document_reviewed_by' => null,
            'document_notes_count' => 0,
        ];
    }

    private function companyName(string $type, Model $record): ?string
    {
        return match ($type) {
            self::TYPE_DEALER     => $record->company_name,
            self::TYPE_BUSINESS   => $record->legal_business_name,
            self::TYPE_GOVERNMENT => $record->entity_name,
            default               => null,
        };
    }

    private function identifier(string $type, Model $record): ?string
    {
        return match ($type) {
            self::TYPE_DEALER     => $record->dealer_license,
            self::TYPE_BUSINESS   => $record->entity_type,
            self::TYPE_GOVERNMENT => $record->entity_subtype,
            self::TYPE_POA        => $record->type,
            default               => null,
        };
    }

    private function rawStatus(string $type, Model $record): ?string
    {
        return $type === self::TYPE_POA ? $record->status : $record->approval_status;
    }

    private function normalizedStatus(string $type, Model $record): string
    {
        $raw = $this->rawStatus($type, $record);

        return match ($raw) {
            'approved'           => 'approved',
            'rejected'           => 'rejected',
            // POA-only: a reviewed-and-returned POA is distinct from an
            // unreviewed one. Other types never carry this raw value, so their
            // normalization is unchanged.
            'revision_requested' => 'revision_requested',
            default              => 'pending',
        };
    }

    private function remarks(string $type, Model $record): ?string
    {
        return $type === self::TYPE_POA ? $record->admin_notes : $record->rejection_reason;
    }

    private function findRecord(string $type, int $id): ?Model
    {
        return match ($type) {
            self::TYPE_DEALER     => DealerProfile::find($id),
            self::TYPE_BUSINESS   => BusinessProfile::find($id),
            self::TYPE_SELLER     => SellerProfile::find($id),
            self::TYPE_GOVERNMENT => GovProfile::find($id),
            self::TYPE_POA        => PowerOfAttorney::find($id),
            default               => null,
        };
    }
}
