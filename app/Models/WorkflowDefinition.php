<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
#[Fillable(['document_type_code','name_th','mode','approval_permission','approver_positions','is_active','steps','updated_by'])]
class WorkflowDefinition extends Model { protected function casts(): array { return ['steps'=>'array','approver_positions'=>'array','is_active'=>'boolean']; } }
