<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;

class MeController extends BaseApiController
{
    public function show(Request $request)
    {
        $u = $this->authUser($request);
        return response()->json([
            'id' => $u->id,
            'email' => $u->email,
            'full_name' => $u->full_name,
            'branch_id' => $u->branch_id,
            'roles' => $u->roleCodes(),
        ]);
    }
}
