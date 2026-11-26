<?php

namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use JsonException;

class CreateAdmin extends Command
{
    protected $signature = 'admin:create {--credentials-file= : Private JSON file containing email and password, or - for standard input}';

    protected $description = 'Create a dedicated administrator login';

    public function handle(): int
    {
        $file = $this->option('credentials-file');
        if ($file === null) {
            $credentials = ['email' => $this->ask('Admin email'), 'password' => $this->secret('Admin password')];
        } else {
            $contents = $file === '-' ? stream_get_contents(STDIN) : (is_readable($file) ? file_get_contents($file) : false);
            try {
                $credentials = json_decode($contents ?: '', true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $this->error('The private credentials file must contain valid JSON.');

                return self::FAILURE;
            }
        }

        $validator = Validator::make(is_array($credentials) ? $credentials : [], [
            'email' => ['required', 'email', 'lowercase', 'max:255', 'unique:admins,email'],
            'password' => ['required', 'string', 'min:12', 'max:72'],
        ]);
        if ($validator->fails()) {
            $this->error(implode(' ', $validator->errors()->all()));

            return self::FAILURE;
        }
        Admin::create($validator->validated());
        $this->info('Admin account created.');

        return self::SUCCESS;
    }
}
