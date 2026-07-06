<?php

namespace Webkul\Shopify\Services;

use Illuminate\Support\Facades\DB;
use Webkul\DataTransfer\Helpers\Export as ExportHelper;
use Webkul\DataTransfer\Models\JobTrackProxy;
use Webkul\DataTransfer\Repositories\JobTrackRepository;
use Webkul\Shopify\Models\ShopifyBulkOperation;
use Webkul\Shopify\Repositories\ShopifyBulkOperationRepository;

/**
 * Tracks Shopify follow-up phase progress for a JobTrack.
 *
 * Storage layout:
 * - Counter lives on each core bulk op's meta as `unfinished_phase_jobs` so it
 *   survives Export::completed() (which wipes JobTrack.summary). Total = sum
 *   across all core ops for the JobTrack.
 * - JobTrack.summary.current_phase is a best-effort UI hint; if Export::completed
 *   wipes it, the next phase write restores it.
 * - JobTrack.summary.follow_up_phases_finalize_pending is set by the deferral
 *   listener so the last markFinishedForCore knows to flip state back to completed.
 */
class PhaseProgressTracker
{
    public const PHASES_PER_BATCH = 3;

    public function __construct(
        protected JobTrackRepository $jobTrackRepository,
        protected ShopifyBulkOperationRepository $bulkOperationRepository,
    ) {}

    /**
     * Bump the per-core-op counter when a batch dispatches its phase jobs.
     */
    public function registerPhaseJobsForCore(int $coreBulkOpId, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        DB::transaction(function () use ($coreBulkOpId, $count) {
            $coreOp = $this->lockBulkOp($coreBulkOpId);

            if (! $coreOp) {
                return;
            }

            $meta = $coreOp->meta ?? [];
            $meta['unfinished_phase_jobs'] = (int) ($meta['unfinished_phase_jobs'] ?? 0) + $count;

            $coreOp->meta = $meta;
            $coreOp->save();
        });
    }

    /**
     * UI hint: write the currently running phase name to summary.
     * Best-effort; concurrent jobs may overwrite each other.
     */
    public function markStarted(?int $jobTrackId, string $phase): void
    {
        if (! $jobTrackId) {
            return;
        }

        DB::transaction(function () use ($jobTrackId, $phase) {
            $jobTrack = $this->lockJobTrack($jobTrackId);

            if (! $jobTrack) {
                return;
            }

            $summary = $jobTrack->summary ?? [];
            $summary['current_phase'] = $phase;

            $this->jobTrackRepository->update(['summary' => $summary], $jobTrackId);
        });
    }

    /**
     * UI hint: record the active batch ordinal together with the phase currently
     * executing for it, so the tracker can show "Batch N - <phase>" in real time.
     *
     * Writes to JobTrack.summary (current_batch + current_phase). Both the async
     * phase jobs (via markStarted) and the synchronous per-batch exporter pipeline
     * feed this; the tracker UI polls JobTrack.summary every second.
     */
    public function markBatchPhaseStarted(?int $jobTrackId, ?int $batchNumber, string $phase, ?float $progress = null): void
    {
        if (! $jobTrackId) {
            return;
        }

        DB::transaction(function () use ($jobTrackId, $batchNumber, $phase, $progress) {
            $jobTrack = $this->lockJobTrack($jobTrackId);

            if (! $jobTrack) {
                return;
            }

            $summary = $jobTrack->summary ?? [];
            $summary['current_phase'] = $phase;

            if ($batchNumber !== null) {
                $summary['current_batch'] = $batchNumber;
            }

            // Overall progress, distributed evenly across batches and the five
            // phases within each batch, so the tracker bar advances per phase.
            if ($progress !== null) {
                $summary['phase_progress'] = $progress;
            }

            $this->jobTrackRepository->update(['summary' => $summary], $jobTrackId);
        });
    }

    /**
     * UI hint: record the number of objects Shopify reports processed so far for
     * a given phase of the active export, so the tracker can show a live object
     * count per phase ("Media Upload · 1,234 objects") while the bulk operation
     * is still running.
     *
     * Stored as a phase => count map under summary.phase_object_counts. Null
     * counts (Shopify has not reported one yet) are ignored so an earlier good
     * value is never clobbered. Best-effort, mirroring markBatchPhaseStarted.
     */
    public function recordPhaseObjectCount(?int $jobTrackId, string $phase, ?int $objectCount): void
    {
        if (! $jobTrackId || $objectCount === null) {
            return;
        }

        DB::transaction(function () use ($jobTrackId, $phase, $objectCount) {
            $jobTrack = $this->lockJobTrack($jobTrackId);

            if (! $jobTrack) {
                return;
            }

            $summary = $jobTrack->summary ?? [];
            $counts = $summary['phase_object_counts'] ?? [];
            $counts[$phase] = $objectCount;
            $summary['phase_object_counts'] = $counts;

            $this->jobTrackRepository->update(['summary' => $summary], $jobTrackId);
        });
    }

    /**
     * Clear the per-phase object count map at the start of a new batch, so the
     * tracker UI only ever shows counts for the batch currently being processed
     * rather than accumulating completed phases from previous batches.
     */
    public function resetPhaseObjectCounts(?int $jobTrackId): void
    {
        if (! $jobTrackId) {
            return;
        }

        DB::transaction(function () use ($jobTrackId) {
            $jobTrack = $this->lockJobTrack($jobTrackId);

            if (! $jobTrack) {
                return;
            }

            $summary = $jobTrack->summary ?? [];

            if (! array_key_exists('phase_object_counts', $summary)) {
                return;
            }

            unset($summary['phase_object_counts']);

            $this->jobTrackRepository->update(['summary' => $summary], $jobTrackId);
        });
    }

    /**
     * Decrement a core op's counter when a phase work unit settles.
     *
     * If the JobTrack-wide total reaches zero AND no other core ops are still
     * pending (about to register their own phases) AND the listener has flagged
     * finalize_pending, flip state back to completed.
     */
    public function markFinishedForCore(int $coreBulkOpId, ?int $jobTrackId, string $phase): void
    {
        if (! $jobTrackId) {
            return;
        }

        DB::transaction(function () use ($coreBulkOpId, $jobTrackId, $phase) {
            $coreOp = $this->lockBulkOp($coreBulkOpId);

            if ($coreOp) {
                $meta = $coreOp->meta ?? [];
                $meta['unfinished_phase_jobs'] = max(0, (int) ($meta['unfinished_phase_jobs'] ?? 0) - 1);
                $coreOp->meta = $meta;
                $coreOp->save();
            }

            $remainingTotal = $this->totalUnfinishedForJobTrack($jobTrackId);
            $pendingCoreOps = $this->hasPendingCoreOps($jobTrackId);

            $jobTrack = $this->lockJobTrack($jobTrackId);

            if (! $jobTrack) {
                return;
            }

            $summary = $jobTrack->summary ?? [];

            if (($summary['current_phase'] ?? null) === $phase) {
                $summary['current_phase'] = ($remainingTotal > 0 || $pendingCoreOps) ? $phase : null;
            }

            $update = ['summary' => $summary];

            $finalizePending = ! empty($summary['follow_up_phases_finalize_pending']);
            $allWorkDone = $remainingTotal === 0 && ! $pendingCoreOps;

            if ($allWorkDone && $finalizePending && $jobTrack->state !== ExportHelper::STATE_COMPLETED) {
                $summary['follow_up_phases_finalize_pending'] = false;
                $update['summary'] = $summary;
                $update['state'] = ExportHelper::STATE_COMPLETED;
                $update['completed_at'] = now();
            }

            $this->jobTrackRepository->update($update, $jobTrackId);
        });
    }

    /**
     * Sum of unfinished_phase_jobs across every core bulk op tied to this JobTrack.
     * Source of truth used by both the deferral listener and markFinishedForCore.
     */
    public function totalUnfinishedForJobTrack(int $jobTrackId): int
    {
        $coreOps = ShopifyBulkOperation::query()
            ->where('job_track_id', $jobTrackId)
            ->where(function ($q) {
                $q->where('phase', BulkOperationService::CORE_PRODUCT_PHASE)->orWhereNull('phase');
            })
            ->get(['id', 'meta']);

        return (int) $coreOps->sum(fn ($op) => (int) (($op->meta ?? [])['unfinished_phase_jobs'] ?? 0));
    }

    /**
     * Whether the JobTrack still has follow-up work in flight or queued.
     *
     * Returns true when either:
     *  - any core bulk op is still pending on Shopify (status created/running),
     *    in which case follow-ups will be dispatched once it finalizes; or
     *  - any core bulk op already has phase jobs registered with non-zero counter.
     *
     * Used by DeferJobTrackCompletion to decide whether to revert state back to
     * processing when Export::completed() fires prematurely.
     */
    public function followUpsScheduled(int $jobTrackId): bool
    {
        $coreOps = ShopifyBulkOperation::query()
            ->where('job_track_id', $jobTrackId)
            ->where(function ($q) {
                $q->where('phase', BulkOperationService::CORE_PRODUCT_PHASE)->orWhereNull('phase');
            })
            ->get(['status', 'meta']);

        foreach ($coreOps as $op) {
            if (in_array($op->status ?? '', ['created', 'running'], true)) {
                return true;
            }

            if ((int) (($op->meta ?? [])['unfinished_phase_jobs'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any core bulk op for the JobTrack is still pending on Shopify
     * (status created/running) — i.e., its follow-up phases have not yet been
     * registered. Used to avoid prematurely flipping state to completed when
     * one batch's phases finish before another batch's bulk op even finalizes.
     */
    public function hasPendingCoreOps(int $jobTrackId): bool
    {
        return ShopifyBulkOperation::query()
            ->where('job_track_id', $jobTrackId)
            ->where(function ($q) {
                $q->where('phase', BulkOperationService::CORE_PRODUCT_PHASE)->orWhereNull('phase');
            })
            ->whereIn('status', ['created', 'running'])
            ->exists();
    }

    protected function lockJobTrack(int $jobTrackId)
    {
        $modelClass = JobTrackProxy::modelClass();

        return $modelClass::query()
            ->whereKey($jobTrackId)
            ->lockForUpdate()
            ->first();
    }

    protected function lockBulkOp(int $coreBulkOpId): ?ShopifyBulkOperation
    {
        return ShopifyBulkOperation::query()
            ->whereKey($coreBulkOpId)
            ->lockForUpdate()
            ->first();
    }
}
