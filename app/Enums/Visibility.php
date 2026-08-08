<?php

namespace App\Enums;

enum Visibility: string
{
    case Private = 'private';
    case Workspace = 'workspace';
    case SharedLink = 'shared_link';
    case Public = 'public';
}
