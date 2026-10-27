<?php

namespace Database\Seeders;

use App\Models\PermanentPost;
use Illuminate\Database\Seeder;

class PermanentPostSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        PermanentPost::seedDefault();
    }
}
