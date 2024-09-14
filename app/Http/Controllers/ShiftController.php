<?php

namespace App\Http\Controllers;

use App\Models\MachineShift;
use App\Models\Shift;
use Illuminate\Http\Request;
use App\Traits\API;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ShiftController extends Controller
{
    use API;

    public function index()
    {
        $shifts = Shift::all();
        return $this->success($shifts);
    }
}
