<?php
namespace App\Http\Controllers;
use App\Models\AuditLog; use App\Models\WorkflowDefinition; use Illuminate\Http\RedirectResponse; use Illuminate\Http\Request; use Illuminate\View\View;
class WorkflowDefinitionController extends Controller {
 public function index(): View { return view('settings.workflows',['definitions'=>WorkflowDefinition::orderBy('name_th')->get()]); }
 public function update(Request $request, WorkflowDefinition $workflowDefinition): RedirectResponse {
  $data=$request->validate(['mode'=>['required','in:fast,approval'],'approval_permission'=>['nullable','string','max:80'],'is_active'=>['required','boolean'],'steps'=>['required','string','max:2000']]);
  $steps=array_values(array_filter(array_map('trim',preg_split('/\r\n|\r|\n/',$data['steps']))));
  abort_if($data['mode']==='approval' && count($steps)<2,422,'Approval Workflow ต้องมีอย่างน้อย 2 ขั้นตอน');
  $old=$workflowDefinition->only(['mode','approval_permission','is_active','steps']);
  $workflowDefinition->update(['mode'=>$data['mode'],'approval_permission'=>$data['approval_permission'] ?: null,'is_active'=>(bool)$data['is_active'],'steps'=>$steps,'updated_by'=>$request->user()->id]);
  AuditLog::create(['user_id'=>$request->user()->id,'branch_id'=>$request->user()->branch_id,'action'=>'workflow.updated','table_name'=>'workflow_definitions','record_id'=>$workflowDefinition->id,'old_values'=>$old,'new_values'=>$workflowDefinition->only(['mode','approval_permission','is_active','steps'])]);
  return back()->with('success','บันทึก Workflow ของ '.$workflowDefinition->name_th.' แล้ว');
 }
}
