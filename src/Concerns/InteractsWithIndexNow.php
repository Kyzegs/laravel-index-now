<?php

declare(strict_types=1);

namespace Kyzegs\IndexNow\Concerns;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Kyzegs\IndexNow\IndexNow;
use Kyzegs\IndexNow\Support\SubmissionMode;

/**
 * Submits a model's public URLs when it is created, changed or deleted.
 *
 * On update both the current and the previous state are considered: when a
 * slug moves, or a page stops qualifying (unpublished, noindexed), the old
 * URLs are sent too, so the engines refetch them and drop what is gone.
 *
 * @mixin Model
 */
trait InteractsWithIndexNow
{
    /**
     * Absolute URLs (or paths, resolved against app.url) of this model's pages.
     *
     * @return string|list<string>
     */
    abstract public function indexNowUrls(): string|array;

    public static function bootInteractsWithIndexNow(): void
    {
        static::created(function (Model $model): void {
            /** @var Model&self $model */
            if ($model->shouldSubmitToIndexNow()) {
                $model->sendToIndexNow((array) $model->indexNowUrls());
            }
        });

        static::updated(function (Model $model): void {
            /** @var Model&self $model */
            $model->submitChangesToIndexNow();
        });

        static::deleted(function (Model $model): void {
            /** @var Model&self $model */
            if ($model->shouldSubmitToIndexNow()) {
                $model->sendToIndexNow((array) $model->indexNowUrls());
            }
        });
    }

    /**
     * Whether this model's pages belong in a search index right now
     * (published, public, not noindexed…).
     */
    public function shouldSubmitToIndexNow(): bool
    {
        return true;
    }

    /**
     * Attributes whose change makes the page worth resubmitting. Null means
     * any attribute except the timestamps.
     *
     * @return list<string>|null
     */
    public function indexNowAttributes(): ?array
    {
        return null;
    }

    public function indexNowMode(): SubmissionMode
    {
        return SubmissionMode::Defer;
    }

    /**
     * Runs inside `updated`, where getChanges() is what this save changed and
     * the original attributes still hold the pre-save row.
     */
    public function submitChangesToIndexNow(): void
    {
        if (!$this->hasIndexNowRelevantChanges()) {
            return;
        }

        $before = (clone $this)->setRawAttributes($this->getRawOriginal(), true);

        $urls = [
            ...($this->shouldSubmitToIndexNow() ? (array) $this->indexNowUrls() : []),
            ...($before->shouldSubmitToIndexNow() ? (array) $before->indexNowUrls() : []),
        ];

        $this->sendToIndexNow($urls);
    }

    protected function hasIndexNowRelevantChanges(): bool
    {
        $changed = array_keys($this->getChanges());
        $watched = $this->indexNowAttributes();

        if ($watched !== null) {
            return array_intersect($changed, $watched) !== [];
        }

        return array_diff($changed, array_filter([$this->getCreatedAtColumn(), $this->getUpdatedAtColumn()])) !== [];
    }

    /**
     * @param  list<string>  $urls
     */
    protected function sendToIndexNow(array $urls): void
    {
        if ($urls === []) {
            return;
        }

        $indexNow = Container::getInstance()->make(IndexNow::class);

        assert($indexNow instanceof IndexNow);

        $indexNow->submitUsing($this->indexNowMode(), array_values(array_unique($urls)));
    }
}
