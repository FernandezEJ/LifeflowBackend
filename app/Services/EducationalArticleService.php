<?php

namespace App\Services;

use App\Models\EducationalArticle;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class EducationalArticleService
{
    public function __construct(private readonly MediaStorage $media) {}

    public function profile(EducationalArticle $article): array
    {
        return [...$article->toArray(), 'status' => $article->trashed() ? 'deleted' : $article->status];
    }

    public function listing(array $filters, bool $staff = false): array
    {
        $query = EducationalArticle::query();
        if ($staff && ($filters['status'] ?? '') === 'deleted') {
            $query->onlyTrashed();
        } else {
            $query->where('status', $staff ? ($filters['status'] ?? 'published') : 'published');
        }
        if (trim($filters['search'] ?? '') !== '') {
            $query->whereLike('title', '%'.trim($filters['search']).'%');
        }
        if (isset($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        $page = $query->orderByDesc('published_at')->orderByDesc('id')->paginate(20);
        $result = ['data' => $page->getCollection()->map(fn ($item): array => $this->profile($item))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => 20],
            'categories' => EducationalArticle::CATEGORIES];
        if ($staff) {
            $counts = EducationalArticle::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
            $result['counts'] = ['published' => (int) ($counts['published'] ?? 0), 'draft' => (int) ($counts['draft'] ?? 0), 'deleted' => EducationalArticle::onlyTrashed()->count()];
        }

        return $result;
    }

    public function save(User $actor, array $data, ?int $id = null): EducationalArticle
    {
        $newPath = null;
        try {
            return DB::transaction(function () use ($actor, $data, $id, &$newPath): EducationalArticle {
                $article = $id === null ? new EducationalArticle : EducationalArticle::lockForUpdate()->findOrFail($id);
                $creating = ! $article->exists;
                $status = $data['status'] ?? $article->status;
                abort_if($article->status === 'published' && $status !== 'published', 409, 'Published changes remain live.');
                foreach (['title', 'short_description', 'description'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $article->$field = $data[$field] ?? '';
                    }
                }
                foreach (['category', 'description_document'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $article->$field = $data[$field];
                    }
                }
                if ($article->description_document !== null) {
                    $article->description = AnnouncementContent::plainText($article->description_document, allowEmpty: $status === 'draft');
                }
                if (isset($data['image'])) {
                    $newPath = $this->media->store($data['image'], 'education');
                    $article->image_path = $newPath;
                }
                if ($status === 'published') {
                    $errors = [];
                    foreach (['title', 'category', 'short_description', 'description'] as $field) {
                        if (trim($article->$field ?? '') === '') {
                            $errors[$field] = 'Complete this field before publishing.';
                        }
                    }
                    if ($article->description_document === null) {
                        $errors['description_document'] = 'Enter formatted educational content before publishing.';
                    }
                    if (! $this->media->educationalImageExists($article->image_path)) {
                        $errors['image'] = 'Upload a valid cover image before publishing.';
                    }
                    if ($errors !== []) {
                        throw ValidationException::withMessages($errors);
                    }
                    $article->published_at ??= now();
                }
                $article->status = $status;
                $article->save();
                $this->audit($actor, $article, $creating ? 'created' : 'updated');

                return $article;
            });
        } catch (Throwable $error) {
            if ($newPath !== null) {
                $this->media->discardNewUpload($newPath);
            }
            throw $error;
        }
    }

    public function transition(User $actor, int $id, string $action): EducationalArticle
    {
        if ($action === 'publish') {
            return $this->save($actor, ['status' => 'published'], $id);
        }

        return DB::transaction(function () use ($actor, $id, $action): EducationalArticle {
            $article = EducationalArticle::withTrashed()->lockForUpdate()->findOrFail($id);
            if ($action === 'restore') {
                abort_unless($article->trashed(), 409, 'This article is not deleted.');
                $article->status = 'draft';
                $article->published_at = null;
                $article->restore();
            } else {
                abort_if($article->trashed(), 409, 'This article is already deleted.');
                $article->delete();
            }
            $this->audit($actor, $article, $action);

            return $article;
        });
    }

    private function audit(User $actor, EducationalArticle $article, string $action): void
    {
        $actor->auditLogs()->create(['actor_role' => $actor->role, 'action' => 'educational_article_'.$action,
            'module' => 'education', 'target_type' => 'educational_article', 'target_id' => $article->id]);
    }
}
