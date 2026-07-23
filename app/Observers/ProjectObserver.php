<?php

namespace App\Observers;

use App\Models\Project;
use App\Models\User;
use App\Notifications\ProjectStatusChanged;

class ProjectObserver
{
    public function updated(Project $project): void
    {
        if ($project->isDirty('status')) {
            $oldStatus = $project->getOriginal('status');
            $newStatus = $project->status;

            $users = User::all();
            foreach ($users as $user) {
                $user->notify(new ProjectStatusChanged($project, $oldStatus, $newStatus));
            }
        }
    }
}
