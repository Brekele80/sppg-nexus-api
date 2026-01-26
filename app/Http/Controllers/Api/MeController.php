<?php

namespace App\Http\Controllers\Api;

use App\Support\AuthUser;
use Illuminate\Http\Request;

class MeController extends BaseApiController
{
    public function show(Request $request)
    {
        $u = $this->authUser($request);

        $roles = AuthUser::roleCodes($u);
        if (!is_array($roles)) $roles = [];
        $roles = array_values($roles);

        return response()->json([
            'id'         => $u->id,
            'email'      => $u->email,
            'full_name'  => $u->full_name,
            'company_id' => $u->company_id,
            'branch_id'  => $u->branch_id,
            'roles'      => $roles,
        ]);
    }
}
