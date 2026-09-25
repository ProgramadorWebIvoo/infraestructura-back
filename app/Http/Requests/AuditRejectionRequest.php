<?php

namespace App\Http\Requests;

use App\Models\ProjectClosureReport;
use Illuminate\Validation\Rule;

class AuditRejectionRequest extends ClosureReasonRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'target' => ['required', Rule::in([ProjectClosureReport::TARGET_CONTRACTOR, ProjectClosureReport::TARGET_RESIDENT])],
        ];
    }
}
