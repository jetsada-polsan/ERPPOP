<?php
require base_path('tools/staging/seed_uat_data.php');
if (! in_array(app()->environment(), ['local', 'testing', 'staging'], true)) { echo "หยุด: รันได้เฉพาะ local/staging\n"; return; }
$branchId = DB::table('branches')->where('code','UAT')->value('id');
$user = App\Models\User::create(['name'=>'แคชเชียร์ทดสอบ','username'=>'uatcashier','email'=>'uat@example.test','password'=>Hash::make('Uat-Pass-2026!'),'branch_id'=>$branchId,'is_active'=>true,'must_change_password'=>false]);
$role = App\Models\Role::create(['code'=>'UAT_CASHIER','name'=>'UAT cashier']);
foreach (['pos.use','pos.sell','pos.void','reports.view'] as $c) $role->permissions()->attach(App\Models\Permission::firstOrCreate(['code'=>$c],['name'=>$c])->id);
$user->roles()->attach($role->id);
$cashier = App\Models\Salesman::create(['branch_id'=>$branchId,'code'=>'UAT-C1','name'=>'แคชเชียร์ทดสอบ','is_active'=>true,'user_id'=>$user->id]);
$t = App\Models\PosTerminal::create(['branch_id'=>$branchId,'code'=>'UAT-T1','name'=>'Web POS UAT']);
App\Models\PosShift::create(['branch_id'=>$branchId,'pos_terminal_id'=>$t->id,'cashier_id'=>$cashier->id,'shift_no'=>'UAT-SHIFT-1','opened_at'=>now(),'opening_cash'=>0,'status'=>'open']);
$p = DB::table('products')->where('sku_code', 'UAT-SKU-001')->first();
DB::table('product_barcodes')->insert(['product_id' => $p->id, 'unit_id' => $p->base_unit_id, 'barcode' => '8850000000013', 'barcode_type' => 'CUSTOM', 'is_active' => true, 'unit_factor' => 1]);
echo "SETUP OK branch=$branchId\n";
