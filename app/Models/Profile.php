<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class Profile extends Model
{
    use Notifiable;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'email', 'full_name', 'branch_id', 'company_id', 'is_active',
    ];

    /**
     * DB-based roles (future / optional).
     * NOTE: this is not required if you're using JWT app_metadata roles,
     * but keeping it gives you flexibility later.
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id');
    }

    /**
     * Primary role source = injected roles from JWT middleware (fast, stateless).
     * Fallback = DB pivot if you decide to persist roles there.
     */
    public function roleCodes(): array
    {
        // If middleware injected roles onto the model:
        // e.g., $profile->roles = ['PURCHASE_CABANG', ...]
        if (isset($this->roles) && is_array($this->roles)) {
            return array_values(array_unique(array_map(
                fn ($r) => strtoupper(trim((string) $r)),
                $this->roles
            )));
        }

        // Fallback to DB pivot (optional)
        try {
            return $this->roles()->pluck('code')->map(fn ($c) => strtoupper((string) $c))->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function hasRole(string $code): bool
    {
        $code = strtoupper(trim($code));
        if ($code === '') return false;

        // Prefer in-memory role codes (from JWT middleware)
        $codes = $this->roleCodes();
        if (in_array($code, $codes, true)) return true;

        // Optional fallback (if you rely on DB pivot only)
        // This is redundant if roleCodes() already pulled from DB, but safe.
        try {
            return $this->roles()->where('code', $code)->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
