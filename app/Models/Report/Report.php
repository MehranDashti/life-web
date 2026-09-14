<?php

declare(strict_types=1);

namespace App\Models\Report;

use App\Models\User\User;
use App\Enums\ReportPeriod;
use App\Enums\ReportStatus;
use Illuminate\Support\Carbon;
use App\Models\Traits\BlamableTrait;
use Illuminate\Database\Eloquent\Model;
use Database\Factories\Report\ReportFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A user's periodic report subscription.
 *
 * @property string $id
 * @property string $user_id
 * @property string $name
 * @property ReportPeriod $period
 * @property array<int, string> $keywords
 * @property array<int, string>|null $news_agency_ids
 * @property bool $match_all_keywords
 * @property ReportStatus $status
 * @property Carbon|null $last_run_at
 * @property Carbon|null $next_run_at
 * @property int $consecutive_failures
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $user  null when the owner has been soft-deleted
 * @property-read Collection<int, ReportRun> $runs
 */
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use BlamableTrait, HasFactory, HasUuids, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'name',
        'period',
        'keywords',
        'news_agency_ids',
        'match_all_keywords',
        'status',
        'last_run_at',
        'next_run_at',
        'consecutive_failures',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ReportRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(ReportRun::class);
    }

    public function isActive(): bool
    {
        return $this->status === ReportStatus::Active;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period' => ReportPeriod::class,
            'status' => ReportStatus::class,
            'keywords' => 'array',
            'news_agency_ids' => 'array',
            'match_all_keywords' => 'boolean',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
            'consecutive_failures' => 'integer',
        ];
    }
}
