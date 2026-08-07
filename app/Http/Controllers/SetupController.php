<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class SetupController extends Controller
{
    public function seed(): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Database ready.']);
    }
}
