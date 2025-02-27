<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Losx;
use App\Traits\API;

use Illuminate\Http\Request;


class LosxController extends Controller
{
    use API;
    public function getPriorities(Request $request)
    {
        $query = Losx::with('productionOrderHistory.line')->where('status', 1)->orderBy('priority', 'ASC');
        $total = $query->count();
        if (isset($request->page) && isset($request->pageSize)) {
            $query->offset(($request->page - 1) * $request->pageSize)->limit($request->pageSize);
        }
        $result = $query->with('product')->get();
        return $this->success(['data' => $result, 'total' => $total]);
    }
}
