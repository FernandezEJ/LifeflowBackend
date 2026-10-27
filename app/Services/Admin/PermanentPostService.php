<?php

namespace App\Services\Admin;

use App\Models\PermanentPost;
use App\Models\User;
use App\Services\AnnouncementContent;
use App\Services\MediaStorage;
use Illuminate\Support\Facades\DB;
use Throwable;

class PermanentPostService
{
    public function __construct(private readonly MediaStorage $media) {}

    public function save(User $actor, array $data): PermanentPost
    {
        $newPath = null;
        try {
            $saved = DB::transaction(function () use ($actor, $data, &$newPath): PermanentPost {
                $post = PermanentPost::where('slug', PermanentPost::RED_CROSS)->lockForUpdate()->firstOrFail();
                $post->fill(['title' => $data['title'], 'summary' => $data['summary'],
                    'description' => $data['description'], 'description_document' => $data['description_document'] ?? null]);
                if ($post->description_document !== null) {
                    $post->description = AnnouncementContent::plainText($post->description_document);
                }
                if (isset($data['image'])) {
                    $newPath = $this->media->store($data['image'], 'announcements');
                    $post->image_path = $newPath;
                }
                $post->save();
                $actor->auditLogs()->create(['actor_role' => $actor->role, 'action' => 'permanent_post_updated',
                    'module' => 'announcements', 'target_type' => 'permanent_post', 'target_id' => $post->id]);

                // Existing immutable media is retained, as with regular announcements.
                return $post;
            });
            request()->attributes->remove(PermanentPost::REQUEST_CACHE);

            return $saved;
        } catch (Throwable $error) {
            if ($newPath !== null) {
                $this->media->discardNewUpload($newPath);
            }
            throw $error;
        }
    }
}
