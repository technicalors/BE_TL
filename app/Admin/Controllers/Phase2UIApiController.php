<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Factory;
use App\Models\InfoCongDoan;
use App\Models\Line;
use App\Models\Lot;
use App\Models\LSXLog;
use App\Models\Machine;
use App\Models\Material;
use App\Models\ProductionPlan;
use App\Models\QCHistory;
use App\Models\Spec;
use App\Models\TestCriteria;
use App\Models\Tracking;
use App\Models\User;
use App\Models\Workers;
use App\Traits\API;
use Carbon\Carbon;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class Phase2UIApiController extends Controller
{
    use API;
    public function getTreeSelect(Request $request)
    {
        $factories = Factory::with('line.machine')
        // ->select('factories.*', 'id as key', 'name as title', DB::raw("'factory' as type"))
        ->where('id', 2)
        ->get();
        foreach ($factories as $factory) {
            foreach ($factory->line as $line) {
                foreach ($line->machine as $machine) {
                    $machine->key = $machine->id;
                    $machine->title = $machine->name;
                    $machine->type = 'machine';
                }
                $line->key = $line->id;
                $line->title = $line->name;
                $line->children = $line->machine;
                $line->type = 'line';
            }
            $factory['key'] = $factory->id;
            $factory['title'] = $factory->name;
            $factory['children'] = $factory->line;
            $factory['type'] = 'factory';

        }
        return $this->success($factories);
    }
}
