<?php

namespace App\Http\Requests\Admin;

class SavePermanentPostRequest extends SaveAnnouncementRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['required', 'string', 'max:500'],
            'description' => ['required', 'string', 'max:10000'],
            'description_document' => ['nullable', 'array'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            'slug' => ['missing'], 'image_path' => ['missing'],
            'status' => ['missing'], 'event_date' => ['missing'], 'donation_date' => ['missing'],
            'expires_at' => ['missing'], 'published_at' => ['missing'], 'notify_donors' => ['missing'],
        ];
    }
}
