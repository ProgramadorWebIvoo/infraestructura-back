<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class ModuleController extends Controller
{
    public function index()
    {
        return DB::table('app_modules')->orderBy('id')->get();
    }
}
