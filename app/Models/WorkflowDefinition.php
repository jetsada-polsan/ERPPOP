<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
#[Fillable(['document_type_code','name_th','mode','approval_permission','is_active','steps','updated_by'])]
class WorkflowDefinition extends Model { protected function casts(): array { return ['steps'=>'array','is_active'=>'boolean']; } }
