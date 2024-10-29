<?php

namespace App\Http\Controllers;

use App\Models\PowerConsume;
use App\Models\Spec;
use Illuminate\Http\Request;
use App\Traits\API;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SpecController extends Controller
{
    use API;
    private const PRODUCTION_JOURNEY_SLUG = 'hanh-trinh-san-xuat';
    private const PRODUCTION_JOURNEY_NAME = 'Hành trình sản xuất';

    public function createProductionJourney(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'line_ids' => 'required|array',
            'line_ids.*.line_id' => 'required',
            'line_ids.*.value' => 'required',
        ]);
        
        DB::beginTransaction();
        try {
            foreach ($request->line_ids as $row) {
                $row = (object) $row;
                Spec::create([
                    'product_id' => $request->product_id,
                    'line_id' => $row->line_id,
                    'slug' => self::PRODUCTION_JOURNEY_SLUG,
                    'value' => $row->value,
                    'name' => self::PRODUCTION_JOURNEY_NAME,
                ]);
            }
            DB::commit();
            return $this->success([], 'Thao tác thành công!');
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            DB::rollBack();
            return $this->failure([], $e->getMessage(), 500);
        }
    }

    public function updateProductionJourney(Request $request)
    {
        $request->validate([
            'product_id' => 'required',
            'line_ids' => 'required|array',
            'line_ids.*.line_id' => 'required',
            'line_ids.*.value' => 'required',
        ]);
        
        DB::beginTransaction();
        try {
            Spec::query()->where('product_id', $request->product_id)->where('slug', self::PRODUCTION_JOURNEY_SLUG)->delete();
            foreach ($request->line_ids as $row) {
                $row = (object) $row;
                Spec::create([
                    'product_id' => $request->product_id,
                    'line_id' => $row->line_id,
                    'slug' => self::PRODUCTION_JOURNEY_SLUG,
                    'value' => $row->value,
                    'name' => self::PRODUCTION_JOURNEY_NAME,
                ]);
            }
            DB::commit();
            return $this->success([], 'Thao tác thành công!');
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            DB::rollBack();
            return $this->failure([], $e->getMessage(), 500);
        }
    }

    public function deleteProductionJourney($product_id)
    {
        DB::beginTransaction();
        try {
            Spec::query()->where('product_id', $product_id)->where('slug', self::PRODUCTION_JOURNEY_SLUG)->delete();
            DB::commit();
            return $this->success([], 'Thao tác thành công!');
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            DB::rollBack();
            return $this->failure([], $e->getMessage(), 500);
        }
    }
}
