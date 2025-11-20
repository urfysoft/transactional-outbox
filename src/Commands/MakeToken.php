<?php

namespace Urfysoft\TransactionalOutbox\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\Console\Input\InputOption;

class MakeToken extends Command
{
    protected $name = 'urfysoft:make-token';

    protected $description = 'Creates unlimited token for clients';

    public function handle(): int
    {
        $name = Str::slug($this->option('name'));

        if (empty($name)) {
            $this->error('The --name option is required and can\'nt be empty');

            return self::INVALID;
        }

        $personalToken = PersonalAccessToken::query()
            ->where('name', '=', $name)
            ->first();

        $plainTextToken = sprintf(
            '%s%s%s',
            config('sanctum.token_prefix', ''),
            $tokenEntropy = Str::random(40),
            hash('crc32b', $tokenEntropy)
        );

        $payload = [
            'name' => $name,
            'token' => hash('sha256', $plainTextToken),
            'abilities' => [config('transactional-outbox.sanctum.required_ability')],
            'tokenable_id' => 0,
            'tokenable_type' => 'outbox-service',
            'expires_at' => null,
        ];

        if ($personalToken) {
            if (
                ! $this->components->confirm('Access token for this service already exists. Do you want to refresh it?')
            ) {
                return self::FAILURE;
            }

            $personalToken->update($payload);
        } else {
            $personalToken = PersonalAccessToken::query()->make($payload);
            $personalToken->tokenable_type = $payload['tokenable_type'];
            $personalToken->tokenable_id = $payload['tokenable_id'];
            $personalToken->save();
        }

        $accessToken = new NewAccessToken($personalToken, $personalToken->getKey().'|'.$plainTextToken);

        $this->table([
            'name',
            'token'
        ], [
            [
                $personalToken->name,
                $accessToken->plainTextToken,
            ]
        ]);

        $this->components->warn('Save auth token, You can\'t get it second time');

        return self::SUCCESS;
    }

    protected function getOptions()
    {
        return [
            new InputOption(
                name: 'name',
                shortcut: 'nm',
                mode: InputOption::VALUE_REQUIRED,
                description: 'Unique name of the token works like identifier'
            ),
        ];
    }
}
