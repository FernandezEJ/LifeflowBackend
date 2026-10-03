<?php

namespace Database\Factories;

use App\Models\FlowieConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FlowieConversation> */
class FlowieConversationFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'title' => 'Flowie Conversation',
            'status' => FlowieConversation::ACTIVE, 'last_message_at' => now()];
    }

    public function ended(): static
    {
        return $this->state(fn () => ['status' => FlowieConversation::ENDED, 'ended_at' => now()]);
    }
}
