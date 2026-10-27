<?php

namespace App\Models;

use App\Services\MediaStorage;
use Illuminate\Database\Eloquent\Model;

class PermanentPost extends Model
{
    public const string RED_CROSS = 'red-cross-dagupan';

    public const string REQUEST_CACHE = 'lifeflow.permanent_post.red_cross';

    protected $guarded = ['id'];

    protected $hidden = ['image_path'];

    protected $appends = ['image_url'];

    protected function casts(): array
    {
        return ['description_document' => 'array'];
    }

    public function getImageUrlAttribute(): ?string
    {
        return app(MediaStorage::class)->permanentPostUrl($this);
    }

    public static function seedDefault(): void
    {
        static::query()->firstOrCreate(['slug' => self::RED_CROSS], [
            'title' => 'Philippine Red Cross – Dagupan City Chapter',
            'summary' => 'Choose the Philippine Red Cross Dagupan City Chapter as your donation option.',
            'description' => 'Choose this option when you plan to donate through the Philippine Red Cross Dagupan City Chapter. Confirm donation arrangements with the chapter before visiting. Final donation eligibility is determined by the facility.',
        ]);
    }

    public static function redCross(): self
    {
        $request = request();
        $post = $request->attributes->get(self::REQUEST_CACHE);
        if (! $post instanceof self) {
            $post = static::query()->where('slug', self::RED_CROSS)->firstOrFail();
            $request->attributes->set(self::REQUEST_CACHE, $post);
        }

        return $post;
    }
}
