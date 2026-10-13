<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Необязательно: php artisan db:seed — создаёт демо-доступ admin@oftob.uz / oftob12345.
     * Обычно первый вход делается через страницу /setup.
     */
    public function run(): void
    {
        if (User::exists()) {
            return;
        }
        $ws = Workspace::create(['name' => 'Демо-компания', 'version' => 0]);
        User::create(['workspace_id' => $ws->id, 'name' => 'Руководитель', 'email' => 'admin@oftob.uz', 'password' => 'oftob12345', 'role' => 'admin']);
        $this->command?->warn('Создан демо-вход admin@oftob.uz / oftob12345 — смените пароль!');
    }
}
