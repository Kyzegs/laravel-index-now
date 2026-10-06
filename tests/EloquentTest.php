<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Kyzegs\IndexNow\Concerns\InteractsWithIndexNow;
use Kyzegs\IndexNow\Facades\IndexNow;
use Kyzegs\IndexNow\Support\SubmissionMode;

class Page extends Model
{
    use InteractsWithIndexNow;
    use SoftDeletes;

    protected $guarded = [];

    protected $attributes = ['published' => true];

    protected $casts = ['published' => 'boolean'];

    public static ?array $watched = null;

    public static SubmissionMode $mode = SubmissionMode::Defer;

    public function indexNowUrls(): string|array
    {
        return "/pages/{$this->slug}";
    }

    public function shouldSubmitToIndexNow(): bool
    {
        return $this->published;
    }

    public function indexNowAttributes(): ?array
    {
        return static::$watched;
    }

    public function indexNowMode(): SubmissionMode
    {
        return static::$mode;
    }
}

beforeEach(function () {
    Schema::create('pages', function (Blueprint $table) {
        $table->id();
        $table->string('slug');
        $table->string('title')->default('');
        $table->unsignedInteger('views')->default(0);
        $table->boolean('published')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });

    Page::$watched = null;
    Page::$mode = SubmissionMode::Defer;
    $this->fake = IndexNow::fake();
});

it('submits a created page', function () {
    Page::create(['slug' => 'a']);

    $this->fake->assertSubmitted('https://example.com/pages/a', SubmissionMode::Defer);
    $this->fake->assertSubmittedCount(1);
});

it('does not submit a page created unpublished', function () {
    Page::create(['slug' => 'a', 'published' => false]);

    $this->fake->assertNothingSubmitted();
});

it('submits a changed page', function () {
    $page = Page::create(['slug' => 'a']);

    $page->update(['title' => 'New']);

    $this->fake->assertSubmittedCount(2);
});

it('ignores a save that changed nothing, or only timestamps', function () {
    $page = Page::create(['slug' => 'a']);

    $page->save();
    $page->touch();

    $this->fake->assertSubmittedCount(1);
});

it('submits the old and the new url when the slug moves', function () {
    $page = Page::create(['slug' => 'old']);

    $page->update(['slug' => 'new']);

    $this->fake->assertSubmitted(fn(array $urls): bool => $urls === ['https://example.com/pages/new', 'https://example.com/pages/old']);
});

it('submits a page that was unpublished, so the engines drop it', function () {
    $page = Page::create(['slug' => 'a']);

    $page->update(['published' => false]);

    $this->fake->assertSubmittedCount(2);
});

it('submits a page that was published', function () {
    $page = Page::create(['slug' => 'a', 'published' => false]);

    $page->update(['published' => true]);

    $this->fake->assertSubmitted('https://example.com/pages/a');
});

it('leaves a page that was and stays unpublished alone', function () {
    $page = Page::create(['slug' => 'a', 'published' => false]);

    $page->update(['title' => 'Draft']);

    $this->fake->assertNothingSubmitted();
});

it('only reacts to the watched attributes', function () {
    Page::$watched = ['title', 'slug'];
    $page = Page::create(['slug' => 'a']);

    $page->update(['views' => 10]);
    $this->fake->assertSubmittedCount(1);

    $page->update(['title' => 'New']);
    $this->fake->assertSubmittedCount(2);
});

it('submits a deleted page', function (bool $force) {
    $page = Page::create(['slug' => 'a']);

    $force ? $page->forceDelete() : $page->delete();

    $this->fake->assertSubmittedCount(2);
})->with(['soft' => false, 'force' => true]);

it('submits a restored page', function () {
    $page = Page::create(['slug' => 'a']);
    $page->delete();

    $page->restore();

    $this->fake->assertSubmittedCount(3);
});

it('does not submit a deleted unpublished page', function () {
    $page = Page::create(['slug' => 'a', 'published' => false]);

    $page->delete();

    $this->fake->assertNothingSubmitted();
});

it('uses the model mode', function (SubmissionMode $mode) {
    Page::$mode = $mode;

    Page::create(['slug' => 'a']);

    $this->fake->assertSubmitted('https://example.com/pages/a', $mode);
})->with(SubmissionMode::cases());

class Note extends Model
{
    use InteractsWithIndexNow;

    protected $table = 'pages';

    protected $guarded = [];

    public function indexNowUrls(): string|array
    {
        return ["/notes/{$this->slug}", "/notes/{$this->slug}/print"];
    }
}

it('defaults to deferring every change of an always-public model', function () {
    $note = Note::create(['slug' => 'a']);
    $note->update(['views' => 1]);

    $this->fake->assertSubmittedCount(2);
    $this->fake->assertSubmitted(fn(array $urls, SubmissionMode $mode): bool => $mode === SubmissionMode::Defer
        && $urls === ['https://example.com/notes/a', 'https://example.com/notes/a/print']);
});
