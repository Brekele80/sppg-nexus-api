<?php

namespace App\Http\Middleware;

use App\Models\Profile;
use Closure;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class VerifySupabaseJwt
{
    private int $jwksTtl = 3600;
    private int $leeway  = 60;

    public function handle(Request $request, Closure $next)
    {
        $auth = $request->header('Authorization');

        if (!$auth || !preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return response()->json([
                'error' => ['code' => 'auth_missing_token', 'message' => 'Missing bearer token']
            ], 401);
        }

        try {
            $decoded = $this->decodeJwt($m[1]);

            // ---- issuer
            $issuer = config('services.supabase.issuer');
            if ($issuer && (($decoded->iss ?? null) !== $issuer)) {
                return response()->json([
                    'error' => ['code' => 'auth_invalid_issuer', 'message' => 'Invalid issuer']
                ], 401);
            }

            // ---- audience
            $expectedAud = config('services.supabase.audience');
            if ($expectedAud) {
                $aud = $decoded->aud ?? null;

                $audOk = false;
                if (is_string($aud)) {
                    $audOk = ($aud === $expectedAud);
                } elseif (is_array($aud)) {
                    $audOk = in_array($expectedAud, $aud, true);
                }

                if (!$audOk) {
                    return response()->json([
                        'error' => ['code' => 'auth_invalid_audience', 'message' => 'Invalid audience']
                    ], 401);
                }
            }

            $sub = $decoded->sub ?? null;
            if (!$sub || !is_string($sub)) {
                return response()->json([
                    'error' => ['code' => 'auth_invalid_token', 'message' => 'Missing sub']
                ], 401);
            }

            // Token fields
            $email = isset($decoded->email) && is_string($decoded->email) ? $decoded->email : '';
            $fullName = '';

            if (isset($decoded->user_metadata) && is_object($decoded->user_metadata)) {
                $um = $decoded->user_metadata;
                if (isset($um->full_name) && is_string($um->full_name)) {
                    $fullName = $um->full_name;
                } elseif (isset($um->name) && is_string($um->name)) {
                    $fullName = $um->name;
                }
            }

            // Extract roles from JWT (critical!)
            $jwtRoles = $this->extractRoles($decoded);

            // Determine company_id from token if later used
            $companyIdFromToken = $this->extractCompanyId($decoded);

            $profile = DB::transaction(function () use ($sub, $email, $fullName, $companyIdFromToken) {

                /** @var Profile $profile */
                $profile = Profile::lockForUpdate()->firstOrCreate(
                    ['id' => $sub],
                    [
                        'email' => $email,
                        'full_name' => $fullName ?: null,
                        'is_active' => true,
                    ]
                );

                if (!$profile->is_active) {
                    return $profile;
                }

                $dirty = false;

                if ($email && $profile->email !== $email) {
                    $profile->email = $email;
                    $dirty = true;
                }
                if ($fullName && $profile->full_name !== $fullName) {
                    $profile->full_name = $fullName;
                    $dirty = true;
                }

                // Ensure company_id exists (critical for requireCompany)
                if (empty($profile->company_id)) {
                    $resolvedCompanyId = null;

                    if ($companyIdFromToken) {
                        $resolvedCompanyId = $companyIdFromToken;
                    }

                    if (!$resolvedCompanyId && !empty($profile->branch_id)) {
                        $branchCompanyId = DB::table('branches')->where('id', $profile->branch_id)->value('company_id');
                        if ($branchCompanyId) $resolvedCompanyId = $branchCompanyId;
                    }

                    if (!$resolvedCompanyId) {
                        $resolvedCompanyId = DB::table('companies')->where('code', 'DEFAULT')->value('id')
                            ?: DB::table('companies')->orderBy('created_at')->value('id');
                    }

                    if (!$resolvedCompanyId) {
                        throw new \RuntimeException('No company exists to assign to profile.');
                    }

                    $profile->company_id = $resolvedCompanyId;
                    $dirty = true;
                }

                // If branch_id exists, enforce branch.company_id == profile.company_id
                if (!empty($profile->branch_id)) {
                    $branchCompanyId = DB::table('branches')->where('id', $profile->branch_id)->value('company_id');
                    if ($branchCompanyId && $branchCompanyId !== $profile->company_id) {
                        throw new \RuntimeException('Branch company mismatch for profile.');
                    }

                    // Ensure profile_branch_access mapping exists
                    $exists = DB::table('profile_branch_access')
                        ->where('company_id', $profile->company_id)
                        ->where('profile_id', $profile->id)
                        ->where('branch_id', $profile->branch_id)
                        ->exists();

                    if (!$exists) {
                        DB::table('profile_branch_access')->insert([
                            'id' => (string) Str::uuid(),
                            'company_id' => $profile->company_id,
                            'profile_id' => $profile->id,
                            'branch_id' => $profile->branch_id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                if ($dirty) $profile->save();

                return $profile;
            });

            if (!$profile->is_active) {
                return response()->json([
                    'error' => ['code' => 'user_disabled', 'message' => 'User disabled']
                ], 403);
            }

            // Attach to request
            $request->attributes->set('auth_user', $profile);

            // IMPORTANT: attach roles to Profile so RequireRole can see them
            $profile->setAttribute('roles', $jwtRoles);

            // Optional also store on request
            $request->attributes->set('auth_roles', $jwtRoles);

            return $next($request);

        } catch (\Throwable $e) {
            return response()->json([
                'error' => ['code' => 'auth_invalid_token', 'message' => 'Invalid token']
            ], 401);
        }
    }

    private function extractRoles(object $decoded): array
    {
        // Supabase puts custom roles commonly in: app_metadata.roles (array)
        $roles = [];

        if (isset($decoded->app_metadata) && is_object($decoded->app_metadata)) {
            $am = $decoded->app_metadata;

            if (isset($am->roles) && is_array($am->roles)) {
                $roles = $am->roles;
            } elseif (isset($am->role) && is_string($am->role)) {
                $roles = [$am->role];
            }
        }

        // Also allow top-level roles claim if you ever add it
        if (empty($roles) && isset($decoded->roles) && is_array($decoded->roles)) {
            $roles = $decoded->roles;
        }

        // Normalize: trim, uppercase, unique
        $roles = array_map(fn($r) => strtoupper(trim((string)$r)), $roles);
        $roles = array_values(array_unique(array_filter($roles, fn($r) => $r !== '')));

        return $roles;
    }

    private function extractCompanyId(object $decoded): ?string
    {
        if (isset($decoded->company_id) && is_string($decoded->company_id) && Str::isUuid($decoded->company_id)) {
            return $decoded->company_id;
        }

        if (isset($decoded->app_metadata) && is_object($decoded->app_metadata)) {
            $am = $decoded->app_metadata;
            if (isset($am->company_id) && is_string($am->company_id) && Str::isUuid($am->company_id)) {
                return $am->company_id;
            }
        }

        if (isset($decoded->user_metadata) && is_object($decoded->user_metadata)) {
            $um = $decoded->user_metadata;
            if (isset($um->company_id) && is_string($um->company_id) && Str::isUuid($um->company_id)) {
                return $um->company_id;
            }
        }

        return null;
    }

    private function decodeJwt(string $jwt): object
    {
        $kid = $this->extractKid($jwt);
        if (!$kid) throw new \RuntimeException('Missing kid');

        $jwks = $this->getJwksCached(false);

        if (!$this->jwksHasKid($jwks, $kid)) {
            Cache::forget('supabase_jwks');
            $jwks = $this->getJwksCached(true);

            if (!$this->jwksHasKid($jwks, $kid)) {
                throw new \RuntimeException('Unknown KID');
            }
        }

        JWT::$leeway = $this->leeway;

        return JWT::decode($jwt, JWK::parseKeySet($jwks));
    }

    private function extractKid(string $jwt): ?string
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) return null;

        $headerJson = JWT::urlsafeB64Decode($parts[0]);
        $header = json_decode($headerJson);

        if (!is_object($header)) return null;

        $kid = $header->kid ?? null;
        return is_string($kid) && $kid !== '' ? $kid : null;
    }

    private function getJwksCached(bool $force = false): array
    {
        if ($force) {
            $jwks = $this->fetchJwks();
            Cache::put('supabase_jwks', $jwks, $this->jwksTtl);
            return $jwks;
        }

        return Cache::remember('supabase_jwks', $this->jwksTtl, function () {
            return $this->fetchJwks();
        });
    }

    private function fetchJwks(): array
    {
        $url = config('services.supabase.jwks_url');
        if (!$url) throw new \RuntimeException('Missing services.supabase.jwks_url');

        $json = Http::timeout(10)->get($url)->json();

        if (!is_array($json) || !isset($json['keys']) || !is_array($json['keys'])) {
            throw new \RuntimeException('Invalid JWKS response');
        }

        return $json;
    }

    private function jwksHasKid(array $jwks, string $kid): bool
    {
        if (!isset($jwks['keys']) || !is_array($jwks['keys'])) return false;

        foreach ($jwks['keys'] as $k) {
            if (is_array($k) && isset($k['kid']) && $k['kid'] === $kid) return true;
        }

        return false;
    }
}
