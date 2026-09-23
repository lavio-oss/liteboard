<?php

namespace Modules\Admin\Enums\Project;

enum ProjectRole: string
{
    case Admin = 'admin';
    case Engineer = 'engineer';
    case Operator = 'operator';
    case Viewer = 'viewer';
}
