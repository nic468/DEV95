<?php

namespace App\Services;

use App\Models\UploadSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScrapPointingService
{
    public function __construct(
        private readonly EffectiveSnapshotMovementQuery $movements,
        private readonly SnapshotMetricCalculator $metrics
    ) {}

    /**
     * @param  array{snapshot_id: int, cost_center_code: string, work_order: string, product_code: string}  $identity
     */
    public function availableArea(array $identity): string
    {
        $netArea = (string) ($this->eligibleMovements($identity)->sum('quantity') ?? '0.000000');

        return $this->compareDecimals($netArea, '0') <= 0 ? '0.000000' : $netArea;
    }

    /**
     * @param  array{snapshot_id: int, cost_center_code: string, work_order: string, product_code: string}  $identity
     */
    public function record(array $identity, string $valueM2, string $recordedBy): int
    {
        return DB::transaction(function () use ($identity, $valueM2, $recordedBy): int {
            UploadSnapshot::query()
                ->whereKey($identity['snapshot_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $scrap = DB::table('snapshot_scraps')
                ->where($identity)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if ($scrap === null) {
                throw ValidationException::withMessages([
                    'snapshot_id' => 'Este grupo de chapa não está ativo para apontamento.',
                ]);
            }

            $availableArea = $this->availableArea($identity);

            if ($this->compareDecimals($valueM2, $availableArea) > 0) {
                throw ValidationException::withMessages([
                    'value_m2' => "A sobra apontada não pode exceder {$availableArea} m² disponíveis.",
                ]);
            }

            $revision = (int) DB::table('scrap_revisions')
                ->where($identity)
                ->max('revision') + 1;

            DB::table('scrap_revisions')->insert([
                ...$identity,
                'revision' => $revision,
                'value_m2' => $valueM2,
                'recorded_at' => CarbonImmutable::now('UTC'),
                'recorded_by' => $recordedBy,
            ]);

            $this->metrics->rebuild($identity['snapshot_id']);

            return $revision;
        });
    }

    public function assertActivePointingsWithinAvailableArea(int $snapshotId): void
    {
        $latestRevision = DB::table('scrap_revisions')
            ->where('snapshot_id', $snapshotId)
            ->select([
                'snapshot_id',
                'cost_center_code',
                'work_order',
                'product_code',
            ])
            ->selectRaw('MAX(revision) AS latest_revision')
            ->groupBy([
                'snapshot_id',
                'cost_center_code',
                'work_order',
                'product_code',
            ]);

        $pointings = DB::table('snapshot_scraps as scraps')
            ->joinSub($latestRevision, 'latest', function (JoinClause $join): void {
                $join->on('latest.snapshot_id', '=', 'scraps.snapshot_id')
                    ->on('latest.cost_center_code', '=', 'scraps.cost_center_code')
                    ->on('latest.work_order', '=', 'scraps.work_order')
                    ->on('latest.product_code', '=', 'scraps.product_code');
            })
            ->join('scrap_revisions as revisions', function (JoinClause $join): void {
                $join->on('revisions.snapshot_id', '=', 'latest.snapshot_id')
                    ->on('revisions.cost_center_code', '=', 'latest.cost_center_code')
                    ->on('revisions.work_order', '=', 'latest.work_order')
                    ->on('revisions.product_code', '=', 'latest.product_code')
                    ->on('revisions.revision', '=', 'latest.latest_revision');
            })
            ->where('scraps.snapshot_id', $snapshotId)
            ->where('scraps.is_active', true)
            ->whereNotNull('revisions.value_m2')
            ->get([
                'scraps.snapshot_id',
                'scraps.cost_center_code',
                'scraps.work_order',
                'scraps.product_code',
                'revisions.value_m2',
            ]);

        foreach ($pointings as $pointing) {
            $identity = [
                'snapshot_id' => (int) $pointing->snapshot_id,
                'cost_center_code' => $pointing->cost_center_code,
                'work_order' => $pointing->work_order,
                'product_code' => $pointing->product_code,
            ];
            $availableArea = $this->availableArea($identity);

            if ($this->compareDecimals((string) $pointing->value_m2, $availableArea) > 0) {
                throw ValidationException::withMessages([
                    'quantity' => sprintf(
                        'A correção deixaria o apontamento de %s m² acima da área disponível de %s m². Reduza a sobra antes de corrigir o material.',
                        $pointing->value_m2,
                        $availableArea
                    ),
                ]);
            }
        }
    }

    /**
     * @param  array{snapshot_id: int, cost_center_code: string, work_order: string, product_code: string}  $identity
     */
    private function eligibleMovements(array $identity): Builder
    {
        return $this->movements->forSnapshot($identity['snapshot_id'])
            ->where('cost_center_code', $identity['cost_center_code'])
            ->where('work_order', $identity['work_order'])
            ->where('product_code', $identity['product_code'])
            ->where('unit_code', 'M2')
            ->whereRaw('LOWER(description) LIKE ?', ['%chapa%'])
            ->whereRaw('LOWER(description) NOT LIKE ?', ['%grade%'])
            ->whereRaw('LOWER(description) NOT LIKE ?', ['%degrau%']);
    }

    private function compareDecimals(string $left, string $right): int
    {
        $leftNegative = str_starts_with($left, '-');
        $rightNegative = str_starts_with($right, '-');
        [$leftWhole, $leftFraction] = array_pad(explode('.', ltrim($left, '-'), 2), 2, '');
        [$rightWhole, $rightFraction] = array_pad(explode('.', ltrim($right, '-'), 2), 2, '');

        $leftWhole = ltrim($leftWhole, '0') ?: '0';
        $rightWhole = ltrim($rightWhole, '0') ?: '0';
        $scale = max(strlen($leftFraction), strlen($rightFraction));
        $leftFraction = str_pad($leftFraction, $scale, '0');
        $rightFraction = str_pad($rightFraction, $scale, '0');

        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }

        $magnitude = strlen($leftWhole) <=> strlen($rightWhole);

        if ($magnitude === 0) {
            $magnitude = strcmp($leftWhole, $rightWhole);
        }

        if ($magnitude === 0) {
            $magnitude = strcmp($leftFraction, $rightFraction);
        }

        $magnitude = $magnitude <=> 0;

        return $leftNegative ? -$magnitude : $magnitude;
    }
}
