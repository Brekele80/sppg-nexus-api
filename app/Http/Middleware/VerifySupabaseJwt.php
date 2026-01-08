<?php

namespace App\Http\Middleware;

use App\Models\Profile;
use Closure;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

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

            if (($decoded->iss ?? null) !== config('services.supabase.issuer')) {
                return response()->json(['error'=>['code'=>'auth_invalid_issuer','message'=>'Invalid issuer']],401);
            }

            if (($decoded->aud ?? null) !== config('services.supabase.audience')) {
                return response()->json(['error'=>['code'=>'auth_invalid_audience','message'=>'Invalid audience']],401);
            }

            $profile = Profile::firstOrCreate(
                ['id' => $decoded->sub],
                ['email'=>$decoded->email ?? '', 'is_active'=>true]
            );

            if (!$profile->is_active) {
                return response()->json(['error'=>['code'=>'user_disabled','message'=>'User disabled']],403);
            }

            $request->attributes->set('auth_user', $profile);
            return $next($request);

        } catch (\Throwable $e) {
            return response()->json([
                'error' => ['code'=>'auth_invalid_token','message'=>'Invalid token']
            ], 401);
        }
    }

    private function decodeJwt(string $jwt): object
    {
        [$kid] = $this->extractHeader($jwt);
        $jwks = $this->getJwksCached();

        if (!$this->jwksHasKid($jwks, $kid)) {
            Cache::forget('supabase_jwks');
            $jwks = $this->getJwksCached(true);
            if (!$this->jwksHasKid($jwks, $kid)) throw new \Exception("Unknown KID");
        }

        JWT::$leeway = $this->leeway;
        return JWT::decode($jwt, JWK::parseKeySet($jwks));
    }

    private function extractHeader(string $jwt): array
    {
        $header = json_decode(JWT::urlsafeB64Decode(explode('.', $jwt)[0]));
        return [$header->kid ?? null];
    }

    private function getJwksCached(bool $force = false): array
    {
        return Cache::remember('supabase_jwks', $this->jwksTtl, fn() => $this->fetchJwks());
    }

    private function fetchJwks(): array
    {
        return Http::get(config('services.supabase.jwks_url'))->json();
    }

    private function jwksHasKid(array $jwks, string $kid): bool
    {
        foreach ($jwks['keys'] as $k) if ($k['kid'] === $kid) return true;
        return false;
    }
}