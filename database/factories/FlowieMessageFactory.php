<?php

namespace Database\Factories;

use App\Models\FlowieConversation;
use App\Models\FlowieMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FlowieMessage> */
class FlowieMessageFactory extends Factory
{
    public function definition(): array
    {
        return ['flowie_conversation_id' => FlowieConversation::factory(),
            'role' => 'user', 'content' => 'How can I prepare for donation?'];
    }

    public function assistant(): static
    {
        return $this->state(fn () => ['role' => 'assistant', 'content' => 'Rest and hydrate.']);
    }
}
