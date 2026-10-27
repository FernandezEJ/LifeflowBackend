<?php

namespace App\Models;

use App\Services\MediaStorage;
use Database\Factories\EducationalArticleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EducationalArticle extends Model
{
    /** @use HasFactory<EducationalArticleFactory> */
    use HasFactory;

    use SoftDeletes;

    public const array CATEGORIES = ['blood_basics' => 'Blood Basics', 'donor_tips' => 'Donor Tips', 'donation_guide' => 'Donation Guide', 'myths_facts' => 'Myths & Facts'];

    protected $guarded = ['id'];

    protected $attributes = ['title' => '', 'short_description' => '', 'description' => '', 'status' => 'draft'];

    protected $hidden = ['image_path'];

    protected $appends = ['image_url', 'category_label'];

    protected function casts(): array
    {
        return ['description_document' => 'array', 'published_at' => 'datetime'];
    }

    public function getImageUrlAttribute(): ?string
    {
        return app(MediaStorage::class)->educationalArticleUrl($this);
    }

    public function getCategoryLabelAttribute(): ?string
    {
        return self::CATEGORIES[$this->category] ?? null;
    }
}
