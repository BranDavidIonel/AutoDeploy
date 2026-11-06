<?php

namespace App\Models;

use App\Enums\ConnectionType;
use App\Enums\ServerAuthMethod;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property ConnectionType $connection_type
 * @property string|null $host
 * @property int $port
 * @property string $username
 * @property ServerAuthMethod $auth_method
 * @property string|null $key_path
 * @property string|null $private_key
 * @property string|null $passphrase
 * @property string|null $password
 * @property string|null $host_fingerprint
 * @property string $status
 * @property Carbon|null $last_checked_at
 * @property string|null $last_error
 * @property array<string, string>|null $info
 */
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'connection_type',
        'host',
        'port',
        'username',
        'auth_method',
        'key_path',
        'private_key',
        'passphrase',
        'password',
        'host_fingerprint',
        'status',
        'last_checked_at',
        'last_error',
        'info',
    ];

    protected $hidden = ['private_key', 'passphrase', 'password'];

    protected function casts(): array
    {
        return [
            'connection_type' => ConnectionType::class,
            'auth_method' => ServerAuthMethod::class,
            'port' => 'integer',
            'private_key' => 'encrypted',
            'passphrase' => 'encrypted',
            'password' => 'encrypted',
            'last_checked_at' => 'datetime',
            'info' => 'array',
        ];
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function isLocal(): bool
    {
        return $this->connection_type === ConnectionType::Local;
    }

    /** "root@203.0.113.10:22" or "This machine". */
    public function address(): string
    {
        if ($this->isLocal()) {
            return __('This machine');
        }

        return $this->username.'@'.$this->host.($this->port !== 22 ? ':'.$this->port : '');
    }

    /** Total RAM in MB, from the last check (null when unknown). */
    public function memoryMb(): ?int
    {
        $total = $this->info['mem_total'] ?? null;

        return is_numeric($total) ? (int) $total : null;
    }
}
